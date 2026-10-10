<?php
/**
 * Copyright © Magenx. All rights reserved.
 */
declare(strict_types=1);

namespace Magenx\AppServer\Test\Integration\Cases;

/**
 * Every GraphQL operation of the Magenx companion modules, as request-state cases.
 *
 * Each case runs the same operation twice in one application, the way two
 * consecutive requests on one worker thread would, and is checked by
 * StateDiff: no shared object may change state across the pair, and no
 * object may drift from the state it was constructed with. That is what has
 * to hold before the worker may serve the operation, so a case exists for
 * public reads, private (customer-token) reads, cart reads and mutations
 * alike. CompanionSchemaCoverageTest fails when a companion resolver has no
 * case here.
 *
 * Private cases mostly run customer A, then customer B (or a guest). A's data
 * is seeded with LEAK_MARKER in it, and the second response must not contain
 * the marker: a singleton that kept A's state would hand it to B.
 *
 * Plain PHP with no Magento dependency, so the unit suite can check the
 * catalogue's structure in CI without a Magento installation.
 *
 * Case keys:
 *  - module:     module that owns the operation; the case is skipped when it is
 *                not enabled. Fields of one module per case, so one missing
 *                module never fails another module's case on schema validation.
 *  - query:      the document; its operation name is the case's operationName,
 *                which also selects core state-skip-list entries, so it must be
 *                unique and must not collide with a core operation name.
 *  - variables:  first request; values '%name%' are replaced by seed results.
 *  - variables2: second request, when it must differ (optional).
 *  - auth:       [first, second]: 'A', 'B' or null (guest).
 *  - expected:   [first, second]: substring(s) each raw response must contain.
 *  - forbidden:  substrings the second response must not contain (optional).
 *  - errors:     true when a response may carry GraphQL errors (negative or
 *                config-dependent outcomes); otherwise any error fails the case.
 *  - seeds:      data to create before the pair runs (optional), see the
 *                seed*() methods of CompanionStateTestCase.
 */
class CompanionCases
{
    public const CUSTOMERS = [
        'A' => ['email' => 'customer@example.com', 'password' => 'password'],
        'B' => ['email' => 'customer_two@example.com', 'password' => 'password'],
    ];

    /** Seeded into customer A's data; must never reach customer B or a guest. */
    public const LEAK_MARKER = 'STATE-LEAK-A';

    /** Order of customer A, from Magento/Sales/_files/order_with_customer.php. */
    public const ORDER_NUMBER = '100000001';

    /** Pickup location set by the config fixtures of the catalog and cart tests. */
    public const PICKUP_CODE = 'STATE-PICKUP';

    public const SOCIAL_LOGIN_SECRET = 'state-test-secret';

    /**
     * Seeds a case may name, with the placeholders each one provides.
     * CompanionStateTestCase implements them as seed<CamelCase>().
     */
    public const SEEDS = [
        'reward' => [],
        'helpdesk_ticket' => ['ticket_code'],
        'product_alert' => [],
        'gdpr_request' => [],
        'return' => ['order_id', 'order_item_id', 'reason_id', 'resolution_type_id', 'rma_id'],
    ];

    /** Placeholders a whole group provides without a seed (CompanionCartStateTest). */
    public const GROUP_PLACEHOLDERS = [
        'cart' => ['cart_id'],
    ];

    private const NOT_AUTHORIZED = 'The current customer isn\'t authorized.';

