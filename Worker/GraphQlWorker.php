<?php

declare(strict_types=1);

namespace Magenx\AppServer\Worker;

use Laminas\Stdlib\Parameters;
use Magento\Framework\App\Area;
use Magento\Framework\App\Cache\StateInterface as CacheState;
use Magento\Framework\App\Cache\Type\Config as ConfigCache;
use Magento\Framework\App\ErrorHandler;
use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\MaintenanceMode;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\State;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\GraphQl\Query\QueryParser;
use Magento\Framework\ObjectManager\ConfigLoaderInterface;
use Magento\Framework\ObjectManager\Resetter\ResetterInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\GraphQl\Model\Config\DisableSession;
use Psr\Log\LoggerInterface;

/**
 * Serves /graphql requests from one long-lived, already bootstrapped app.
 *
 * Does once in boot() what Bootstrap::run() + App\Http::launch() redo on
 * every PHP-FPM request (error handler, area code, area DI config, front
 * controller), then per request: hydrate the shared request from the
 * superglobals FrankenPHP filled in, dispatch, send, reset.
 *
 * App\Http::launch() is not reused: State::setAreaCode() throws on a second
 * call and is not reset, and re-loading the area DI config every request is
 * the cost this server exists to remove. Plugins on App\Http itself (core:
 * only NewRelicReporting) therefore do not run.
 */
class GraphQlWorker
{
    /**
     * Last entry core System config type writes when it (re)builds the config
     * cache, encrypted with a fresh nonce each time: its value changes on every
     * admin config save, cache clean and cache flush.
     */
    private const CONFIG_MARKER_ID = 'system_scopes';

    /** Parsed queries kept by the core QueryParser before it is emptied. */
    private const MAX_PARSED_QUERIES = 1000;

    private FrontControllerInterface $frontController;
    private HttpRequest $request;
    private EventManager $eventManager;
    private MaintenanceMode $maintenanceMode;
    private ResourceConnection $resourceConnection;
    private LoggerInterface $logger;
    private QueryParser $queryParser;
    private ?ConfigCache $configCache = null;
    private \Closure $clearUnresetRequestState;
    private \Closure $countParsedQueries;

