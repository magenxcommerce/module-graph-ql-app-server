<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AppServer\Test\Integration;

use Magenx\AppServer\Test\Integration\Cases\CompanionCases;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Companion cart fields and cart mutations on customer A's cart.
 *
 * Every pair collects quote totals twice in one application, which is where
 * a totals collector that loses its code on reset (STATE_AUDIT.md #1 and the
 * TotalsCollectorList note) shows up.
 *
 * @magentoDbIsolation disabled
 * @magentoAppIsolation enabled
 * @magentoAppArea graphql
 */
class CompanionCartStateTest extends CompanionStateTestCase
{
    /**
     * @magentoDataFixture Magento/Customer/_files/customer.php
     * @magentoDataFixture Magenx_AppServer::Test/Integration/_files/second_customer.php
     * @magentoDataFixture Magento/GraphQl/Catalog/_files/simple_product.php
     * @magentoDataFixture Magento/GraphQl/Quote/_files/customer/create_empty_cart.php
     * @magentoDataFixture Magento/GraphQl/Quote/_files/add_simple_product.php
     * @magentoConfigFixture base_website magenx_reward/general/enabled 1
     * @magentoConfigFixture base_website magenx_reward/redemption/rate 0.01
     * @magentoConfigFixture default_store magenx_garan/general/enabled 1
     * @magentoConfigFixture default_store magenx_garan/garan/enabled 1
     * @magentoConfigFixture default_store carriers/magenx_storepickup/active 1
     * @magentoConfigFixture default_store carriers/magenx_storepickup/locations {"s":{"code":"STATE-PICKUP","name":"Pickup","city":"Testville"}}
     * @param array<string, mixed> $case
     */
    #[DataProvider('cartCases')]
    public function testCartState(array $case): void
    {
        $this->runCase($case);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cartCases(): array
    {
        return CompanionCases::provide(CompanionCases::cart());
    }

    /**
     * @inheritDoc
     */
    protected function placeholders(): array
    {
        return ['cart_id' => $this->maskedCartId()];
    }
}
