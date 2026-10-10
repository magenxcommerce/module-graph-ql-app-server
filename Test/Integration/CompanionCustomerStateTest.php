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
     * @magentoConfigFixture base_website magenx_reward/general/enabled 1
     * @magentoConfigFixture base_website magenx_reward/redemption/rate 0.01
     * @magentoConfigFixture default_store magenx_helpdesk/general/enabled 1
     * @magentoConfigFixture default_store magenx_helpdesk/customer_account/enabled 1
     * @magentoConfigFixture default_store magenx_helpdesk/guest/enabled 1
     * @magentoConfigFixture default_store rma/general/enabled 1
     * @magentoConfigFixture default_store rma/policy/allowed_order_statuses processing,complete
     * @magentoConfigFixture default_store rma/withdrawal/enabled 1
     * @magentoConfigFixture default_store catalog/productalert/allow_price 1
     * @magentoConfigFixture default_store catalog/productalert/allow_stock 1
     * @magentoConfigFixture default_store magenx_price_history/general/enabled 1
     * @magentoConfigFixture default_store magenx_garan/general/enabled 1
     * @magentoConfigFixture default_store magenx_garan/garan/enabled 1
     * @magentoConfigFixture default_store carriers/magenx_storepickup/active 1
     * @magentoConfigFixture default_store carriers/magenx_storepickup/locations {"s":{"code":"STATE-PICKUP","name":"Pickup","city":"Testville"}}
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
     * @magentoConfigFixture base_website magenx_reward/general/enabled 1
     * @magentoConfigFixture base_website magenx_reward/redemption/rate 0.01
     * @magentoConfigFixture default_store magenx_helpdesk/general/enabled 1
     * @magentoConfigFixture default_store magenx_helpdesk/customer_account/enabled 1
     * @magentoConfigFixture default_store magenx_helpdesk/guest/enabled 1
     * @magentoConfigFixture default_store rma/general/enabled 1
     * @magentoConfigFixture default_store rma/policy/allowed_order_statuses processing,complete
     * @magentoConfigFixture default_store rma/withdrawal/enabled 1
     * @magentoConfigFixture default_store catalog/productalert/allow_price 1
     * @magentoConfigFixture default_store catalog/productalert/allow_stock 1
     * @magentoConfigFixture default_store magenx_social_login/general/shared_secret state-test-secret
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
