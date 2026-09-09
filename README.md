# WooCommerce Dual API

The engine behind the WooCommerce **dual API**: a code-first API architecture where you write plain PHP classes (the *code API*) and a build script generates a fully functional GraphQL endpoint that mirrors them. This plugin provides the engine (the build tooling, the attributes, the authorization model, the request pipeline, the caches and their settings); plugins use it to define their own dual APIs.

> **Experimental.** Everything under the `Automattic\WooCommerce\Api` namespace can change in backwards-incompatible ways, or be removed, in any release. Do not use it in production extensions.

The documentation lives in [the GitHub pages site for this repository](https://woocommerce.github.io/woocommerce-dual-api/). Start with [Creating a dual API in a plugin](https://woocommerce.github.io/woocommerce-dual-api/creating-a-dual-api-in-a-plugin.html), and see [woocommerce-simple-events](https://github.com/woocommerce/woocommerce-simple-events) for a complete, runnable example.

## Requirements

- WooCommerce 11.2 or newer.
- PHP 8.1 or newer.

## Installation

Until a packaged release is available, install from source:

```sh
cd wp-content/plugins
git clone https://github.com/woocommerce/woocommerce-dual-api.git
cd woocommerce-dual-api
composer install --no-dev
```

Then activate **WooCommerce Dual API** in the Plugins screen. Activation is the only switch: there is no feature flag. The plugin registers no GraphQL endpoint of its own; endpoints come from the plugins that define a code API, through `Automattic\WooCommerce\Api\Infrastructure\Main::register_graphql_endpoint()`.

The engine's settings (anonymous-requests and GET endpoint toggles, query depth and complexity limits, query caches) are under **WooCommerce → Settings → Advanced → GraphQL** and apply to every dual-API endpoint on the site.

### Behavior on other WooCommerce versions

- **WooCommerce 10.9 to 11.1** ship the engine inside WooCommerce itself, behind the hidden `dual_code_graphql_api` feature flag. On those versions the plugin stays **dormant** (it must not load a second copy of the same classes) and shows an admin notice explaining that the flag is the switch there.
- **Older than 10.9**: the plugin stays inactive with an error notice.
- **PHP older than 8.1**: the plugin stays inactive with an error notice (the `Requires PHP` header normally prevents activation in the first place).

Defining `WC_DUAL_API_DISABLED` as `true` in `wp-config.php` makes the plugin do nothing at all.

### Extraction from WooCommerce core

The engine was introduced as part of WooCommerce 10.9, together with a proof-of-concept API for products and coupons built on it. WooCommerce 11.2 removed both from core; the engine moved here unchanged (same namespaces, same option, filter and hook names, so existing code APIs keep working) and the proof of concept API was dropped.

## Repository layout

```text
woocommerce-dual-api/
├── woocommerce-dual-api.php        # plugin header and kill switches; hands over to PluginLoader
├── bin/api-builder/                # ApiBuilder, build-api.php, check-api-staleness.php, code templates
├── docs/                           # the dual API documentation (also in GitHub Pages)
├── lib/packages/GraphQL/           # webonyx/graphql-php, re-namespaced with Mozart (see lib/README.md)
├── src/
│   ├── Api/                        # public surface: Attributes/, Infrastructure/, Pagination/, exceptions
│   └── Internal/Api/               # runtime internals: settings, query cache, endpoint registrar, PluginLoader
└── tests/                          # PHPUnit suite; see tests/php/src/Internal/Api/README.md
```

`bin/api-builder/` ships with the plugin on purpose: a plugin's build script requires this plugin's `vendor/autoload.php` and calls `ApiBuilder::run_for_plugin()`, so building works against an installed copy as well as against a clone of this repository.

## Development

```sh
composer install          # development dependencies (phpcs, PHPStan, PHPUnit, Mozart)
composer phpcs            # coding standards (WooCommerce-Core ruleset)
composer phpstan          # static analysis
composer build:api:test   # regenerate the test fixture's GraphQL layer after changing the fixture or the builder
composer build:api:check  # fail when the fixture's generated code is out of date
```

### Running the tests

The tests run against WooCommerce's own test framework, loaded from a WooCommerce checkout, and need the WordPress test library and a MySQL database:

```sh
# Once: install WordPress and its test library (arguments: db-name db-user db-pass [db-host]).
tests/bin/install-wp-tests.sh wordpress_test root root 127.0.0.1

# Point WC_DIR at a WooCommerce checkout's plugins/woocommerce directory (11.2 or newer, or a
# development build of it) with its Composer dependencies installed, then run the suite.
WC_DIR=/path/to/woocommerce/plugins/woocommerce composer test
```

`WC_DIR` may be omitted when the WooCommerce monorepo is checked out next to this repository (`../woocommerce`). The checkout must not ship the engine in core (see above), since the engine classes would otherwise be defined twice.

### Updating the vendored GraphQL engine

See [lib/README.md](lib/README.md).
