# Request-state audit for the GraphQL worker (2026-10-07)

Which objects in our modules keep state between requests once `/graphql`
runs in a long-lived worker? Under PHP-FPM every request starts empty, so
none of these were bugs before.

**Scope:** `magento/StripeIntegration/*` (this repo) and the 29
`magenxcommerce/module-*` repos.

**Method:**
1. A token scan of every non-test PHP class. It looks for property writes
   outside the constructor, static properties and static locals,
   superglobals, `exit`, `header()`, session use, `Registry`, and work done
   in constructors.
2. A manual read of each hit, checked against what runs in the `graphql`
   area: global and `graphql` DI, global and `graphql` events, and
   resolvers. Code that is only `adminhtml`, cron, CLI or frontend-route
   stays on PHP-FPM and is out of scope.
3. A check against how core 2.4.8 resets its own base classes.

**Not covered:**
- The closed-source `stripe/module-payments` core module. It is not on
  Packagist.
- Core classes beyond the ones named below.
- Runtime behaviour. The authoritative check is still `GraphQlStateTest`
  with every module enabled, plus this module's companion state tests
  (`Test/Integration`, see README "Companion state tests"), which run every
  companion query and mutation, guest and customer, through the same
  comparator.

## What applies to the read-only phase

The worker only serves anonymous, read-only, allowlisted queries
(`Worker/ReadOnlyScope.php`). Everything else is refused, and nginx replays
it on PHP-FPM. In this phase, mutations, customer tokens, cart and checkout
never run in the worker, so findings #1–#4 below do not apply yet. They are
fixed or mitigated ahead of a later phase that moves cart or checkout over.

What does apply now:
- the stale/growing caches on read paths: deal, price-history, garan
  prefetch, store-pickup locations;
- recycling limits (config edits are seen within `MAGENX_WORKER_MAX_AGE`).

Before any cart or checkout traffic goes to the worker, also fix this:
`Quote\TotalsCollectorList` caches its `Address\Total\Collector` (and the
store it was built for) on a shared instance. Core `AbstractTotal::_resetState()`
then nulls every collector's code after the first request. Collectors such
as core `Subtotal` get their code only once, when the collector is built, so
cart totals would break from the second request on, and a worker would keep
computing totals with the first store it saw. The fix is to rebuild the
collector per request: reset `TotalsCollectorList::$totalCollector`, which
its getter recreates lazily. That needs an exception in
`bin/check-reset-json.php`, because the constructor assigns that property.

The lost codes are now mitigated here: `Plugin\Quote\RestoreTotalCodes`
(graphql area) sets every total whose code is null back to the code the
collector registered it under, on each `Collector::getCollectors()`, which
every collect and fetch goes through. A plugin on `_resetState()` itself is
not possible: the interceptor generator skips that method. The store the
collector was built for is still kept, so that part of the fix is open.

## Fix status

Fixes for #1, #2 and every "should fix" row below are pushed to branch
`claude/peaceful-brown-7sjyjd` in each module repo. They are not merged yet:
module CI runs on pull requests, and release-please releases from `main`.

| Repo | Commit |
|---|---|
| `module-reward` | `5faddda` |
| `module-garan-graph-ql` | `853d91d` |
| `module-deal-graph-ql` | `dfaa107` |
| `module-price-history-graph-ql` | `5938474` |
| `module-helpdesk-graph-ql` | `686791d` |
| `module-helpdesk` | `41d496a` |
| `module-rma` | `9417711` |
| `module-store-pickup-graph-ql` | `31196ad` |

#3 and #4 are mitigated in this module's `etc/reset.json`. The rest of the
Stripe core module still needs the check described under #4.

## Must fix before worker traffic

### 1. Reward total loses its code after the first request — `module-reward`

`Magenx\Reward\Model\Quote\Total\Reward` sets its code only in the
constructor (`setCode('reward')`). Core
`AbstractTotal::_resetState()` sets `_code = null`, and the collector
instances live on, because `TotalsCollectorList` caches the collector. From
the second request in a worker, the reward total writes and fetches under a
`null` code, so the reward discount drops out of cart and order totals.

Core `Address\Total\Shipping` has the same constructor pattern and fixes it
by overriding `_resetState()`. Do the same:

