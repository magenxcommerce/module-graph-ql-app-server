# Magenx_AppServer — GraphQL application server (PoC)

Serves Magento's `/graphql` from a [FrankenPHP](https://frankenphp.dev) worker
that boots Magento **once per worker** and keeps it in memory, instead of
re-bootstrapping on every request like PHP-FPM. Open-source counterpart of
the idea behind Adobe Commerce's (Commerce-only, Swoole-based)
`Magento_ApplicationServer`; no Adobe code is used.

**Status: proof of concept.** It has not run against a real store yet.
Do not send production traffic to it before the verification gate below
passes with every module you run enabled.

## How it works

Magento 2.4.7+ core already contains the state-reset machinery
(`ResetAfterRequestInterface::_resetState()`, the `Resetter`, `reset.json`,
and `GraphQlStateTest`), but in Open Source nothing feeds the Resetter: the
object factory that registers instances with it ships only with Commerce.
This module supplies that piece and the request loop.

| File | Role |
|---|---|
| `bin/graphql-worker.php` | FrankenPHP worker entrypoint: bootstrap once, loop on `frankenphp_handle_request()` |
| `Worker/GraphQlWorker.php` | Per-worker setup (error handler, `graphql` area, area DI config, front controller) and per-request hydrate → dispatch → send → reset |
| `Worker/Limits.php` | Recycle limits: requests, memory, age |
| `Worker/ReadOnlyScope.php` | Admits only anonymous, read-only, allowlisted queries; everything else is refused with 503 for nginx to replay on PHP-FPM |
| `ObjectManager/ObjectManagerFactory.php` | Worker-only `ObjectManagerFactory` that swaps in the environment below |
| `ObjectManager/EnvironmentFactory.php`, `Environment/Compiled.php` | Compiled DI environment with the reset-tracking factory; refuses to boot without `setup:di:compile` output |
| `ObjectManager/Factory/Compiled.php` | Subclass of the core `Compiled` factory that registers every created object with the core `Resetter` |
| `bin/check-reset-json.php` | Checks `etc/reset.json` against the installed classes (property exists, default matches, not set in the constructor) |
| `frankenphp/` | Worker image and wiring for [magenx/Magento-2-docker-configuration](https://github.com/magenx/Magento-2-docker-configuration): `Dockerfile`, `Caddyfile`, `zz-worker.ini`, compose override, nginx templates, `.env` block, `install.sh` |

Per request:

1. **Hydrate.** The shared `Request\Http` is reset and refilled from the
   superglobals FrankenPHP set for this request. It is changed in place,
   never replaced, because many singletons captured it when they were built.
   This also clears request properties that core `_resetState()` misses in
   2.4.8 (`originalPathInfo`, `isSafeMethod`, `route`, ...).
   Cookies are dropped (`$_COOKIE`, `HTTP_COOKIE`; Caddy also strips the
   header): anonymous reads need none, and a session cookie must never
   resume a PHP session inside a long-lived thread.
2. **Scope check.** Anything but an anonymous, read-only, allowlisted query
   gets 503 before Magento runs, and nginx replays it on PHP-FPM (see
   Rollout). The query is parsed with the core `QueryParser`, which Magento
   then reuses, so each query is parsed once.
3. **Maintenance check.** Answers 503 while `var/.maintenance.flag` exists.
4. **Dispatch** through the `graphql` front controller, so its plugins
   (store header, cache tags, ...) run as usual. Then fire
   `controller_front_send_response_before` and send.
5. **Clean up, after the response is sent.** Roll back any DB transaction
   left open, abort any PHP session a request started, then run
   `Resetter::_resetState()` on every tracked object. The core
   `QueryParser` cache is emptied above 1000 parsed queries.
   This runs once `frankenphp_handle_request()` has returned. FrankenPHP
   holds the whole response until the handler returns, so cleanup inside
   the handler would add the reset and its `gc_collect_cycles()` calls to
   every request's time to first byte.
6. **Recycle check.** The worker exits cleanly (FrankenPHP starts a fresh
   one) on any of:
   - a throwable escaped,
   - a transaction was left open,
   - `MAGENX_WORKER_MAX_REQUESTS` (default 500) reached,
   - memory reached `MAGENX_WORKER_MAX_MEMORY_MB` (default 512),
   - the worker is older than `MAGENX_WORKER_MAX_AGE` seconds (default 1800),
   - `app/etc/env.php` changed,
   - the config cache was rebuilt or flushed (admin config save,
     `cache:clean config`, `cache:flush`): checked at most once a second by
     reading core's `system_scopes` config cache entry, which System config
     re-encrypts with a fresh nonce on every rebuild.

   Request and age limits get +-20% jitter per worker, so workers that
   booted together do not all recycle and reboot at the same moment.

   Each boot and each exit is logged to Magento's `var/log/system.log`
   (`notice`), so restarts counted by `frankenphp_worker_restarts` can be
   told apart:

   ```text
   magenx-app-server: worker 1/9f3c2a1b booted in 612 ms, memory 38.2 MB (peak 40.1 MB), opcache 41210 scripts (130987 max), 301/512 MB, interned 38/64 MB, hit 99.2%, restarts oom 0 hash 0
   magenx-app-server: worker 1/9f3c2a1b recycling (max-age) after 87/463 requests, 1712/1712 s, memory 61.4 MB (peak 80.3 MB) (limit 512 MB), opcache ...
   magenx-app-server: worker 1/4d07e6c5 stopped by FrankenPHP (idle 62 s) after 12/541 requests, 140/1930 s, memory 52.0 MB (peak 70.9 MB) (limit 512 MB), opcache ...
   ```

   `worker <pid>/<id>`: all worker threads share the FrankenPHP pid (1 in
   the container), the random id pairs a boot with its exit.

   Reasons: `error`, `open-transaction`, `reset-failed`, `max-requests`,
   `max-memory`, `max-age`, `env-changed`, `config-changed`. `stopped by FrankenPHP` means
   FrankenPHP stopped the thread between requests; it does not tell PHP why:
   - idle about `GRAPHQL_MAX_IDLE_TIME` or more: autoscale downscale. With
     `GRAPHQL_MAX_THREADS` above `GRAPHQL_WORKERS`, FrankenPHP adds a worker
     thread (a fresh Magento boot) when requests queue, and stops it again
     once idle that long (FrankenPHP default 5s; this Caddyfile 60s);
   - otherwise: shutdown, Caddy config reload, or admin API
     `workers/restart`.

   FrankenPHP logs which one at INFO in the container log (`upscaling
   worker thread`, `downscaling thread`, `rebooting all PHP threads`,
   `workers restarted from admin api`):

   ```bash
   docker logs frankenphp 2>&1 | grep -E 'upscaling|downscaling|rebooting|restarted|stopped'
   ```

   ```bash
   grep 'magenx-app-server: worker' var/log/system.log | grep -oE 'booted|recycling \([^)]*\)|stopped by FrankenPHP' | sort | uniq -c
   ```

The core factory scheme is unchanged for PHP-FPM: only the worker builds
the custom `ObjectManagerFactory`. Enabling the module adds no DI, plugins
or observers.

## Install

```bash
composer require magenxcommerce/module-graph-ql-app-server   # or copy to app/code/Magenx/AppServer
bin/magento module:enable Magenx_AppServer
bin/magento setup:upgrade && bin/magento setup:di:compile
```

Run the worker container from `frankenphp/` against the same code base and
`app/etc/env.php` as PHP-FPM. Requirements:

- ZTS PHP 8.3/8.4. The official `dunglas/frankenphp` images are ZTS.
- Production mode only: compiled DI (`setup:di:compile`) is required, and the worker
  refuses to start without it. There is no developer-mode support.
- `MAGENTO_ROOT` set.

The worker file path in the `Caddyfile` assumes a composer install. For an
`app/code` install, set `GRAPHQL_WORKER_FILE=app/code/Magenx/AppServer/bin/graphql-worker.php`.

### magenx docker stack

For [magenx/Magento-2-docker-configuration](https://github.com/magenx/Magento-2-docker-configuration)
(`/opt/BRAND/docker`, release symlinks, hardened images, UID 65532):

```bash
cd /opt/BRAND/docker
cp -r /opt/BRAND/magento/public/current/vendor/magenxcommerce/module-graph-ql-app-server/frankenphp ./frankenphp
bash frankenphp/install.sh          # nginx templates + includes, .env block (idempotent)
vim .env                            # pin FRANKENPHP_RUNTIME_IMAGE (dhi.io/debian:13) by digest
docker compose -f docker-compose.yml -f frankenphp/docker-compose.frankenphp.yml up -d --build frankenphp
docker compose up -d --build nginx
```

What the image does:

- **Build:** in `dunglas/frankenphp` (ZTS PHP 8.4), with the same extensions
  as the php service, plus phpredis built with igbinary + lz4 through PIE, as
  your php image does. The stack's `dhi.io/php` images are NTS, and FrankenPHP
  embeds PHP and needs ZTS.
- **Runtime:** the binary, extensions and their shared libraries are copied
  onto `dhi.io/debian:13`: no shell, no package manager.
- **Container:** runs as `DHI_STACK_UID`, root filesystem read-only,
  `cap_drop: ALL`, `no-new-privileges`, tmpfs for Caddy's `/config`, `/data`
  and `/tmp`. The image strips FrankenPHP's `cap_net_bind_service` file
  capability: with `cap_drop: ALL`, exec would fail with "operation not
  permitted". The worker listens on unprivileged 8081.
- **Ports:** 8081 (worker, for nginx) and 2020 (read-only `/metrics`, for
  other containers) are `expose`d to the docker networks only, with no host
  port. The Caddy admin API, which can rewrite the whole config and restart
  workers, binds to `127.0.0.1:2019` inside the container and is never
  exposed.
- **Mounts:** the same code mounts as the php service, read-only.
  `shared/var` is writable for logs and tmp.
- **Environment:** the same env as php, because `app/etc/env.php` reads its
  credentials with `getenv()`. Verified: the worker sees them at boot and per
  request.
- **Healthcheck:** a real `storeConfig` query through the worker
  (busybox `wget`).

nginx (`nginx/conf_m2/graphql_worker*.conf.template`, included by `install.sh`):

- **Routing:** `location = /graphql` proxies a canary share (default 10%) of
  anonymous requests to `frankenphp:8081`.
- **Fallback:** requests with a customer token, and anything the worker
  refuses (503) or cannot serve (502/504), are internally rewritten to
  `/index.php` and served by php-fpm. That is the same path
  `location / { try_files … /index.php }` takes today: `REQUEST_URI` stays
  `/graphql` and the buffered body is replayed.
- **Startup:** the upstream uses `resolve` (nginx ≥ 1.27.3; the stack runs
  1.31.5), so nginx starts even when the frankenphp container is stopped or
  missing. A static upstream would stop nginx from starting and take the
  whole site down.
- **Client IP:** `X-Real-IP` carries the client IP after `real_ip`. The worker
  adopts it as `REMOTE_ADDR` (`MAGENX_WORKER_TRUST_X_REAL_IP=1`), so the
  maintenance allowlist and logs match php-fpm.

## Rollout: read-only canary via nginx

The worker serves **anonymous, read-only catalog/CMS queries only**.
Customer, cart, checkout and every mutation stay on PHP-FPM.

nginx cannot see the operation inside a POST body, so the worker enforces
this itself (`Worker/ReadOnlyScope.php`). A request with an
`Authorization` header, a mutation, or a root field outside the allowlist
gets `503` + `X-Magenx-Worker: out-of-scope` before Magento runs, and nginx
replays it on PHP-FPM.

Two tested configurations, depending on which nginx proxies `/graphql`:

- **Storefront nginx** (`deploy/nginx/vhost.conf` in
  [magenxcommerce/magenxcommerce](https://github.com/magenxcommerce/magenxcommerce),
  the `nginx:8080` server the Next.js container calls). `/graphql` goes to the
  worker first (`upstream graphql_worker` in `deploy/nginx/maps.conf`).
  Customer-token requests skip it. Refusals (503) and outages (502/504)
  are replayed on Magento via `error_page … = @graphql_fpm`.
- **Magento nginx** on the magenx stack:
  `frankenphp/nginx/conf_m2/*.template`, installed by `install.sh` (see
  "magenx docker stack").

Do not use an upstream with a `backup` server and
`proxy_next_upstream http_503` for this. That replay path was never
verified, and in a real deployment the worker's 503 reached the storefront.

nginx 1.31.5+ can also read `operationName` from the body
(`client_body_early_read` + `json_set`) to skip the worker for known
non-read operations. That only saves the refusal round trip:
`operationName` is client-chosen, so the worker's own check stays the
boundary.

Default allowlist (`ReadOnlyScope::DEFAULT_ROOT_FIELDS`):

- core: `products`, `categories`, `categoryList`, `storeConfig`,
  `availableStores`, `cmsPage`, `cmsBlocks`, `route`, `urlResolver`,
  `countries`, `country`, `currency`, `customAttributeMetadataV2`,
  `customAttributeMetadata`, `productReviewRatingsMetadata`;
- Magenx: `bestSellers`, `blog*`, `deals`, `garanNotice`,
  `gdprCookieGroups`, `quickSearchSuggestions`, `storePickupLocations`.

Override it with `MAGENX_WORKER_ALLOWED_FIELDS` (comma separated). Add a
field only after checking that its resolvers keep no per-customer state.

Bodies over `MAGENX_WORKER_MAX_BODY_KB` (default 512) are refused before
they are decoded or parsed, and replayed on PHP-FPM. Caddy rejects anything
over 2 MB outright.

Add `$upstream_addr` and `$graphql_backend` to the `graphql_timing` log
format to compare latency between backends and see how often requests are
replayed.

## Deploys and config changes

Workers hold system config, store config and DI config in memory.

- **Deploy** (`setup:upgrade`, `setup:di:compile`, static deploy, new code):
  restart the workers after the switch. On the magenx stack, the release
  switch moves `public/current`; restart the container so the worker boots
  from the new release. nginx serves `/graphql` from php-fpm meanwhile.

  ```bash
  docker compose -f docker-compose.yml -f frankenphp/docker-compose.frankenphp.yml restart frankenphp
  # or, same release, workers only (admin API on loopback inside the container):
  docker compose exec frankenphp /bin/busybox wget -q -O - --post-data '' http://127.0.0.1:2019/frankenphp/workers/restart
  ```

- **Load:** Prometheus metrics are served read-only on
  `http://frankenphp:${FRANKENPHP_METRICS_PORT:-2020}/metrics` to the other
  containers (point the Magento platform module there), and on the loopback
  admin API. Every other path on the metrics port returns 404. A
  `frankenphp_worker_queue_depth` above 0 means requests are waiting for a
  free worker; raise `GRAPHQL_WORKERS` (and `GRAPHQL_MAX_THREADS`) if it
  stays there. Frequent `upscaling worker thread` / `downscaling thread`
  lines mean bursts regularly exceed `GRAPHQL_WORKERS`: raise it, or raise
  `GRAPHQL_MAX_IDLE_TIME` to keep added threads booted longer. Every thread
  up to `GRAPHQL_MAX_THREADS` may hold a booted Magento, so size
  `FRANKENPHP_MEM_LIMIT` for that.

  ```bash
  docker compose exec frankenphp /bin/busybox wget -qO- http://127.0.0.1:2020/metrics \
    | grep -E 'frankenphp_(worker_queue_depth|busy_workers|total_workers)'
  # from the php container, as the platform module does:
  docker compose exec php php -r 'echo file_get_contents("http://frankenphp:2020/metrics");' | grep frankenphp_
  ```

- **Admin config save, `cache:clean config` or `cache:flush`:** each worker
  recycles after its next request (checked at most once a second). With the
  `config` cache type disabled there is nothing to watch: workers then see
  the change after at most `MAGENX_WORKER_MAX_AGE` seconds, or right away
  after the restart call above. Other admin edits held in memory (anything
  not in the config cache) are bounded by `MAGENX_WORKER_MAX_AGE`.
- **`env.php` change:** each worker recycles after its next request.

## Checking worker mode and opcache

Worker mode: `frankenphp_total_workers` counts booted worker threads, and every
boot logs a `booted in N ms` line with its own id.

```bash
docker compose exec frankenphp /bin/busybox wget -qO- http://127.0.0.1:2020/metrics \
  | grep -E 'frankenphp_(total_workers|busy_workers|ready_workers|worker_restarts|opcache_restarts)'
grep 'magenx-app-server: worker' var/log/system.log | tail
```

Two `booted` lines minutes apart for the same thread, with no recycle in
between, mean FrankenPHP is not keeping the worker (check the container log).

Opcache: all worker threads share one opcache. Every boot and exit line ends
with its fill (`opcache N scripts (max), used/total MB, interned, hit rate,
restarts`). It should rise over the first boots and then stay flat, with
the hit rate near 100% and no restarts. `FULL` at the end of the line, or a
rising `frankenphp_opcache_restarts` (FrankenPHP 1.13+, PHP 8.4+), means
`opcache.memory_consumption`, `opcache.interned_strings_buffer` or
`opcache.max_accelerated_files` in `zz-worker.ini` is too small, and every
boot then recompiles. `opcache off` means the extension is not loaded:
check `FRANKENPHP_EXTENSION` contains `opcache`.

At boot each worker runs `MAGENX_WORKER_WARMUP_QUERY` (default
`{storeConfig{store_code}}`, `off` to skip) once through the front
controller, so the first real request does not pay for autoloading, config
and schema loading. The response is discarded and state is reset after it.

GraphQL sessions: core default `graphql/session/disable=0` lets GraphQL
start PHP sessions. The worker drops cookies and aborts any session a
request opens, but turn sessions off anyway (the storefront uses tokens):

```bash
bin/magento config:set graphql/session/disable 1
```

A worker logs a warning at boot while it is on.

## Verification gate (before any real traffic)

1. **State test with your modules enabled.** Run core
   `dev/tests/integration/testsuite/Magento/GraphQl/App/GraphQlStateTest.php`
   (plus `GraphQlCheckoutMutationsStateTest` and
   `GraphQlCustomerMutationsTest`) and this module's companion state tests
   (below) with every Magenx module and StripeIntegration enabled. Every
   object that changes state across requests is a potential cross-customer
   leak. Fix it with `_resetState()` in the owning module, or with an
   `etc/reset.json` entry for code you cannot change.
2. **Shadow comparison.** Replay real queries (guest, customer token,
   cart, checkout) against both backends in interleaved order and diff the
   responses.
3. **Load test.** Compare p50/p95 with FPM, and watch memory per worker
   and recycle frequency.

### Companion state tests

`Test/Integration` runs every GraphQL operation of the Magenx companion
modules the way core `GraphQlStateTest` runs core ones: each operation twice
in one application, with the core `Comparator` failing on any shared object
that changes state across the two requests or drifts from its constructed
state. It covers what the worker refuses today, so a green run is the
precondition for serving customer tokens and mutations:

| Test | Operations |
|---|---|
| `CompanionCatalogStateTest` | public reads: best sellers, blog, deals, GARAN, GDPR cookie groups, quick search, pickup locations, help desk and RMA metadata, every `ProductInterface` extension |
| `CompanionCustomerStateTest` | private reads (reward points, product alerts, Stripe subscriptions, GDPR requests and export, help desk tickets, returns, order fields) and every non-cart mutation |
| `CompanionCartStateTest` | cart fields (GARAN, Stripe, reward, pickup) and cart mutations (reward points, pickup location) |
| `CompanionSchemaCoverageTest` | fails when a companion resolver has no case, a case no longer matches the schema, or a core state skip/filter list names a `Magenx\` class |

Private cases run customer A, then customer B or a guest. A's data is seeded
with the marker `STATE-LEAK-A`, and the second response must not contain it.
Each pair also runs entirely under the core `Resetter`, with every module's
`reset.json` applied, as in the worker.

The cases live in `Test/Integration/Cases/CompanionCases.php`; add one there
for every new query, mutation or field (the coverage test names what is
missing). Never add a companion class to a core state skip list to get green:
that hides the leak from the test, not from the worker.

Run from the Magento root, against an integration test database
(`dev/tests/integration/etc/install-config-mysql.php`) with all companion
modules installed. Add a suite to `dev/tests/integration/phpunit.xml`:

```xml
<testsuite name="Magenx companion state">
    <directory>../../../vendor/magenxcommerce/module-graph-ql-app-server/Test/Integration</directory>
</testsuite>
```

```bash
cd dev/tests/integration
../../../vendor/bin/phpunit -c phpunit.xml --testsuite "Magenx companion state"
```

Cases of a disabled module are skipped. For the gate, no case of a module
you run may be skipped.

CI runs all of this in `.github/workflows/graphql-state.yml` (pull requests,
nightly, and on demand): a fresh Mage-OS install on PHP 8.4 ZTS (the
FrankenPHP build), MariaDB and OpenSearch services, the latest Stripe
release, and every `magenxcommerce/module-*` repository, then the companion
and core state suites. Private module repos need the `MAGENX_MODULES_TOKEN`
secret (read access to the organisation's repositories); without it their
cases are skipped. A manual run takes a Mage-OS version and a branch name to
test across the module repos, e.g. a fix branch before it is merged. `phpunit.xml.dist` in this module only runs
`Test/Unit`, which checks the case catalogue without Magento.

`reset.json` format: `{"Vendor\\Class": {"property": <reset value>}}`.
Do not ship an empty `{}`: the core Resetter rejects it. This module's own
`etc/reset.json` clears the core `TransportBuilder`, so recipients from a
failed send don't carry into the next email.

It also resets per-request state in the Stripe Payments helpers. Run
`php vendor/magenxcommerce/module-graph-ql-app-server/bin/check-reset-json.php` from the
Magento root after installing or upgrading Stripe; it checks every entry
against the installed classes.

[STATE_AUDIT.md](STATE_AUDIT.md) lists the state findings in our own
modules, ranked. The "must fix" items block worker traffic.

## Known gaps

- **Not tested end to end with Magento.** What was tested:
  - The factory and resetter wiring, smoke-tested against the 2.4.8
    framework sources.
  - The worker image, built and run with the compose hardening (read-only
    root, `cap_drop: ALL`, UID 65532) on a stand-in `debian:trixie-slim`
    runtime, since `dhi.io` needs a login. The build used a stub worker,
    plus sandbox-only substitutions for the CA and PIE/PECL downloads.
  - The routing, behind nginx 1.31.6 and php-fpm 8.4:
    - anonymous POST and GET go to the worker;
    - mutations and cart queries are refused and replayed on php-fpm with
      the body intact;
    - token requests go straight to php-fpm;
    - with the worker stopped, requests fall back (+0.3 s while Docker DNS
      is stale) and come back once it is up;
    - nginx starts with no worker container at all.
  - The stack's own nginx templates with the includes pass `nginx -t`, and
    the compose override merges cleanly with the stack's `docker-compose.yml`.

  A run against a real Magento store is still to do.
- **Plugins on `Magento\Framework\App\Http`** do not run. In core that is
  only `NewRelicReporting`, and the New Relic agent does not support ZTS
  anyway.
- **Plain static properties** and `register_shutdown_function()` callbacks
  registered per request are not reset. Recycling bounds them.
- **`exit()` / `die()`** in any module ends the worker thread mid-request.
- **`$_FILES`** is not mapped. Core GraphQL has no multipart uploads.
- **`MAGE_PROFILER=2`** is not supported: it wraps the factory in a
  decorator, and the worker refuses to start.
- **One request per thread.** Never enable coroutine-style concurrency:
  Magento's singletons are not safe for it.
