<?php

declare(strict_types=1);

namespace Magenx\AppServer\Worker;

use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\OperationDefinitionNode;
use GraphQL\Language\Parser;
use Magento\Framework\App\Request\Http as HttpRequest;

/**
 * Which requests the worker may serve: anonymous, read-only, allowlisted.
 *
 * The worker is meant for catalog/CMS reads only. Customer, cart, checkout
 * and every mutation stay on PHP-FPM, where request state cannot leak. nginx
 * cannot see the operation inside a POST body, so the worker enforces the
 * scope itself and answers anything else with 503 *before* Magento touches it;
 * nginx then replays the request on PHP-FPM (see README, "Rollout").
 *
 * A request is in scope only when all of these hold:
 *  - no Authorization header (no customer or integration token);
 *  - the body parses, and every operation in it is a `query`;
 *  - every root field is on the allowlist (`__typename` aside), and the root
 *    selection uses no fragments, which could hide other fields.
 */
class ReadOnlyScope
{
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
     * @param string[] $allowedRootFields
     */
    public function __construct(array $allowedRootFields)
    {
        $this->allowed = array_fill_keys($allowedRootFields, true);
    }

    /**
     * MAGENX_WORKER_ALLOWED_FIELDS (comma separated) replaces the default list.
     *
     * Call before the first request, while $_SERVER is the worker environment.
     */
    public static function fromEnvironment(): self
    {
        $configured = (string) ($_SERVER['MAGENX_WORKER_ALLOWED_FIELDS'] ?? getenv('MAGENX_WORKER_ALLOWED_FIELDS') ?: '');
        $fields = array_values(array_filter(array_map('trim', explode(',', $configured))));

        return new self($fields !== [] ? $fields : self::DEFAULT_ROOT_FIELDS);
    }

    public function allows(HttpRequest $request): bool
    {
        if ((string) $request->getHeader('Authorization') !== '') {
            return false;
        }

        $query = $this->extractQuery($request);
        if ($query === null) {
            return false;
        }

        try {
            $document = Parser::parse($query, ['noLocation' => true]);
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
        try {
            $body = json_decode((string) $request->getContent(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        $query = is_array($body) ? ($body['query'] ?? null) : null;

        return is_string($query) && $query !== '' ? $query : null;
    }
}