```php
public function _resetState(): void
{
    parent::_resetState();
    $this->setCode('reward');
}
```

### 2. Pending email attachments survive a failed send — `module-garan-graph-ql`

`Model\Email\PendingAttachments` is a shared queue:
- `email_order_set_template_vars_before` fills it.
- `TransportBuilder::afterGetTransport` empties it.

If the send fails before `getTransport()` (for example, an invalid BCC
copy-to address throws in `SenderBuilder`), `OrderSender` catches the
exception and the request goes on normally. The documents stay queued, and
the next email built in that worker gets them attached, whatever its type
and whoever it goes to. That includes another customer's GARAN product
labels.

Fix: `implements ResetAfterRequestInterface` with
`_resetState(): void { $this->clear(); }`.

### 3. Email recipients survive a failed send — core `TransportBuilder` (mitigated here)

Same failure point as #2. `addTo()` appends to the shared builder's private
`messageData`, and only `getTransport()` clears it. If `addBcc()` throws
after `addTo(customer A)`, the next order email from that worker goes to
customer B **and** customer A.

`Magenx_ProductAttachments`' `AttachmentTransportBuilder` is a subclass
with the same behaviour.

Mitigated in this module: `etc/reset.json` clears `messageData` and the
template fields after every request. The core `Resetter` matches by
`instanceof`, so subclasses are covered too. Verified against the 2.4.8
`Resetter`.

### 4. Stripe helpers memoize per-customer state — confirmed, mitigated here

`stripe/module-payments` has no `_resetState()` anywhere and caches per-request
state on shared helpers. Most serious: `Helper\Generic::getCustomerModel()`
keeps the Stripe customer in `$currentCustomer`, and the Magento customer id in
`$magentoCustomerId`. In a worker, customer B would get customer A's Stripe
customer:

- in `PaymentsGraphQl`: A's subscriptions in `Customer.stripe_subscriptions`,
  and a passing `ownsSubscriptionId()` check, so B could cancel A's
  subscriptions;
- in checkout: saved cards and attached payment methods.

Mitigated in this module: `etc/reset.json` resets those two properties, plus
the per-request state of these helpers to their declared defaults:

- `Quote`: `quoteId`, `quotesCache`, `customerDataInvalidated`
- `Order`: `orderComments`, `ordersCache`
- `Subscriptions`, `SubscriptionSwitch`, `RecurringOrderData`, `Compare`,
  `RedirectInvoice`, `Refunds`
- `RequestCache::$cache`, which is static

Every entry is a no-op on stores without Stripe.

Left out on purpose:
- lazily injected dependencies;
- helpers used only by CLI, admin or webhooks (`Migrate`, `Webhooks*`);
- properties that may be set in the constructor (`Address`, `Multishipping`,
  `Error`, `PaymentMethod`); those are stale config at worst, not
  per-customer.

Verify the entries against the installed version, and again after every
Stripe upgrade:

```bash
php vendor/magenxcommerce/module-graph-ql-app-server/bin/check-reset-json.php
```

It fails when an entry no longer matches the installed class: a missing
property, a changed default, a property the constructor assigns, or a class
that has gained its own `_resetState()`.

The companion and core state tests then flagged more of Stripe's
`Model/` and `Observer/` classes (checked against the public
`stripe-magento2-releases` 4.6.7 source; CI installs 4.7.1):

- `Observer\QuoteObserver::$hasSubscriptions`: worked out once for the
  first quote on a shared observer of `sales_quote_collect_totals_before`,
  then reused for every later cart. It picks the tax calculation method, so
  in a worker customer B's cart is taxed as customer A's was. Reset here.
- `Model\Order\InitialFeeManagement::$initialFee`/`$baseInitialFee`: an
  order's fee kept on a shared object, written onto the next order by
  `setDataFrom()`. Reset here.
- `Model\Stripe\Service\StripeObjectService::$objectSpace` and
  `Model\Config::$isSubscriptionsEnabled`: per-call and config state on
  shared objects. Reset here.
