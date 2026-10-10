<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AppServer\Test\Integration;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\Module\Manager as ModuleManager;
use Magento\Framework\TestFramework\ApplicationStateComparator\Comparator;
use Magento\GraphQl\Quote\GetMaskedQuoteIdByReservedOrderId;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Api\Data\OrderInterfaceFactory;
use Magento\Sales\Api\ShipOrderInterface;
use Magenx\AppServer\Test\Integration\Cases\CompanionCases;
use PHPUnit\Framework\TestCase;

/**
 * Runs Cases\CompanionCases through StateDiff, seeding the data a case needs first.
 *
 * Seeds go through the modules' own GraphQL API where one exists, unmeasured,
 * so they depend on nothing but the public contract; only reward points, which
 * have no storefront write, use the module's service interface.
 *
 * Subclasses carry the fixtures (DB isolation must stay disabled: every
 * request commits, as in core GraphQlStateTest).
 */
abstract class CompanionStateTestCase extends TestCase
{
    private ?StateDiff $stateDiff = null;

    /** @var array<string, string|int> placeholder => value, from the seeds run so far */
    private array $placeholders = [];

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        if (!class_exists(Comparator::class)) {
            $this->markTestSkipped('The application state comparator is not available on this version of Magento.');
        }
        parent::setUp();
        $this->stateDiff = new StateDiff();
        $this->placeholders = [];
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        $this->stateDiff?->tearDown();
        $this->stateDiff = null;
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $case
     */
    protected function runCase(array $case): void
    {
        $objectManager = $this->stateDiff->getTestObjectManager();
        if (!$objectManager->get(ModuleManager::class)->isEnabled($case['module'])) {
            $this->markTestSkipped($case['module'] . ' is not enabled.');
        }

        foreach ($case['seeds'] ?? [] as $seed) {
            $method = 'seed' . str_replace('_', '', ucwords($seed, '_'));
            $provided = $this->$method();
            $this->assertSame(
                CompanionCases::SEEDS[$seed],
                array_keys($provided),
                "Seed $seed must provide exactly the placeholders CompanionCases::SEEDS lists."
            );
            $this->placeholders += $provided;
        }

        $case['variables'] = $this->resolvePlaceholders($case['variables'] ?? []);
        if (isset($case['variables2'])) {
            $case['variables2'] = $this->resolvePlaceholders($case['variables2']);
        }
        $case['auth'] = array_map(
            static fn (?string $who): ?array => $who === null ? null : CompanionCases::CUSTOMERS[$who],
            $case['auth']
        );

        $this->stateDiff->run($case, $this);
    }

    /**
     * Placeholders every case of the subclass may use, e.g. the cart id.
     *
     * @return array<string, string|int>
     */
    protected function placeholders(): array
    {
        return [];
    }

    /**
     * Run a seeding request; any GraphQL error fails the test with the response.
     *
     * @param array<string, mixed> $variables
     * @return array<string, mixed> the response's data
     */
    protected function seedRequest(string $query, array $variables = [], ?string $customer = 'A'): array
    {
        $response = $this->stateDiff->execute(
            $query,
            $variables,
            $customer === null ? null : CompanionCases::CUSTOMERS[$customer]
        );
        if (isset($response['errors']) || !isset($response['data'])) {
            $this->fail(sprintf(
                'Seeding with %s failed: %s',
                StateDiff::operationName($query),
                json_encode($response)
            ));
        }
        return $response['data'];
    }

    /**
     * The cart of customer A from the core GraphQl/Quote fixtures.
     */
    protected function maskedCartId(): string
    {
        return $this->stateDiff->getTestObjectManager()
            ->get(GetMaskedQuoteIdByReservedOrderId::class)
            ->execute('test_quote');
    }

    /**
     * 777 points for customer A, with the leak marker in the transaction reason.
     *
     * @return array<string, string|int>
     */
    private function seedReward(): array
    {
        $objectManager = $this->stateDiff->getTestObjectManager();
        $customer = $objectManager->get(CustomerRepositoryInterface::class)
            ->get(CompanionCases::CUSTOMERS['A']['email']);
        $objectManager->get(\Magenx\Reward\Api\RewardManagerInterface::class)->adjust(
            (int) $customer->getId(),
            (int) $customer->getWebsiteId(),
            777,
            CompanionCases::LEAK_MARKER . ' reward grant'
        );
        return [];
    }

