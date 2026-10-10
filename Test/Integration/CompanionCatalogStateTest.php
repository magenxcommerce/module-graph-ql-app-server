<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AppServer\Test\Integration;

use Magenx\AppServer\Test\Integration\Cases\CompanionCases;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Companion public reads: what the worker serves in its read-only phase.
 *
 * _files/companion_config.php switches every module on, so the read paths
 * with per-request caches (deals, price history, GARAN prefetch, pickup
 * locations) run.
 *
 * @magentoDbIsolation disabled
 * @magentoAppIsolation enabled
 * @magentoAppArea graphql
 */
class CompanionCatalogStateTest extends CompanionStateTestCase
{
    /**
     * @magentoDataFixture Magento/Catalog/_files/product_simple.php
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoDataFixture Magenx_AppServer::Test/Integration/_files/blog_posts.php
     * @magentoDataFixture Magenx_AppServer::Test/Integration/_files/companion_config.php
     * @param array<string, mixed> $case
     */
    #[DataProvider('catalogCases')]
    public function testCatalogState(array $case): void
    {
        $this->runCase($case);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function catalogCases(): array
    {
        return CompanionCases::provide(CompanionCases::catalog());
    }
}