- `Model\InitialFee` and `Tax\Model\Quote\Total\StripeFlatFees` set their
  total code only in the constructor, so core `AbstractTotal::_resetState()`
  leaves them without one (same as #1). `reset.json` cannot help: the
  Resetter skips it for classes with their own `_resetState()`. Mitigated
  by `RestoreTotalCodes`, above. `InitialFee` also reads the subscriptions
  setting once per process (`$isSubscriptionsEnabled`), for the first
  store it sees. That is still open; the state tests filter it until it
  is reset.

The same runs flagged core order loading, which also keeps state:
`Sales\Model\Order\ShippingAssignmentBuilder::$orderId` and
`SalesGraphQl\Model\OrderItem\DataProvider`'s order item cache (no
cross-customer read, since the order resolver checks ownership first, but
stale and growing, and it keeps whole order models alive). Reset here.

Still to check on the install:
- The rest of Stripe's `Model/` classes:
  `grep -rnE '^\s+(public|protected|private) (static )?\$\w+ = ' vendor/stripe/module-payments/Model`
- The guest branch, which reads `sessionManager->getStripeCustomerId()` from
  the PHP session.
- `GraphQlCheckoutMutationsStateTest` with Stripe as the payment method,
  before sending checkout traffic to the worker.

## Should fix: stale or growing state (no cross-customer data)

These are request-scoped caches on singletons. Comments like "cached per
request" show they were written for FPM. In a worker they live until
recycle (`MAGENX_WORKER_MAX_AGE`, default 300 s), so they serve stale data
and grow with every product they see. The fix for each is
`ResetAfterRequestInterface`, with `_resetState()` emptying the caches
(no-op under FPM).

| Module | Class | State | Effect in worker |
|---|---|---|---|
| deal-graph-ql | `Model\DealProvider` | `$now ??= time()`, `dealRules`, `matchCache` | Deal start/end evaluated at the first request's time; rule edits unseen; growth per product |
| deal-graph-ql | `Model\CustomerContext` | `groupCache` (by customer id) | Customer group change unseen |
| price-history-graph-ql | `Model\PriceHistoryProvider` | `cache` | Stale lowest price; growth per product |
| price-history-graph-ql | `Model\CustomerContext` | `groupCache` | As above |
| garan-graph-ql | `Model\Garan\PrefetchedValues` | `parents`, `values` | Stale GARAN/brand attribute values; growth |
| garan-graph-ql | `Model\Garan\Resolver` | `parentIds` | Stale parent links; growth |
| helpdesk-graph-ql | `Resolver\DataProvider\TicketFormatter` | statuses, priorities, departments, fields, `orderNumbers` | Admin label edits unseen; growth |
| helpdesk | `Model\StoreScope`, `Ticket\StatusResolver`, `Field\ValueManager` | lookup maps | Admin edits unseen |
| rma | `Model\RMA\StatusResolver` | code/id map | Admin edits unseen |
| store-pickup-graph-ql | `Model\Config` | `locationsCache` | Location config edits unseen |

## Reviewed, no action

- **`StripeIntegration\SharedPaymentTokens\Model\ConfirmScope`:** the token
  is opened and closed in `try/finally` around the confirm. The cached
  `StripeClient`s are keyed by API-key hash and version. Optional: add a
  `_resetState()` that calls `close()`, as defence in depth.
- **`GraphQlTiming\Plugin\ServerTimingPlugin`:** `startedAt` is overwritten
  in every `beforeDispatch`.
- **`SocialLoginGraphQl\Model\Config::readEnv()`:** reads the secret from
  env or `$_SERVER` on every call, with no caching. Deployment note: the
  worker container needs the same environment variable PHP-FPM gets today.
- **Request-independent memos:** `auto-product-links` attribute ids and
  garan font metrics. The `best-seller` table check and the
  `auto-product-links` link-type id were listed here too, but the state test
  counts any change to a shared object, so this module's `etc/reset.json`
  now clears both after each request (one cheap lookup per request, as
  under PHP-FPM).
- **No static properties, `exit`/`die`, PHP sessions or `Registry`** on any
  GraphQL path.
- **Search-criteria builders** are always used in one chained
  `addFilter()…->create()` expression, so they cannot leak filters.
- **Out of the worker path:**
  - admin only: admin-activity, platform, product-feed;
  - frontend route `/magenx-mcp`, stays on FPM: ai-mcp;
  - cron or admin: sitemap, abandoned-checkout, helpdesk gateway, the
    Adminhtml/Ui/Console code in every module.