    /**
     * A help desk ticket of customer A.
     *
     * @return array<string, string|int>
     */
    private function seedHelpdeskTicket(): array
    {
        $data = $this->seedRequest(
            <<<'QUERY'
                mutation magenxSeedTicket($subject: String!) {
                    createHelpdeskTicket(input: { subject: $subject, message: "Seeded body" }) { ticket { code } }
                }
                QUERY,
            ['subject' => CompanionCases::LEAK_MARKER . ' ticket']
        );
        return ['ticket_code' => (string) $data['createHelpdeskTicket']['ticket']['code']];
    }

    /**
     * A price alert of customer A on the fixture product.
     *
     * @return array<string, string|int>
     */
    private function seedProductAlert(): array
    {
        $this->seedRequest(
            <<<'QUERY'
                mutation magenxSeedProductAlert {
                    productAlertSubscribe(input: { product_sku: "simple", alert_type: PRICE }) { is_subscribed }
                }
                QUERY
        );
        return [];
    }

    /**
     * A pending GDPR request of customer A.
     *
     * @return array<string, string|int>
     */
    private function seedGdprRequest(): array
    {
        $this->seedRequest(
            <<<'QUERY'
                mutation magenxSeedGdprRequest($note: String) {
                    requestGdprAction(input: { type: EXPORT_DATA, note: $note }) { id }
                }
                QUERY,
            ['note' => CompanionCases::LEAK_MARKER . ' gdpr request']
        );
        return [];
    }

    /**
     * Ship customer A's order and open a return on one unit, with a comment.
     *
     * @return array<string, string|int>
     */
    private function seedReturn(): array
    {
        $order = $this->loadOrder();
        if ($order->canShip()) {
            $this->stateDiff->getTestObjectManager()->get(ShipOrderInterface::class)
                ->execute((int) $order->getEntityId());
            $order = $this->loadOrder();
        }
        $orderItems = $order->getItems();
        $orderItem = reset($orderItems);

        $metadata = $this->seedRequest(
            <<<'QUERY'
                query magenxSeedReturnMetadata {
                    returnAttributesMetadata { reasons { id } resolution_types { id } }
                }
                QUERY,
            [],
            null
        )['returnAttributesMetadata'];
        $placeholders = [
            'order_id' => (int) $order->getEntityId(),
            'order_item_id' => (int) $orderItem->getItemId(),
            'reason_id' => (int) ($metadata['reasons'][0]['id'] ?? 0),
            'resolution_type_id' => (int) ($metadata['resolution_types'][0]['id'] ?? 0),
        ];

        $return = $this->seedRequest(
            <<<'QUERY'
                mutation magenxSeedReturn($orderId: Int!, $orderItemId: Int!, $reasonId: Int!, $resolutionId: Int!) {
                    createCustomerReturn(input: {
                        order_id: $orderId
                        reason_id: $reasonId
                        resolution_type_id: $resolutionId
                        items: [{ order_item_id: $orderItemId, qty_requested: 1 }]
                    }) { return { rma_id } }
                }
                QUERY,
            [
                'orderId' => $placeholders['order_id'],
                'orderItemId' => $placeholders['order_item_id'],
                'reasonId' => $placeholders['reason_id'],
                'resolutionId' => $placeholders['resolution_type_id'],
            ]
        );
        $placeholders['rma_id'] = (int) $return['createCustomerReturn']['return']['rma_id'];

        $this->seedRequest(
            <<<'QUERY'
                mutation magenxSeedReturnComment($rmaId: Int!, $comment: String!) {
                    addReturnComment(input: { rma_id: $rmaId, comment: $comment }) { comment_id }
                }
                QUERY,
            ['rmaId' => $placeholders['rma_id'], 'comment' => CompanionCases::LEAK_MARKER . ' return comment']
        );

        return $placeholders;
    }

    private function loadOrder(): OrderInterface
    {
        return $this->stateDiff->getTestObjectManager()->create(OrderInterfaceFactory::class)->create()
            ->loadByIncrementId(CompanionCases::ORDER_NUMBER);
    }

    /**
     * Replace '%name%' values with seed results; a placeholder nothing seeded fails the test.
     *
     * @param array<string, mixed> $variables
     * @return array<string, mixed>
     */
    private function resolvePlaceholders(array $variables): array
    {
        $values = $this->placeholders + $this->placeholders();
        foreach ($variables as $name => $value) {
            if (is_string($value) && preg_match('/^%(\w+)%$/', $value, $match)) {
                if (!array_key_exists($match[1], $values)) {
                    $this->fail("Variable \$$name needs placeholder %{$match[1]}%, which no seed provided.");
                }
                $variables[$name] = $values[$match[1]];
            }
        }
        return $variables;
    }
}
