<?php
/**
 * FrankenPHP worker entrypoint for Magento /graphql (PoC).
 *
 * Boots Magento once per worker thread, then serves requests in a loop with
 * request state reset between them. Point a FrankenPHP `worker` block at
 * this file (see frankenphp/Caddyfile) with MAGENTO_ROOT set.
 *
 * Exits cleanly (code 0) when a recycle limit is hit or a request fails in a
 * way that may have left state behind; FrankenPHP starts a fresh worker.
 */

declare(strict_types=1);

use Magenx\AppServer\ObjectManager\ObjectManagerFactory;
use Magenx\AppServer\Worker\GraphQlWorker;
use Magenx\AppServer\Worker\Limits;
use Magenx\AppServer\Worker\ReadOnlyScope;
use Magento\Framework\App\Bootstrap;

if (!function_exists('frankenphp_handle_request')) {
    fwrite(STDERR, "graphql-worker.php must run as a FrankenPHP worker.\n");
    exit(1);
}

// A client disconnect must not abort a request half-way through its state.
ignore_user_abort(true);

$magentoRoot = rtrim((string) ($_SERVER['MAGENTO_ROOT'] ?? getenv('MAGENTO_ROOT') ?: ''), '/');
if ($magentoRoot === '' || !is_file($magentoRoot . '/app/bootstrap.php')) {
    error_log('magenx-app-server: MAGENTO_ROOT must point at the Magento root (app/bootstrap.php not found)');
    exit(1);
}
require $magentoRoot . '/app/bootstrap.php';

// Until the first frankenphp_handle_request() call, $_SERVER is the
// worker's own environment (MAGE_MODE, MAGE_RUN_CODE, Caddyfile `env`).
$initParams = $_SERVER;
$objectManagerFactory = new ObjectManagerFactory(
    Bootstrap::createFilesystemDirectoryList(BP, $initParams),
    Bootstrap::createFilesystemDriverPool($initParams),
    Bootstrap::createConfigFilePool()
);
$bootstrap = Bootstrap::create(BP, $initParams, $objectManagerFactory);

$worker = new GraphQlWorker(
    $bootstrap->getObjectManager(),
    $objectManagerFactory->getResetter(),
    Limits::fromEnvironment(),
    ReadOnlyScope::fromEnvironment(),
    BP . '/app/etc/env.php'
);
$worker->boot();

$handler = static function () use ($worker): void {
    $worker->handle();
};

do {
    // false: FrankenPHP is shutting down or restarting workers.
    $keepRunning = frankenphp_handle_request($handler);
    // The response is complete once frankenphp_handle_request() returns, so
    // the state reset runs here, off the client's latency.
    $keepWorker = $worker->afterRequest();
} while ($keepRunning && $keepWorker);

exit(0);
