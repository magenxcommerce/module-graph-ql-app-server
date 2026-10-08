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
2. **Scope check.** Anything but an anonymous, read-only, allowlisted query
   gets 503 before Magento runs, and nginx replays it on PHP-FPM (see
   Rollout).
3. **Maintenance check.** Answers 503 while `var/.maintenance.flag` exists.
4. **Dispatch** through the `graphql` front controller, so its plugins
   (store header, cache tags, ...) run as usual. Then fire
   `controller_front_send_response_before` and send.
5. **Clean up, after the response is sent.** Roll back any DB transaction
   left open, then run `Resetter::_resetState()` on every tracked object.
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
   - the worker is older than `MAGENX_WORKER_MAX_AGE` seconds (default 300),
   - `app/etc/env.php` changed.

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
  docker compose exec frankenphp /bin/busybox wget -q -O - --post-data '' http://localhost:2019/frankenphp/workers/restart
  ```

- **Admin config save or `cache:flush`:** workers see the change after at
  most `MAGENX_WORKER_MAX_AGE` seconds, or right away after the restart
  call above.
- **`env.php` change:** each worker recycles after its next request.

## Verification gate (before any real traffic)

1. **State test with your modules enabled.** Run core
   `dev/tests/integration/testsuite/Magento/GraphQl/App/GraphQlStateTest.php`
   (plus `GraphQlCheckoutMutationsStateTest` and
   `GraphQlCustomerMutationsTest`) with every Magenx module and
   StripeIntegration enabled. Add cases for each module's own queries and
   mutations. Every object that changes state across requests is a
   potential cross-customer leak. Fix it with `_resetState()` in the owning
   module, or with an `etc/reset.json` entry for code you cannot change.
2. **Shadow comparison.** Replay real queries (guest, customer token,
   cart, checkout) against both backends in interleaved order and diff the
   responses.
3. **Load test.** Compare p50/p95 with FPM, and watch memory per worker
   and recycle frequency.

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
