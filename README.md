# WooCommerce Dual API

WooCommerce extension providing the dual (code-first PHP + generated GraphQL) API engine, extracted from WooCommerce core.

This is "Flavor A" of the extraction analyzed in [DUAL_API_EXTRACTION.md](DUAL_API_EXTRACTION.md): the plugin keeps the `Automattic\WooCommerce\Api\*` and `Automattic\WooCommerce\Internal\Api\*` namespaces, so existing consumer plugins keep working unmodified and the merge-back-into-core path stays cheap.

## Bootstrap behavior

Everything is decided on `woocommerce_loaded` by `WC_Dual_API_Loader` (`includes/class-wc-dual-api-loader.php`). The engine-in-core detection is a capability probe — `class_exists( 'Automattic\WooCommerce\Api\Infrastructure\Main' )` evaluated *before* the plugin registers its own autoloader — rather than a version range check, so it is immune to plugin load-order variations (e.g. network-activated WooCommerce loading before site plugins) and keeps working if the engine is ever merged back into core.

| Environment | Result |
| --- | --- |
| WooCommerce without the in-core engine (11.2+) | Plugin registers its autoloader and boots the engine. Plugin activation is the on/off switch; there is no feature flag. |
| WooCommerce with the in-core engine (10.9–11.1) | **Dormant**: the plugin must not load its class tree next to core's identical FQCNs, so it stays inert. A notice explains that on these versions the API is controlled by core's feature flag (see below). |
| WooCommerce older than `WC_Dual_API_Loader::MINIMUM_WC_VERSION` | Inert, error notice. |
| WooCommerce missing (reachable only on WordPress < 6.5; `Requires Plugins` blocks it otherwise) | Inert, error notice. |
| PHP < 8.1 (reachable only if PHP was downgraded after activation; `Requires PHP` blocks it otherwise) | Inert, error notice. |

### The overlap window

On WooCommerce versions where core still owns the engine, the on/off switch remains core's hidden `dual_code_graphql_api` feature flag. The plugin deliberately never writes that flag — it only reads it to tailor the dormant notice:

- Flag enabled: an info notice on the Plugins screen states that the API is provided and controlled by WooCommerce itself.
- Flag disabled: a warning notice states that the feature must be enabled to use the API, e.g. `wp option update woocommerce_feature_dual_code_graphql_api_enabled yes`.

### Kill switch

`define( 'WC_DUAL_API_DISABLED', true );` in `wp-config.php` makes the plugin do nothing at all.

## Extraction status

Extraction source: `woocommerce/woocommerce` @ `7f118fa049c40043287d4a037f4ff18eb8520fbc` (trunk, 11.1.0-dev, everything under `plugins/woocommerce/`). Core-side API changes landing after that commit should be synced by diffing against this ref before the core removal PR.

- [x] Bootstrap (`woocommerce-dual-api.php`, `includes/class-wc-dual-api-loader.php`, `composer.json`)
- [ ] Move the infrastructure part of `src/Api/` and all of `src/Internal/Api/` from core. The PoC code API (`Queries/`, `Mutations/`, `Types/`, `InputTypes/`, `Enums/`, `Interfaces/`, `Scalars/`, `Utils/{Coupons,Products}`) does not move: it stays in core and disappears with the engine's removal
- [ ] Move the vendored GraphQL engine (`lib/packages/GraphQL/`) and its Mozart config
- [ ] Add `src/Api/Infrastructure/Schema/aliases.php` to `autoload.files` in `composer.json` — deliberately omitted while the tree is empty, so that a premature `composer install` doesn't produce an autoloader that fatals on require
- [ ] Adapt `Main` to activation semantics: drop the `FeaturesUtil` check, reduce `is_enabled()` to the PHP version check
- [ ] Move `bin/api-builder/`; decide its distribution (release-bundled vs Composer package); parameterize the emitted text domain (core hardcodes `'woocommerce'`)
- [ ] Port the infrastructure tests and the DummyApi fixture (the PoC tests don't move), phpcs/PHPStan config, and the staleness-check CI workflow; the test harness reuses core's, WFP-style (bootstrap against a WooCommerce checkout via `WC_DIR`)
- [ ] Core removal PR (targeting the first WooCommerce release without the engine): bootstrap line, feature flag declaration, composer entries, tooling config, docs pointer
