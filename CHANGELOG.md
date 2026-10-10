# Changelog

## [1.1.0](https://github.com/magenxcommerce/module-graph-ql-app-server/compare/v1.0.3...v1.1.0) (2026-10-10)


### Features

* config-change recycling, boot warm-up, opcache stats; drop cookies and cap bodies ([14dd41c](https://github.com/magenxcommerce/module-graph-ql-app-server/commit/14dd41c4a369938217c0a1abe97ec6ce5b2aed5f))


### Bug Fixes

* Add config change detection, warmup query, and opcache monitoring ([#9](https://github.com/magenxcommerce/module-graph-ql-app-server/issues/9)) ([14dd41c](https://github.com/magenxcommerce/module-graph-ql-app-server/commit/14dd41c4a369938217c0a1abe97ec6ce5b2aed5f))

## [1.0.3](https://github.com/magenxcommerce/module-graph-ql-app-server/compare/v1.0.2...v1.0.3) (2026-10-09)


### Bug Fixes

* Expose read-only Prometheus metrics on separate port ([#7](https://github.com/magenxcommerce/module-graph-ql-app-server/issues/7)) ([bcd8ddd](https://github.com/magenxcommerce/module-graph-ql-app-server/commit/bcd8ddd1972fabaae1f68ab33c948ab0a3d0ce4a))
* serve Prometheus metrics on a separate read-only port ([bcd8ddd](https://github.com/magenxcommerce/module-graph-ql-app-server/commit/bcd8ddd1972fabaae1f68ab33c948ab0a3d0ce4a))

## [1.0.2](https://github.com/magenxcommerce/module-graph-ql-app-server/compare/v1.0.1...v1.0.2) (2026-10-08)


### Bug Fixes

* Add worker thread identification and idle time tracking ([#5](https://github.com/magenxcommerce/module-graph-ql-app-server/issues/5)) ([dc94c28](https://github.com/magenxcommerce/module-graph-ql-app-server/commit/dc94c28e21609c13e0ca077c437fc82b1d4e448d))
* log why FrankenPHP stopped a worker; keep autoscaled threads 60s ([dc94c28](https://github.com/magenxcommerce/module-graph-ql-app-server/commit/dc94c28e21609c13e0ca077c437fc82b1d4e448d))

## [1.0.1](https://github.com/magenxcommerce/module-graph-ql-app-server/compare/v1.0.0...v1.0.1) (2026-10-08)


### Bug Fixes

* bind the Caddy admin API to 127.0.0.1; log worker boots, recycles and stops ([#3](https://github.com/magenxcommerce/module-graph-ql-app-server/issues/3)) ([4181683](https://github.com/magenxcommerce/module-graph-ql-app-server/commit/4181683603406d89cdf59abd1bf7dffd6f79d297))

## 1.0.0 (2026-10-08)


### Features

* GraphQL application server worker for FrankenPHP ([#1](https://github.com/magenxcommerce/module-graph-ql-app-server/issues/1)) ([de9c5db](https://github.com/magenxcommerce/module-graph-ql-app-server/commit/de9c5dbe3802a7a03f0e30496bcab50555a11f78))