    private bool $trustRealIp = false;
    private bool $healthy = true;
    private int $handled = 0;
    private int $bootedAt = 0;
    private int $lastRequestAt = 0;
    /** Tells worker threads apart: getmypid() is the same for all of them. */
    private string $id = '';
    private int|false $envMtime = false;
    private ?string $configMarker = null;
    private float $configCheckedAt = 0.0;
    private bool $sessionWarned = false;

    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly ResetterInterface $resetter,
        private readonly Limits $limits,
        private readonly Scope $scope,
        private readonly string $envFile,
        private readonly string $warmupQuery = '',
    ) {
    }

    /**
     * One-time per-worker setup. Must run before the first request.
     *
     * @param float|null $startedAt microtime(true) at the start of the worker
     *                              script, so the boot log covers bootstrap too.
     */
    public function boot(?float $startedAt = null): void
    {
        set_error_handler([new ErrorHandler(), 'handler']);

        $om = $this->objectManager;
        $om->get(State::class)->setAreaCode(Area::AREA_GRAPHQL);
        $om->configure($om->get(ConfigLoaderInterface::class)->load(Area::AREA_GRAPHQL));

        $this->frontController = $om->get(FrontControllerInterface::class);
        $this->request = $om->get(HttpRequest::class);
        $this->eventManager = $om->get(EventManager::class);
        $this->maintenanceMode = $om->get(MaintenanceMode::class);
        $this->resourceConnection = $om->get(ResourceConnection::class);
        $this->logger = $om->get(LoggerInterface::class);
        $this->queryParser = $om->get(QueryParser::class);

        // The core parser memoizes every distinct query and is never reset;
        // read its size so afterRequest() can bound it.
        $this->countParsedQueries = \Closure::bind(
            fn (): int => count($this->parsedQueries),
            $this->queryParser,
            QueryParser::class
        );

        // Request\Http::_resetState() (2.4.8) misses these; stale values
        // would leak the previous request's method/route into this one.
        $this->clearUnresetRequestState = \Closure::bind(
            function (): void {
                $this->originalPathInfo = '';
                $this->isSafeMethod = null;
                $this->route = null;
                $this->controllerModule = null;
                $this->beforeForwardInfo = [];
            },
            $this->request,
            HttpRequest::class
        );

        // Behind nginx the TCP peer is nginx itself. With this on, REMOTE_ADDR
        // is taken from X-Real-IP (set by nginx after real_ip), so it matches
        // what php-fpm sees for the maintenance allowlist and logging. Enable
        // only when nginx is the sole client of the worker port.
        $this->trustRealIp = filter_var(
            $_SERVER['MAGENX_WORKER_TRUST_X_REAL_IP'] ?? getenv('MAGENX_WORKER_TRUST_X_REAL_IP'),
            FILTER_VALIDATE_BOOL
        );

        $this->id = bin2hex(random_bytes(4));
        $this->bootedAt = time();
        $this->lastRequestAt = $this->bootedAt;
        $this->envMtime = $this->envMtime();

        if ($om->get(CacheState::class)->isEnabled(ConfigCache::TYPE_IDENTIFIER)) {
            $this->configCache = $om->get(ConfigCache::class);
        } else {
            $this->logger->warning(
                'magenx-app-server: config cache disabled; admin config changes reach workers only on recycle'
            );
        }
        if (class_exists(DisableSession::class) && !$om->get(DisableSession::class)->isDisabled()) {
            $this->logger->warning(
                'magenx-app-server: GraphQL sessions are enabled; run '
                . '`bin/magento config:set graphql/session/disable 1` (the worker drops cookies and never keeps a session)'
            );
        }

        $this->warmUp();

        // Start request #1 from the same baseline as every later request.
        $this->resetter->_resetState();
        $this->configMarker = $this->loadConfigMarker();

        $this->logger->notice(sprintf(
            'magenx-app-server: worker %s booted in %s mode in %d ms, memory %s, %s',
            $this->workerName(),
            $this->scope->mode,
            (int) round((microtime(true) - ($startedAt ?? microtime(true))) * 1000),
            $this->formatMemory(),
            $this->formatOpcache()
        ));
    }

    /**
     * Answer the current request. Runs inside frankenphp_handle_request().
     *
     * Only the work the client waits for happens here: FrankenPHP holds the
     * whole response back until this returns. Cleanup is in afterRequest().
     */
    public function handle(): void
    {
        $this->healthy = true;
        try {
            $this->hydrateRequest();
            if (!$this->scope->allows($this->request, $this->queryParser)) {
                // Not ours: refuse before Magento runs, so nginx replays it on PHP-FPM.
                $this->sendError(503, 'Out of worker scope', ['X-Magenx-Worker: out-of-scope']);
            } elseif ($this->maintenanceMode->isOn((string) ($_SERVER['REMOTE_ADDR'] ?? ''))) {
                // Magento did not run either: nginx may replay it, PHP-FPM answers for maintenance.
                $this->sendError(503, 'Service temporarily unavailable', ['X-Magenx-Worker: maintenance']);
            } else {
                $this->dispatch();
            }
        } catch (\Throwable $e) {
            // Unknown state after an escaped throwable: answer, then recycle.
            $this->healthy = false;
            $this->logCritical($e);
            $this->sendError(500, 'Internal server error');
        }
    }

    /**
     * Clean up after the response has been sent. Returns false when the worker must exit.
     *
     * Called once frankenphp_handle_request() has returned, i.e. after the
     * client got its response: the reset (and the Resetter's two
     * gc_collect_cycles() calls) no longer adds to request latency. The next
     * request on this thread starts only after it, so state is still clean.
     */
    public function afterRequest(): bool
    {
        $reasons = [];
        if (!$this->healthy) {
            $reasons[] = 'error';
        }
        if (!$this->rollBackOpenTransactions()) {
            $reasons[] = 'open-transaction';
        }
        $this->closeSession();
        try {
            $this->resetter->_resetState();
            if (($this->countParsedQueries)() > self::MAX_PARSED_QUERIES) {
                $this->queryParser->reloadState();
            }
        } catch (\Throwable $e) {
            $reasons[] = 'reset-failed';
            $this->logCritical($e);
        }

        ++$this->handled;
        $this->lastRequestAt = time();
        $reasons = [...$reasons, ...$this->recycleReasons()];
        if ($reasons === []) {
            return true;
        }

        $this->logExit('recycling (' . implode(', ', $reasons) . ')');
        return false;
    }

    /**
     * Log that FrankenPHP stopped this worker without a request to handle.
     *
     * FrankenPHP does not say why. The idle time tells the cases apart: an
     * autoscaled thread (GRAPHQL_MAX_THREADS > GRAPHQL_WORKERS) is
     * downscaled once idle longer than max_idle_time; shutdown, config
     * reload and admin API workers/restart stop busy and idle threads alike.
     */
    public function stopped(): void
    {
        $this->logExit(sprintf('stopped by FrankenPHP (idle %d s)', time() - $this->lastRequestAt));
    }

    /**
     * Run one query through the front controller before the first request, so
     * request #1 does not pay for autoloading, config and schema loading.
     * The response is discarded; boot() resets all state afterwards.
     */
    private function warmUp(): void
    {
        if ($this->warmupQuery === '') {
            return;
        }
        $saved = [$_SERVER, $_GET, $_POST, $_COOKIE];
        $_GET = ['query' => $this->warmupQuery];
        $_POST = [];
        $_COOKIE = [];
        $_SERVER = [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/graphql?query=' . rawurlencode($this->warmupQuery),
            'QUERY_STRING' => 'query=' . rawurlencode($this->warmupQuery),
            'HTTP_HOST' => 'localhost',
            'REMOTE_ADDR' => '127.0.0.1',
        ] + $_SERVER;
        try {
            $this->hydrateRequest();
            $this->frontController->dispatch($this->request);
        } catch (\Throwable $e) {
            $this->logger->warning('magenx-app-server: warm-up query failed: ' . $e->getMessage());
        } finally {
            [$_SERVER, $_GET, $_POST, $_COOKIE] = $saved;
            $this->rollBackOpenTransactions();
            $this->closeSession();
        }
    }

    private function dispatch(): void
    {
        $response = $this->frontController->dispatch($this->request);
        if (!$response instanceof HttpResponse) {
            throw new \UnexpectedValueException(
                'GraphQL front controller returned ' . get_debug_type($response)
            );
        }
        // Same event App\Http::launch() fires before output (cookie/header hooks).
        $this->eventManager->dispatch(
            'controller_front_send_response_before',
            ['request' => $this->request, 'response' => $response]
        );
        $response->sendResponse();
    }

    /**
     * Re-point the shared request (held by many singletons) at this request.
     *
     * Mutated in place, never replaced: replacing it would leave every
     * object that captured it at construction reading the old instance.
     */
    private function hydrateRequest(): void
    {
        $this->request->_resetState();
        ($this->clearUnresetRequestState)();

        $realIp = $_SERVER['HTTP_X_REAL_IP'] ?? null;
        if ($this->trustRealIp && is_string($realIp) && filter_var($realIp, FILTER_VALIDATE_IP)) {
            $_SERVER['REMOTE_ADDR'] = $realIp;
        }

        // GraphQL clients authenticate with tokens, not cookies, and a session
        // cookie would let Magento resume a PHP session inside this long-lived
        // thread. Core PhpCookieReader reads $_COOKIE directly, so empty it as well.
        $_COOKIE = [];
        unset($_SERVER['HTTP_COOKIE']);

        // Mirrors Laminas PhpEnvironment\Request::__construct(); setServer()
        // derives headers, method and URI. The body is read lazily from
        // php://input by getContent(), which FrankenPHP serves per request.
        // Request::_resetState() keeps cookie params, so always replace them.
        $this->request->setQuery(new Parameters($_GET));
        $this->request->setPost(new Parameters($_POST));
        $this->request->setCookies(new Parameters([]));
        $this->request->setServer(new Parameters($_SERVER));
    }

    /**
     * A transaction left open by a failed request would otherwise carry
     * into the next one on the same connection.
     */
    private function rollBackOpenTransactions(): bool
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            if ($connection->getTransactionLevel() === 0) {
                return true;
            }
            while ($connection->getTransactionLevel() > 0) {
                $connection->rollBack();
            }
            $this->logger->critical('magenx-app-server: request left a DB transaction open; rolled back');
        } catch (\Throwable $e) {
            $this->logCritical($e);
        }
        return false;
    }

    /**
     * A session must never outlive its request in a shared thread. Changes are
     * discarded: GraphQL clients authenticate with tokens, and cookies never
     * reach the worker, so no request can resume the session later anyway.
     */
    private function closeSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        session_abort();
        if (!$this->sessionWarned) {
            $this->sessionWarned = true;
            $this->logger->warning(
                'magenx-app-server: a request started a PHP session; set graphql/session/disable=1'
            );
        }
    }

    /**
     * True once the config cache was rebuilt or flushed since this worker
     * loaded it. Checked at most once a second: one small cache read.
     */
    private function configChanged(): bool
    {
        if ($this->configCache === null) {
            return false;
        }
        $now = microtime(true);
        if ($now - $this->configCheckedAt < 1.0) {
            return false;
        }
        $this->configCheckedAt = $now;
        $current = $this->loadConfigMarker();
        if ($this->configMarker === null) {
            // Config not cached at boot: take the first value seen as baseline.
            $this->configMarker = $current;
            return false;
        }
        return $current !== $this->configMarker;
    }

    private function loadConfigMarker(): ?string
    {
        if ($this->configCache === null) {
            return null;
        }
        try {
            $value = $this->configCache->load(self::CONFIG_MARKER_ID);
        } catch (\Throwable $e) {
            $this->logCritical($e);
            return $this->configMarker;
        }
        return is_string($value) ? $value : null;
    }

    /**
     * @return string[] the recycle limits this worker has reached
     */
    private function recycleReasons(): array
    {
        $limits = $this->limits;
        $reasons = [];
        if ($limits->maxRequests > 0 && $this->handled >= $limits->maxRequests) {
            $reasons[] = 'max-requests';
        }
        if ($limits->maxMemoryBytes > 0 && memory_get_usage() >= $limits->maxMemoryBytes) {
            $reasons[] = 'max-memory';
        }
        if ($limits->maxAgeSeconds > 0 && time() - $this->bootedAt >= $limits->maxAgeSeconds) {
            $reasons[] = 'max-age';
        }
        // env.php changed (deploy, cache:enable, db creds): DeploymentConfig is bootstrapped once.
        if ($this->envMtime() !== $this->envMtime) {
            $reasons[] = 'env-changed';
        }
        // Admin config save, cache:clean config or cache:flush: this worker holds the old config.
        if ($this->configChanged()) {
            $reasons[] = 'config-changed';
        }
        return $reasons;
    }

    /**
     * One line per worker exit, so restarts in frankenphp_worker_restarts can be told apart.
     */
    private function logExit(string $why): void
    {
        $limits = $this->limits;
        try {
            $this->logger->notice(sprintf(
                'magenx-app-server: worker %s %s after %d/%d requests, %d/%d s, memory %s (limit %d MB), %s',
                $this->workerName(),
                $why,
                $this->handled,
                $limits->maxRequests,
                time() - $this->bootedAt,
                $limits->maxAgeSeconds,
                $this->formatMemory(),
                intdiv($limits->maxMemoryBytes, 1024 * 1024),
                $this->formatOpcache()
            ));
        } catch (\Throwable) {
            error_log('magenx-app-server: worker ' . $this->workerName() . ' ' . $why);
        }
    }

    private function workerName(): string
    {
        return getmypid() . '/' . $this->id;
    }

    private function formatMemory(): string
    {
        return sprintf(
            '%.1f MB (peak %.1f MB)',
            memory_get_usage() / 1048576,
            memory_get_peak_usage() / 1048576
        );
    }

    /**
     * Opcache fill, shared by every thread of the FrankenPHP process. A full
     * cache or OOM restarts mean opcache.memory_consumption or
     * max_accelerated_files is too small: every boot then recompiles.
     */
    private function formatOpcache(): string
    {
        $status = function_exists('opcache_get_status') ? opcache_get_status(false) : false;
        if (!is_array($status) || empty($status['opcache_enabled'])) {
            return 'opcache off';
        }
        $memory = $status['memory_usage'];
        $stats = $status['opcache_statistics'];
        $interned = $status['interned_strings_usage'] ?? ['used_memory' => 0, 'buffer_size' => 0];
        $total = $memory['used_memory'] + $memory['free_memory'] + $memory['wasted_memory'];
        $full = !empty($status['cache_full'])
            || $memory['free_memory'] < $total / 10
            || $stats['oom_restarts'] > 0
            || $stats['hash_restarts'] > 0;

        return sprintf(
            'opcache %d scripts (%d max), %.0f/%.0f MB, interned %.0f/%.0f MB, hit %.1f%%, '
            . 'restarts oom %d hash %d%s',
            $stats['num_cached_scripts'],
            $stats['max_cached_keys'],
            $memory['used_memory'] / 1048576,
            $total / 1048576,
            $interned['used_memory'] / 1048576,
            $interned['buffer_size'] / 1048576,
            $stats['opcache_hit_rate'],
            $stats['oom_restarts'],
            $stats['hash_restarts'],
            $full ? ' FULL: raise opcache.memory_consumption / max_accelerated_files' : ''
        );
    }

    private function envMtime(): int|false
    {
        clearstatcache(true, $this->envFile);
        return is_file($this->envFile) ? filemtime($this->envFile) : false;
    }

    /**
     * @param string[] $headers
     */
    private function sendError(int $status, string $message, array $headers = []): void
    {
        if (headers_sent()) {
            return;
        }
        http_response_code($status);
        header('Content-Type: application/json');
        foreach ($headers as $header) {
            header($header);
        }
        echo json_encode(['errors' => [['message' => $message]]]);
    }

    private function logCritical(\Throwable $e): void
    {
        try {
            $this->logger->critical($e);
        } catch (\Throwable) {
            error_log('magenx-app-server: ' . $e);
        }
    }
}
