<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AppServer\Test\Integration;

use Magenx\AppServer\Test\Integration\Cases\CompanionCases;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Companion private reads and mutations: what must hold before the worker
 * serves customer-token requests and mutations.
 *
 * Customer A owns order 100000001 and the seeded data; customer B is a second
 * account in the same group, so B's requests go through the same group-level
 * caches as A's and only per-customer state can tell them apart.
 *
 * @magentoDbIsolation disabled
 * @magentoAppIsolation enabled
 * @magentoAppArea graphql
 */
class CompanionCustomerStateTest extends CompanionStateTestCase
{
    /**
     * @magentoDataFixture Magento/Sales/_files/order_with_customer.php
     * @magentoDataFixture Magenx_AppServer::Test/Integration/_files/second_customer.php
     * @magentoDataFixture Magenx_AppServer::Test/Integration/_files/companion_config.php
     * @param array<string, mixed> $case
     */
    #[DataProvider('customerCases')]
    public function testCustomerState(array $case): void
    {
        $this->runCase($case);
    }

    /**
     * @magentoDataFixture Magento/Sales/_files/order_with_customer.php
     * @magentoDataFixture Magenx_AppServer::Test/Integration/_files/second_customer.php
     * @magentoDataFixture Magenx_AppServer::Test/Integration/_files/companion_config.php
     * @param array<string, mixed> $case
     */
    #[DataProvider('mutationCases')]
    public function testMutationState(array $case): void
    {
        $this->runCase($case);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function customerCases(): array
    {
        return CompanionCases::provide(CompanionCases::customer());
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function mutationCases(): array
    {
        return CompanionCases::provide(CompanionCases::mutations());
    }
}