    /**
     * Anonymous reads: the operations the read-only worker phase already serves.
     *
     * Run guest then guest, plus guest then customer for the ones whose output
     * depends on the customer group (deal and price-history caches).
     *
     * @return array<string, array<string, mixed>>
     */
    public static function catalog(): array
    {
        return [
            'bestSellers' => [
                'module' => 'Magenx_BestSellerGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateBestSellers {
                        bestSellers(pageSize: 5) {
                            total_count
                            items { rank product { sku } }
                        }
                    }
                    QUERY,
                'auth' => [null, null],
                'expected' => ['"bestSellers":{', '"bestSellers":{'],
            ],
            'ProductInterface.best_seller' => [
                'module' => 'Magenx_BestSellerGraphQl',
                'query' => self::productQuery('magenxStateProductBestSeller', 'best_seller { rank category_id }'),
                'auth' => [null, null],
                'expected' => ['"sku":"simple"', '"sku":"simple"'],
            ],
            'blogPosts' => [
                'module' => 'Magenx_BlogGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateBlogPosts {
                        blogPosts(pageSize: 5) {
                            total_count
                            items {
                                post_id
                                name
                                url_key
                                categories { items { name url_key } }
                                tags { items { name url_key } }
                                products { items { sku url_key } }
                                posts { total_count items { url_key } }
                            }
                        }
                    }
                    QUERY,
                'auth' => [null, null],
                'expected' => ['"url_key":"state-test-post"', '"url_key":"state-test-post"'],
            ],
            'blogPost' => [
                'module' => 'Magenx_BlogGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateBlogPost($urlKey: String!) {
                        blogPost(urlKey: $urlKey) {
                            name
                            categories { items { url_key } }
                            tags { items { url_key } }
                            products { items { sku } }
                            posts { items { url_key } }
                        }
                    }
                    QUERY,
                'variables' => ['urlKey' => 'state-test-post'],
                'variables2' => ['urlKey' => 'state-test-post-related'],
                'auth' => [null, null],
                'expected' => ['"name":"State test post"', '"name":"State test related post"'],
            ],
            'blogTag' => [
                'module' => 'Magenx_BlogGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateBlogTag {
                        blogTag(urlKey: "state-test-tag") {
                            name
                            posts { total_count items { url_key } }
                        }
                    }
                    QUERY,
                'auth' => [null, null],
                'expected' => ['"url_key":"state-test-post"', '"url_key":"state-test-post"'],
            ],
            'blogCategories' => [
                'module' => 'Magenx_BlogGraphQl',
                'query' => 'query magenxStateBlogCategories { blogCategories { category_id name url_key } }',
                'auth' => [null, null],
                'expected' => ['"url_key":"state-test-category"', '"url_key":"state-test-category"'],
            ],
            'deals' => [
                'module' => 'Magenx_DealGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateDeals($type: DealType) {
                        deals(type: $type, pageSize: 5) {
                            total_count
                            items { product { sku deal { label type ends_at } } }
                        }
                    }
                    QUERY,
                'variables' => ['type' => 'DEAL'],
                'variables2' => ['type' => 'FIRST_ORDER'],
                'auth' => [null, null],
                'expected' => ['"deals":{', '"deals":{'],
            ],
            'ProductInterface.deal (guest, then customer group)' => [
                'module' => 'Magenx_DealGraphQl',
                'query' => self::productQuery(
                    'magenxStateProductDeal',
                    'deal { label type deal_price regular_price percent_off }'
                ),
                'auth' => [null, 'A'],
                'expected' => ['"sku":"simple"', '"sku":"simple"'],
            ],
            'ProductInterface.price_history (guest, then customer group)' => [
                'module' => 'Magenx_PriceHistoryGraphQl',
                'query' => self::productQuery(
                    'magenxStateProductPriceHistory',
                    'price_history { lowest_price currency days since }'
                ),
                'auth' => [null, 'A'],
                'expected' => ['"sku":"simple"', '"sku":"simple"'],
            ],
            'garanNotice' => [
                'module' => 'Magenx_GaranGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateGaranNotice {
                        garanNotice { language link_label alt_text excluded_product_types }
                    }
                    QUERY,
                'auth' => [null, null],
                'expected' => ['"garanNotice":', '"garanNotice":'],
            ],
            'ProductInterface.garan_label' => [
                'module' => 'Magenx_GaranGraphQl',
                'query' => self::productQuery(
                    'magenxStateProductGaranLabel',
                    'garan_label { sku brand model_identifier formatted_duration }'
                ),
                'auth' => [null, null],
                'expected' => ['"sku":"simple"', '"sku":"simple"'],
            ],
            'gdprCookieGroups' => [
                'module' => 'Magenx_GdprGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateGdprCookieGroups {
                        gdprCookieGroups { code label is_required cookies { name source } }
                    }
                    QUERY,
                'auth' => [null, null],
                'expected' => ['"gdprCookieGroups":[', '"gdprCookieGroups":['],
            ],
            'quickSearchSuggestions' => [
                'module' => 'Magenx_QuickSearchGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateQuickSearch {
                        quickSearchSuggestions(popularTermsCount: 5) {
                            popular_terms { query_text num_results }
                            promotions { id type title product { sku } }
                        }
                    }
                    QUERY,
                'auth' => [null, null],
                'expected' => ['"popular_terms":[', '"popular_terms":['],
            ],
            'storePickupLocations' => [
                'module' => 'Magenx_StorePickupGraphQl',
                'query' => 'query magenxStatePickupLocations { storePickupLocations { code name city } }',
                'auth' => [null, null],
                'expected' => ['"code":"' . self::PICKUP_CODE . '"', '"code":"' . self::PICKUP_CODE . '"'],
            ],
            'ProductInterface.frequently_bought_together_products' => [
                'module' => 'Magenx_AutoProductLinks',
                'query' => self::productQuery(
                    'magenxStateProductFbt',
                    'frequently_bought_together_products { sku }'
                ),
                'auth' => [null, null],
                'expected' => ['"sku":"simple"', '"sku":"simple"'],
            ],
            'ProductInterface.rating_summary / review_count' => [
                'module' => 'Magenx_CatalogGraphQlBatch',
                'query' => self::productQuery('magenxStateProductRating', 'rating_summary review_count'),
                'auth' => [null, null],
                'expected' => ['"review_count":0', '"review_count":0'],
            ],
            'ProductInterface.magenx_attachments' => [
                'module' => 'Magenx_ProductAttachments',
                'query' => self::productQuery(
                    'magenxStateProductAttachments',
                    'magenx_attachments { id title type }'
                ),
                'auth' => [null, null],
                'expected' => ['"sku":"simple"', '"sku":"simple"'],
            ],
            'magenxProductAttachmentDownload (unknown id)' => [
                'module' => 'Magenx_ProductAttachments',
                'query' => <<<'QUERY'
                    query magenxStateAttachmentDownload {
                        magenxProductAttachmentDownload(id: 999999) { type file_url filename }
                    }
                    QUERY,
                'auth' => [null, null],
                'expected' => ['magenxProductAttachmentDownload', 'magenxProductAttachmentDownload'],
                'errors' => true,
            ],
            'ProductInterface.stripe_subscription' => [
                'module' => 'Magenx_StripePaymentsGraphQl',
                'query' => self::productQuery(
                    'magenxStateProductStripeSubscription',
                    'stripe_subscription { is_subscription interval interval_count }'
                ),
                'auth' => [null, null],
                'expected' => ['"sku":"simple"', '"sku":"simple"'],
            ],
            'helpdeskMetadata' => [
                'module' => 'Magenx_HelpdeskGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateHelpdeskMetadata {
                        helpdeskMetadata {
                            enabled
                            guest_enabled
                            departments { id title }
                            priorities { id code label }
                            custom_fields { code type options { value } }
                        }
                    }
                    QUERY,
                'auth' => [null, null],
                'expected' => ['"enabled":true', '"enabled":true'],
            ],
            'returnAttributesMetadata' => [
                'module' => 'Magenx_RmaGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateReturnMetadata {
                        returnAttributesMetadata {
                            reasons { id code label }
                            resolution_types { id code label }
                            conditions { id code label }
                        }
                    }
                    QUERY,
                'auth' => [null, null],
                'expected' => ['"reasons":[', '"reasons":['],
            ],
            'storeConfig (companion fields)' => [
                'module' => 'Magenx_StorePickupGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateStoreConfig {
                        storeConfig { store_code store_pickup_enabled store_pickup_title }
                    }
                    QUERY,
                'auth' => [null, null],
                'expected' => ['"store_pickup_enabled":true', '"store_pickup_enabled":true'],
            ],
        ];
    }

    /**
     * Customer-scoped reads: the private queries the worker refuses today.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function customer(): array
    {
        return [
            'Customer.reward_points (A, then B)' => [
                'module' => 'Magenx_RewardGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateRewardPoints {
                        customer {
                            email
                            reward_points {
                                balance
                                pending_balance
                                lifetime_earned
                                transactions(pageSize: 5) {
                                    total_count
                                    items { type points balance_after reason }
                                }
                            }
                        }
                    }
                    QUERY,
                'auth' => ['A', 'B'],
                'expected' => ['"balance":777', '"email":"customer_two@example.com"'],
                'forbidden' => [self::LEAK_MARKER, '"balance":777'],
                'seeds' => ['reward'],
            ],
            'Customer.reward_points (A, then guest)' => [
                'module' => 'Magenx_RewardGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateRewardPointsGuest {
                        customer { reward_points { balance transactions { items { reason } } } }
                    }
                    QUERY,
                'auth' => ['A', null],
                'expected' => ['"balance":777', self::NOT_AUTHORIZED],
                'forbidden' => [self::LEAK_MARKER, '"balance":777'],
                'errors' => true,
                'seeds' => ['reward'],
            ],
            'Customer.product_alerts (A, then B)' => [
                'module' => 'Magenx_ProductAlertGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateProductAlerts {
                        customer {
                            product_alerts {
                                price_alerts { id alert_type price status product { sku } }
                                stock_alerts { id alert_type status product { sku } }
                            }
                        }
                    }
                    QUERY,
                'auth' => ['A', 'B'],
                'expected' => ['"sku":"simple"', '"price_alerts":[]'],
                'forbidden' => ['"sku":"simple"'],
                'seeds' => ['product_alert'],
            ],
            'productAlertStatus (A, then B)' => [
                'module' => 'Magenx_ProductAlertGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateProductAlertStatus {
                        productAlertStatus(product_sku: "simple") {
                            product_sku
                            is_price_alert_subscribed
                            is_stock_alert_subscribed
                        }
                    }
                    QUERY,
                'auth' => ['A', 'B'],
                'expected' => ['"is_price_alert_subscribed":true', '"is_price_alert_subscribed":false'],
                'seeds' => ['product_alert'],
            ],
            'productAlertStatus (A, then guest)' => [
                'module' => 'Magenx_ProductAlertGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateProductAlertStatusGuest {
                        productAlertStatus(product_sku: "simple") { is_price_alert_subscribed }
                    }
                    QUERY,
                'auth' => ['A', null],
                'expected' => ['"is_price_alert_subscribed":true', self::NOT_AUTHORIZED],
                'forbidden' => ['"is_price_alert_subscribed":true'],
                'errors' => true,
                'seeds' => ['product_alert'],
            ],
            'Customer.stripe_subscriptions (A, then B)' => [
                'module' => 'Magenx_StripePaymentsGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateStripeSubscriptions {
                        customer {
                            email
                            stripe_subscriptions { subscription_id status amount can_cancel }
                        }
                    }
                    QUERY,
                'auth' => ['A', 'B'],
                'expected' => ['"email":"customer@example.com"', '"email":"customer_two@example.com"'],
            ],
            'myGdprRequests (A, then B)' => [
                'module' => 'Magenx_GdprGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateMyGdprRequests {
                        myGdprRequests { id type status customer_note requested_at }
                    }
                    QUERY,
                'auth' => ['A', 'B'],
                'expected' => [self::LEAK_MARKER, '"myGdprRequests":[]'],
                'forbidden' => [self::LEAK_MARKER],
                'seeds' => ['gdpr_request'],
            ],
            'myPersonalDataExport (A, then B)' => [
                'module' => 'Magenx_GdprGraphQl',
                'query' => <<<'QUERY'
                    query magenxStatePersonalDataExport {
                        myPersonalDataExport {
                            profile { email firstname lastname }
                            addresses { city postcode }
                            orders { increment_id grand_total }
                            consent_history { context analytics }
                        }
                    }
                    QUERY,
                'auth' => ['A', 'B'],
                'expected' => [
                    ['"email":"customer@example.com"', '"increment_id":"' . self::ORDER_NUMBER . '"'],
                    '"email":"customer_two@example.com"',
                ],
                'forbidden' => ['customer@example.com', self::ORDER_NUMBER],
            ],
            'myPersonalDataExport (A, then guest)' => [
                'module' => 'Magenx_GdprGraphQl',
                'query' => <<<'QUERY'
                    query magenxStatePersonalDataExportGuest {
                        myPersonalDataExport { profile { email } }
                    }
                    QUERY,
                'auth' => ['A', null],
                'expected' => ['"email":"customer@example.com"', self::NOT_AUTHORIZED],
                'forbidden' => ['customer@example.com'],
                'errors' => true,
            ],
            'helpdeskTickets (A, then B)' => [
                'module' => 'Magenx_HelpdeskGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateHelpdeskTickets {
                        helpdeskTickets(pageSize: 5) {
                            total_count
                            items {
                                code
                                subject
                                status { code label }
                                priority { code }
                                department { title }
                                messages { author_type body }
                                custom_fields { code value }
                            }
                        }
                    }
                    QUERY,
                'auth' => ['A', 'B'],
                'expected' => [self::LEAK_MARKER, '"total_count":0'],
                'forbidden' => [self::LEAK_MARKER],
                'seeds' => ['helpdesk_ticket'],
            ],
            'helpdeskTicket (A, then B)' => [
                'module' => 'Magenx_HelpdeskGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateHelpdeskTicket($code: String!) {
                        helpdeskTicket(code: $code) { code subject is_locked messages { body } }
                    }
                    QUERY,
                'variables' => ['code' => '%ticket_code%'],
                'auth' => ['A', 'B'],
                'expected' => [self::LEAK_MARKER, 'No such ticket.'],
                'forbidden' => [self::LEAK_MARKER],
                'errors' => true,
                'seeds' => ['helpdesk_ticket'],
            ],
            'customerReturns (A, then B)' => [
                'module' => 'Magenx_RmaGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateCustomerReturns {
                        customerReturns(pageSize: 5) {
                            total_count
                            items {
                                rma_id
                                order_number
                                status { code }
                                reason { code }
                                items { product_sku qty_requested condition { code } }
                                comments { comment }
                            }
                        }
                    }
                    QUERY,
                'auth' => ['A', 'B'],
                'expected' => [self::LEAK_MARKER, '"total_count":0'],
                'forbidden' => [self::LEAK_MARKER, self::ORDER_NUMBER],
                'seeds' => ['return'],
            ],
            'customerReturn (A, then B)' => [
                'module' => 'Magenx_RmaGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateCustomerReturn($rmaId: Int!) {
                        customerReturn(rma_id: $rmaId) { rma_id order_number comments { comment } }
                    }
                    QUERY,
                'variables' => ['rmaId' => '%rma_id%'],
                'auth' => ['A', 'B'],
                'expected' => [self::LEAK_MARKER, 'You are not authorized to view this return.'],
                'forbidden' => [self::LEAK_MARKER, self::ORDER_NUMBER],
                'errors' => true,
                'seeds' => ['return'],
            ],
            'returnComments (A, then B)' => [
                'module' => 'Magenx_RmaGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateReturnComments($rmaId: Int!) {
                        returnComments(rma_id: $rmaId) { total_count items { author_type comment } }
                    }
                    QUERY,
                'variables' => ['rmaId' => '%rma_id%'],
                'auth' => ['A', 'B'],
                'expected' => [self::LEAK_MARKER, 'You are not authorized to view this return.'],
                'forbidden' => [self::LEAK_MARKER],
                'errors' => true,
                'seeds' => ['return'],
            ],
            'guestReturn on a customer order (guest, then guest)' => [
                'module' => 'Magenx_RmaGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateGuestReturn($rmaId: Int!) {
                        guestReturn(order_number: "100000001", email: "customer@example.com", rma_id: $rmaId) {
                            rma_id
                            comments { comment }
                        }
                    }
                    QUERY,
                'variables' => ['rmaId' => '%rma_id%'],
                'auth' => [null, null],
                'expected' => ['We could not find a guest order', 'We could not find a guest order'],
                'forbidden' => [self::LEAK_MARKER],
                'errors' => true,
                'seeds' => ['return'],
            ],
            'CustomerOrder.store_pickup_location (A, then B)' => [
                'module' => 'Magenx_StorePickupGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateOrderPickup {
                        customer {
                            orders(filter: { number: { eq: "100000001" } }) {
                                items { number store_pickup_location { code name } }
                            }
                        }
                    }
                    QUERY,
                'auth' => ['A', 'B'],
                'expected' => ['"number":"' . self::ORDER_NUMBER . '"', '"items":[]'],
                'forbidden' => [self::ORDER_NUMBER],
            ],
            'OrderItemInterface.garan_labels (A, then B)' => [
                'module' => 'Magenx_GaranGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateOrderGaranLabels {
                        customer {
                            orders(filter: { number: { eq: "100000001" } }) {
                                items { number items { product_sku garan_labels { sku brand } } }
                            }
                        }
                    }
                    QUERY,
                'auth' => ['A', 'B'],
                'expected' => ['"number":"' . self::ORDER_NUMBER . '"', '"items":[]'],
                'forbidden' => [self::ORDER_NUMBER],
            ],
        ];
    }

    /**
     * Customer cart reads and cart mutations, on customer A's cart.
     *
     * Two requests by A exercise the quote totals collectors twice (the
     * TotalsCollectorList / reward-total reset in STATE_AUDIT.md); A then B
     * checks that B cannot reach A's cart through a stale cart or user id.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function cart(): array
    {
        return [
            'CartItemInterface.garan_labels + Cart.garan_notice_required (A, then A)' => [
                'module' => 'Magenx_GaranGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateCartGaran($cartId: String!) {
                        cart(cart_id: $cartId) {
                            garan_notice_required
                            items { product { sku } garan_labels { sku brand } }
                        }
                    }
                    QUERY,
                'variables' => ['cartId' => '%cart_id%'],
                'auth' => ['A', 'A'],
                'expected' => ['"sku":"simple_product"', '"sku":"simple_product"'],
            ],
            'CartItemInterface.stripe_subscription (A, then B)' => [
                'module' => 'Magenx_StripePaymentsGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateCartStripe($cartId: String!) {
                        cart(cart_id: $cartId) {
                            items { product { sku } stripe_subscription { is_subscription frequency_label } }
                        }
                    }
                    QUERY,
                'variables' => ['cartId' => '%cart_id%'],
                'auth' => ['A', 'B'],
                'expected' => ['"sku":"simple_product"', 'The current user cannot perform operations on cart'],
                'forbidden' => ['"sku":"simple_product"'],
                'errors' => true,
            ],
            'Cart.applied_reward_points (A, then B)' => [
                'module' => 'Magenx_RewardGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateCartReward($cartId: String!) {
                        cart(cart_id: $cartId) {
                            applied_reward_points { points money_amount { value currency } }
                            prices { grand_total { value } }
                        }
                    }
                    QUERY,
                'variables' => ['cartId' => '%cart_id%'],
                'auth' => ['A', 'B'],
                'expected' => ['"grand_total":{', 'The current user cannot perform operations on cart'],
                'forbidden' => ['"grand_total":{'],
                'errors' => true,
            ],
            'applyRewardPointsToCart (A, then A)' => [
                'module' => 'Magenx_RewardGraphQl',
                'query' => <<<'QUERY'
                    mutation magenxStateApplyRewardPoints($cartId: String!) {
                        applyRewardPointsToCart(cart_id: $cartId, points: 100) {
                            cart {
                                applied_reward_points { points money_amount { value } }
                                prices { grand_total { value } }
                            }
                        }
                    }
                    QUERY,
                'variables' => ['cartId' => '%cart_id%'],
                'auth' => ['A', 'A'],
                'expected' => ['"applied_reward_points":{"points":100', '"applied_reward_points":{"points":100'],
                'seeds' => ['reward'],
            ],
            'removeRewardPointsFromCart (A, then A)' => [
                'module' => 'Magenx_RewardGraphQl',
                'query' => <<<'QUERY'
                    mutation magenxStateRemoveRewardPoints($cartId: String!) {
                        removeRewardPointsFromCart(cart_id: $cartId) {
                            cart { applied_reward_points { points } prices { grand_total { value } } }
                        }
                    }
                    QUERY,
                'variables' => ['cartId' => '%cart_id%'],
                'auth' => ['A', 'A'],
                'expected' => ['"applied_reward_points":null', '"applied_reward_points":null'],
                'seeds' => ['reward'],
            ],
            'applyRewardPointsToCart (B on A\'s cart)' => [
                'module' => 'Magenx_RewardGraphQl',
                'query' => <<<'QUERY'
                    mutation magenxStateApplyRewardPointsForeign($cartId: String!) {
                        applyRewardPointsToCart(cart_id: $cartId, points: 100) {
                            cart { applied_reward_points { points } }
                        }
                    }
                    QUERY,
                'variables' => ['cartId' => '%cart_id%'],
                'auth' => ['B', 'B'],
                'expected' => [
                    'The current user cannot perform operations on cart',
                    'The current user cannot perform operations on cart',
                ],
                'errors' => true,
                'seeds' => ['reward'],
            ],
            'setStorePickupLocationOnCart + Cart.store_pickup_location (A, then A)' => [
                'module' => 'Magenx_StorePickupGraphQl',
                'query' => <<<'QUERY'
                    mutation magenxStateSetPickupLocation($cartId: String!) {
                        setStorePickupLocationOnCart(
                            input: { cart_id: $cartId, location_code: "STATE-PICKUP" }
                        ) {
                            cart { store_pickup_location { code name } }
                        }
                    }
                    QUERY,
                'variables' => ['cartId' => '%cart_id%'],
                'auth' => ['A', 'A'],
                'expected' => ['"code":"' . self::PICKUP_CODE . '"', '"code":"' . self::PICKUP_CODE . '"'],
            ],
            'Cart.store_pickup_location (A, then B)' => [
                'module' => 'Magenx_StorePickupGraphQl',
                'query' => <<<'QUERY'
                    query magenxStateCartPickup($cartId: String!) {
                        cart(cart_id: $cartId) { store_pickup_location { code } items { product { sku } } }
                    }
                    QUERY,
                'variables' => ['cartId' => '%cart_id%'],
                'auth' => ['A', 'B'],
                'expected' => ['"sku":"simple_product"', 'The current user cannot perform operations on cart'],
                'forbidden' => ['"sku":"simple_product"'],
                'errors' => true,
            ],
        ];
    }

    /**
     * Mutations outside the cart. Each pair is chosen so the second request is
     * valid after the first one's write, or its outcome is named explicitly.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function mutations(): array
    {
        return [
            'createHelpdeskTicket (A, then B)' => [
                'module' => 'Magenx_HelpdeskGraphQl',
                'query' => <<<'QUERY'
                    mutation magenxStateCreateTicket($subject: String!) {
                        createHelpdeskTicket(input: { subject: $subject, message: "State test body" }) {
                            ticket { code subject messages { body } }
                        }
                    }
                    QUERY,
                'variables' => ['subject' => self::LEAK_MARKER . ' created'],
                'variables2' => ['subject' => 'Customer B ticket'],
                'auth' => ['A', 'B'],
                'expected' => [self::LEAK_MARKER . ' created', '"subject":"Customer B ticket"'],
                'forbidden' => [self::LEAK_MARKER],
            ],
            'addHelpdeskTicketMessage (A, then B)' => [
                'module' => 'Magenx_HelpdeskGraphQl',
                'query' => <<<'QUERY'
                    mutation magenxStateAddTicketMessage($code: String!) {
                        addHelpdeskTicketMessage(input: { code: $code, message: "State test reply" }) {
                            ticket { code subject messages { body } }
                        }
                    }
                    QUERY,
                'variables' => ['code' => '%ticket_code%'],
                'auth' => ['A', 'B'],
                'expected' => ['State test reply', 'No such ticket.'],
                'forbidden' => [self::LEAK_MARKER],
                'errors' => true,
                'seeds' => ['helpdesk_ticket'],
            ],
            'closeHelpdeskTicket (A, then A)' => [
                'module' => 'Magenx_HelpdeskGraphQl',
                'query' => <<<'QUERY'
                    mutation magenxStateCloseTicket($code: String!) {
                        closeHelpdeskTicket(code: $code) { code is_locked status { code } }
                    }
                    QUERY,
                'variables' => ['code' => '%ticket_code%'],
                'auth' => ['A', 'A'],
                'expected' => ['"closeHelpdeskTicket":{', '"closeHelpdeskTicket":{'],
                'seeds' => ['helpdesk_ticket'],
            ],
            'createHelpdeskGuestTicket (guest, then guest)' => [
                'module' => 'Magenx_HelpdeskGraphQl',
                'query' => <<<'QUERY'
                    mutation magenxStateCreateGuestTicket($email: String!) {
                        createHelpdeskGuestTicket(input: {
                            email: $email
                            name: "State Guest"
                            subject: "State test guest ticket"
                            message: "State test guest body"
                        }) { code }
                    }
                    QUERY,
                'variables' => ['email' => 'state-guest-one@example.com'],
                'variables2' => ['email' => 'state-guest-two@example.com'],
                'auth' => [null, null],
                'expected' => ['"createHelpdeskGuestTicket":{"code":', '"createHelpdeskGuestTicket":{"code":'],
            ],
            'productAlertSubscribe (A, then B)' => [
                'module' => 'Magenx_ProductAlertGraphQl',
                'query' => <<<'QUERY'
                    mutation magenxStateProductAlertSubscribe {
                        productAlertSubscribe(input: { product_sku: "simple", alert_type: STOCK }) {
                            is_subscribed
                            product_alerts { price_alerts { product { sku } } stock_alerts { product { sku } } }
                        }
                    }
                    QUERY,
                'auth' => ['A', 'B'],
                'expected' => ['"is_subscribed":true', '"price_alerts":[]'],
                'seeds' => ['product_alert'],
            ],
            'productAlertUnsubscribe (A, then A)' => [
                'module' => 'Magenx_ProductAlertGraphQl',
                'query' => <<<'QUERY'
                    mutation magenxStateProductAlertUnsubscribe {
                        productAlertUnsubscribe(input: { product_sku: "simple", alert_type: PRICE }) {
                            is_subscribed
                            product_alerts { price_alerts { id } }
                        }
                    }
                    QUERY,
                'auth' => ['A', 'A'],
                'expected' => ['"is_subscribed":false', '"is_subscribed":false'],
                'seeds' => ['product_alert'],
            ],
            'submitCookieConsent (guest, then A)' => [
                'module' => 'Magenx_GdprGraphQl',
                'query' => <<<'QUERY'
                    mutation magenxStateCookieConsent {
                        submitCookieConsent(input: { analytics: true, marketing: false, preferences: true })
                    }
                    QUERY,
                'auth' => [null, 'A'],
                'expected' => ['"submitCookieConsent":true', '"submitCookieConsent":true'],
            ],
            'requestGdprAction (A, then B)' => [
                'module' => 'Magenx_GdprGraphQl',
                'query' => <<<'QUERY'
                    mutation magenxStateRequestGdprAction($note: String) {
                        requestGdprAction(input: { type: EXPORT_DATA, note: $note }) { id type status customer_note }
                    }
                    QUERY,
                'variables' => ['note' => self::LEAK_MARKER . ' mutation'],
                'variables2' => ['note' => 'Customer B request'],
                'auth' => ['A', 'B'],
                'expected' => ['"requestGdprAction"', '"requestGdprAction"'],
                'forbidden' => [self::LEAK_MARKER],
                // A may already hold a pending request from another case: a
                // refusal is a valid outcome, a leak is not.
                'errors' => true,
            ],
            'createCustomerReturn (A, then B)' => [
                'module' => 'Magenx_RmaGraphQl',
                'query' => <<<'QUERY'
                    mutation magenxStateCreateReturn(
                        $orderId: Int!, $orderItemId: Int!, $reasonId: Int!, $resolutionId: Int!
                    ) {
                        createCustomerReturn(input: {
                            order_id: $orderId
                            reason_id: $reasonId
                            resolution_type_id: $resolutionId
                            items: [{ order_item_id: $orderItemId, qty_requested: 1 }]
                        }) {
                            return { rma_id order_number items { product_sku qty_requested } }
                        }
                    }
                    QUERY,
                'variables' => [
                    'orderId' => '%order_id%',
                    'orderItemId' => '%order_item_id%',
                    'reasonId' => '%reason_id%',
                    'resolutionId' => '%resolution_type_id%',
                ],
                'auth' => ['A', 'B'],
                'expected' => [
                    '"order_number":"' . self::ORDER_NUMBER . '"',
                    'You are not authorized to create a return for this order.',
                ],
                'errors' => true,
                'seeds' => ['return'],
            ],
            'createGuestReturn on a customer order (guest, then guest)' => [
                'module' => 'Magenx_RmaGraphQl',
                'query' => <<<'QUERY'
                    mutation magenxStateCreateGuestReturn(
                        $orderItemId: Int!, $reasonId: Int!, $resolutionId: Int!
                    ) {
                        createGuestReturn(input: {
                            order_number: "100000001"
                            email: "customer@example.com"
                            reason_id: $reasonId
                            resolution_type_id: $resolutionId
                            items: [{ order_item_id: $orderItemId, qty_requested: 1 }]
                        }) {
                            return { rma_id }
                        }
                    }
                    QUERY,
                'variables' => [
                    'orderItemId' => '%order_item_id%',
                    'reasonId' => '%reason_id%',
                    'resolutionId' => '%resolution_type_id%',
                ],
                'auth' => [null, null],
                'expected' => ['We could not find a guest order', 'We could not find a guest order'],
                'errors' => true,
                'seeds' => ['return'],
            ],
            'addReturnComment (A, then B)' => [
                'module' => 'Magenx_RmaGraphQl',
                'query' => <<<'QUERY'
                    mutation magenxStateAddReturnComment($rmaId: Int!) {
                        addReturnComment(input: { rma_id: $rmaId, comment: "State test follow-up" }) {
                            comment_id
                            comment
                        }
                    }
                    QUERY,
                'variables' => ['rmaId' => '%rma_id%'],
                'auth' => ['A', 'B'],
                'expected' => ['"comment":"State test follow-up"', 'You are not authorized to comment on this return.'],
                'forbidden' => [self::LEAK_MARKER],
                'errors' => true,
                'seeds' => ['return'],
            ],
            'submitWithdrawal (guest, then A)' => [
                'module' => 'Magenx_RmaGraphQl',
                'query' => <<<'QUERY'
                    mutation magenxStateSubmitWithdrawal($email: String!) {
                        submitWithdrawal(input: {
                            email: $email
                            name: "State Withdrawal"
                            order_number: "100000001"
                            message: "State test withdrawal"
                        }) { ticket_code received_at }
                    }
                    QUERY,
                'variables' => ['email' => 'state-withdrawal@example.com'],
                'variables2' => ['email' => 'customer@example.com'],
                'auth' => [null, 'A'],
                'expected' => ['"submitWithdrawal"', '"submitWithdrawal"'],
                // The outcome depends on the withdrawal and help desk setup; the
                // state of the objects it touches is what this case checks.
                'errors' => true,
            ],
            'socialLogin (wrong secret, then malformed token)' => [
                'module' => 'Magenx_SocialLoginGraphQl',
                'query' => <<<'QUERY'
                    mutation magenxStateSocialLogin($secret: String!) {
                        socialLogin(input: { secret: $secret, idToken: "not-a-jwt" }) { token created }
                    }
                    QUERY,
                'variables' => ['secret' => 'wrong-secret'],
                'variables2' => ['secret' => self::SOCIAL_LOGIN_SECRET],
                'auth' => [null, null],
                'expected' => ['Not authorized.', 'Not authorized.'],
                'forbidden' => ['"token":"'],
                'errors' => true,
            ],
            'cancelStripeSubscription (A, then B)' => [
                'module' => 'Magenx_StripePaymentsGraphQl',
                'query' => <<<'QUERY'
                    mutation magenxStateCancelStripeSubscription {
                        cancelStripeSubscription(subscription_id: "sub_state_test") {
                            success
                            message
                            subscriptions { subscription_id }
                        }
                    }
                    QUERY,
                'auth' => ['A', 'B'],
                'expected' => ['could not be canceled', 'could not be canceled'],
                'errors' => true,
            ],
        ];
    }

    /**
     * Every case, keyed by "<group>: <name>".
     *
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $all = [];
        foreach (['catalog', 'customer', 'cart', 'mutations'] as $group) {
            foreach (self::$group() as $name => $case) {
                $all[$group . ': ' . $name] = $case;
            }
        }
        return $all;
    }

    /**
     * Data provider shape: [name => [case]].
     *
     * @param array<string, array<string, mixed>> $cases
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function provide(array $cases): array
    {
        return array_map(static fn (array $case): array => [$case], $cases);
    }

    /**
     * Product query on the fixture product, selecting one module's extension fields.
     */
    private static function productQuery(string $operationName, string $fields): string
    {
        return <<<QUERY
            query {$operationName} {
                products(filter: { sku: { eq: "simple" } }) {
                    items {
                        sku
                        {$fields}
                    }
                }
            }
            QUERY;
    }
}
