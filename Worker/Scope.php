<?php

declare(strict_types=1);

namespace Magenx\AppServer\Worker;

use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\GraphQl\Query\QueryParser;

/**
 * Which requests the worker may serve, by worker mode (MAGENX_WORKER_MODE).
 *
 * Anything out of scope is answered with 503 + `X-Magenx-Worker: out-of-scope`
 * *before* Magento touches it; nginx then replays it on PHP-FPM (see README,
 * "Worker modes").
 *
 * In both modes, the body must be at most MAGENX_WORKER_MAX_BODY_KB (default
 * 512), checked before anything is decoded or parsed.
 *
 * `default`: every other request is in scope: customer tokens, cart,
 * checkout and mutations included.
 *
 * `restricted` (also when unset): catalog/CMS reads only. Customer, cart,
 * checkout and every mutation stay on PHP-FPM. nginx cannot see the operation
 * inside a POST body, so the worker enforces this itself. A request is in
 * scope only when all of these hold as well:
 *  - no Authorization header (no customer or integration token);
 *  - the body parses, and every operation in it is a `query`;
 *  - every root field is on the allowlist (`__typename` aside), and the root
 *    selection uses no fragments, which could hide other fields.
 */
class Scope
{
    public const MODE_DEFAULT = 'default';
    public const MODE_RESTRICTED = 'restricted';

    /**
     * Core catalog/CMS reads plus the Magenx companion modules' public reads.
     * Customer-, cart-, order- or ticket-scoped roots are deliberately absent.
     */
    public const DEFAULT_ROOT_FIELDS = [
        'products', 'categories', 'categoryList', 'storeConfig', 'availableStores',
        'cmsPage', 'cmsBlocks', 'route', 'urlResolver', 'countries', 'country', 'currency',
        'customAttributeMetadataV2', 'customAttributeMetadata', 'productReviewRatingsMetadata',
        'bestSellers', 'blogPosts', 'blogPost', 'blogTag', 'blogCategories', 'deals',
        'garanNotice', 'gdprCookieGroups', 'quickSearchSuggestions', 'storePickupLocations',
    ];

    /** @var array<string, true> */
    private readonly array $allowed;

    /**
     * @param string[] $allowedRootFields used in restricted mode only
     * @param int $maxBodyBytes 0 = no limit
     */
    public function __construct(
        array $allowedRootFields,
        private readonly int $maxBodyBytes = 512 * 1024,
        public readonly string $mode = self::MODE_RESTRICTED,
    ) {
        if ($mode !== self::MODE_DEFAULT && $mode !== self::MODE_RESTRICTED) {
            throw new \InvalidArgumentException(sprintf(
                'MAGENX_WORKER_MODE must be "%s" or "%s", got "%s"',
                self::MODE_DEFAULT,
                self::MODE_RESTRICTED,
                $mode
            ));
        }
        $this->allowed = array_fill_keys($allowedRootFields, true);
    }

    /**
     * MAGENX_WORKER_MODE picks the mode (empty = restricted, so an upgrade
     * never widens what an existing install sends to the worker);
     * MAGENX_WORKER_ALLOWED_FIELDS (comma separated) replaces the default list;
     * MAGENX_WORKER_MAX_BODY_KB caps the request body (0 = no limit).
     *
     * Call before the first request, while $_SERVER is the worker environment.
     */
    public static function fromEnvironment(): self
    {
        $env = static fn (string $name): string => (string) ($_SERVER[$name] ?? getenv($name) ?: '');
        $fields = array_values(array_filter(array_map('trim', explode(',', $env('MAGENX_WORKER_ALLOWED_FIELDS')))));
        $maxBodyKb = $env('MAGENX_WORKER_MAX_BODY_KB');
        $mode = strtolower(trim($env('MAGENX_WORKER_MODE')));

        return new self(
            $fields !== [] ? $fields : self::DEFAULT_ROOT_FIELDS,
            is_numeric($maxBodyKb) ? max(0, (int) $maxBodyKb) * 1024 : 512 * 1024,
            $mode !== '' ? $mode : self::MODE_RESTRICTED
        );
    }

    /**
     * @param QueryParser $parser the core parser: Magento reuses its parsed
     *                            document, so each query is parsed once.
     */
    public function allows(HttpRequest $request, QueryParser $parser): bool
    {
        if ($this->mode === self::MODE_DEFAULT) {
            return $this->withinBodyLimit($request);
        }
        if ((string) $request->getHeader('Authorization') !== '') {
            return false;
        }

        $query = $this->extractQuery($request);
        if ($query === null) {
            return false;
        }

        try {
            $document = $parser->parse($query);
        } catch (\Throwable) {
            return false;
        }

        $operations = 0;
        foreach ($document->definitions as $definition) {
            if (!$definition instanceof OperationDefinitionNode) {
                continue; // Fragment definitions; only root selections matter.
            }
            if ($definition->operation !== 'query') {
                return false;
            }
            foreach ($definition->selectionSet->selections as $selection) {
                if (!$selection instanceof FieldNode) {
                    return false;
                }
                $name = $selection->name->value;
                if ($name !== '__typename' && !isset($this->allowed[$name])) {
                    return false;
                }
            }
            ++$operations;
        }

        return $operations > 0;
    }

    private function extractQuery(HttpRequest $request): ?string
    {
        if ($request->isGet()) {
            $query = $request->getQuery('query');
            return is_string($query) && $query !== '' ? $query : null;
        }
        if (!$request->isPost()) {
            return null;
        }
        if (!$this->withinBodyLimit($request)) {
            return null;
        }
        try {
            $body = json_decode((string) $request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $query = is_array($body) ? ($body['query'] ?? null) : null;

        return is_string($query) && $query !== '' ? $query : null;
    }

    private function withinBodyLimit(HttpRequest $request): bool
    {
        $limit = $this->maxBodyBytes;
        if ($limit === 0 || $request->isGet()) {
            return true;
        }
        if ((int) $request->getServer('CONTENT_LENGTH') > $limit) {
            return false;
        }
        // Chunked body without Content-Length.
        return strlen((string) $request->getContent()) <= $limit;
    }
}
