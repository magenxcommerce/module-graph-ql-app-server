<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AppServer\Test\Integration;

use Magenx\AppServer\Test\Integration\Cases\CompanionCases;

/**
 * Companion public reads: what the worker serves in its read-only phase.
 *
 * Config fixtures switch every module on, so the read paths with per-request
 * caches (deals, price history, GARAN prefetch, pickup locations) run.
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
     * @magentoConfigFixture default_store magenx_price_history/general/enabled 1
     * @magentoConfigFixture default_store magenx_garan/general/enabled 1
     * @magentoConfigFixture default_store magenx_garan/garan/enabled 1
     * @magentoConfigFixture default_store magenx_product_attachments/general/enabled 1
     * @magentoConfigFixture default_store magenx_helpdesk/general/enabled 1
     * @magentoConfigFixture default_store magenx_helpdesk/guest/enabled 1
     * @magentoConfigFixture default_store rma/general/enabled 1
     * @magentoConfigFixture default_store carriers/magenx_storepickup/active 1
     * @magentoConfigFixture default_store carriers/magenx_storepickup/locations {"s":{"code":"STATE-PICKUP","name":"Pickup","city":"Testville"}}
     * @dataProvider catalogCases
     * @param array<string, mixed> $case
     */
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
