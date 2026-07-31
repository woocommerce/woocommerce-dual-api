# Feasibility analysis: extracting the dual API into a separate extension

Assessment of moving the WooCommerce dual API (`docs/apis/dual-api/`) out of core into its own plugin, in two flavors:

- **Flavor A**: a WooCommerce extension following the WooCommerce Fraud Protection (WFP) model — root `Automattic\WooCommerce` namespace, core directory conventions, designed to merge back into core.
- **Flavor B**: a plain WordPress extension with no WooCommerce dependency.

Compatibility breakage is explicitly out of scope (the feature is experimental), but the analysis notes where each flavor breaks existing consumers anyway, since the cost difference between the flavors is largely *about* that.

## TL;DR

Both flavors are feasible. The infrastructure's coupling to WooCommerce core is deliberately thin — one bootstrap line in `includes/`, one feature-flag declaration, four `wc_get_container()` call sites, one settings class, and a handful of `woocommerce_`-prefixed names. The genuinely WooCommerce-specific code (`WC_Product`/`WC_Coupon` usage) is confined to the proof-of-concept code API, which is cleanly separable from the infrastructure.

- **Flavor A** is low-cost and low-risk: existing consumer plugins keep working *unmodified* (their entire contract is the `Automattic\WooCommerce\Api\*` namespace plus a `method_exists()` guard), the DI container keeps working, and the merge-back path is preserved. The "extension of an extension of an extension" chain is real but manageable, and has precedent in the Woo ecosystem.
- **Flavor B** is architecturally cleaner (the infrastructure is, in fact, almost WordPress-generic already) but costs a one-time rename of every namespace — breaking all consumer source and generated trees — plus small rewrites of the settings UI, the class-resolution default, and the cache-cleanup scheduling. It also forfeits the cheap merge-back-to-core path.

Recommendation at the end: Flavor A now, engineered so that Flavor B remains possible later.

## 1. What would move: current footprint

| Bucket | Files | Size | Notes |
| --- | --- | --- | --- |
| `src/Api/` (infrastructure ≈ 42 files, PoC ≈ 47 files) | 89 | 600K | Public surface; namespace `Automattic\WooCommerce\Api` |
| `src/Internal/Api/` (8 hand-written + 50 generated) | 58 | 348K | Runtime internals + committed generated tree |
| `bin/api-builder/` | 16 | 220K | `ApiBuilder.php` alone is ~135K; 12 code templates |
| `docs/apis/dual-api/` | 17 | 120K | |
| Tests (`tests/php/src/Api/` + `tests/php/src/Internal/Api/`) | 148 | 952K | Includes the DummyApi fixture and its generated tree |
| `lib/packages/GraphQL/` (vendored webonyx v15.32.1) | 228 | 1.7M | Mozart-renamed to `Automattic\WooCommerce\Vendor\GraphQL`, committed |

Total excluding the vendored engine: ~330 files, ~2.2 MB. Everything lives in self-contained directories; nothing is interleaved with unrelated core code.

## 2. What binds the dual API to WooCommerce today

### Hard couplings (infrastructure)

| Coupling | Where | Extraction impact |
| --- | --- | --- |
| Bootstrap call `Main::register()` | `includes/class-woocommerce.php:437` (the **only** touch point in `includes/`) | Moves to the plugin's own bootstrap |
| Feature flag `dual_code_graphql_api` | `Main::is_enabled()` via `FeaturesUtil`; declared in `FeaturesController.php:637` (hidden, experimental, off by default) | Replaced by plugin activation (see below) |
| `wc_get_container()` | `Main.php:180,196,223` (resolves `Settings`, the controller, `QueryCache`) and `ClassResolver.php:25` (default command resolution) | Works unchanged in Flavor A; needs a replacement default in Flavor B |
| Settings UI | `src/Internal/Api/Settings.php`: `woocommerce_get_sections_advanced` / `woocommerce_get_settings_advanced` filters + `WC_Admin_Settings::add_error()` | Works unchanged in Flavor A; rewrite as a WP Settings API screen in Flavor B |
| `wc_string_to_bool()` | `Main.php:107–137` | Trivial to inline |
| Action Scheduler | `OpcacheFileExpiry.php` (`as_schedule_single_action()` etc., all `function_exists()`-guarded; action `woocommerce_graphql_opcache_cleanup`) | Guaranteed present in Flavor A; needs a WP-Cron fallback in Flavor B |
| Build-time `phpcbf` | `ApiBuilder` defaults to core's `vendor/bin/phpcbf`, standard `WordPress-Core` | Ships with (or is required by) the new repo either way |
| Composer wiring | `autoload.files` → `Schema/aliases.php`; `autoload-dev` → `bin/api-builder/`; the `Automattic\WooCommerce\Vendor\` mapping to `lib/packages/` | Recreated in the plugin's own `composer.json` |

Notably absent: no service provider (the container autowires by reflection), no `LegacyProxy`, no dependency on anything else in `includes/`. `RuntimeContainer` resolves **any** class under the `Automattic\WooCommerce\` prefix regardless of which autoloader provides it (`RuntimeContainer.php:219–221`) — this is the exact mechanism WFP relies on, and why it sets a WC ≥ 9.5 floor (older containers required explicit registration).

### Naming couplings (soft — renames, not rewrites)

- Eight `woocommerce_graphql_*` options (endpoint URL, GET toggle, depth/complexity limits, three cache toggles, TTL).
- Four `woocommerce_graphql_*` filters (introspection, debug mode, metadata, OPcache dir).
- Default REST route `wc/graphql`; consumer routes typically registered under the `wc` namespace.
- Object-cache group `wc-graphql`; uploads directory `wc-graphql-cache`.
- Hardcoded `'woocommerce'` text domain emitted by the builder templates into **all** generated code — including third-party plugins' generated trees (`ApiBuilder.php:2535`). This is a pre-existing bug worth fixing during extraction regardless of flavor.

### WordPress-level (not WooCommerce) dependencies

These stay in either flavor: `WP_REST_Request`/`WP_REST_Response`, `register_rest_route()`, `wp_get_current_user()`, WP capabilities (`Principal` checks `manage_woocommerce` for introspection and `manage_options` for debug mode), the WP object cache, `posts_where` (pagination cursor filter), the uploads directory.

### The PoC code API — the only truly WooCommerce part

All `WC_Coupon` / `WC_Product` / `wc_get_product()` / `wc_price()` usage is confined to `Queries/`, `Mutations/`, `Types/`, `InputTypes/`, `Enums/`, `Interfaces/`, `Scalars/` and `Utils/{Coupons,Products}` under `src/Api/` (≈ 47 files, ~110K). It never touches `wc_get_container()` and uses `WP_Query` rather than WC data stores for listing. It detaches from the infrastructure cleanly.

This confirms the intuition behind the question: **strip the PoC, and nothing in the remaining infrastructure is conceptually WooCommerce** — the couplings above are wiring and naming, not domain logic.

### The consumer contract (what a plugin like `woocommerce-simple-events` actually depends on)

- **Runtime**: one static method — `Automattic\WooCommerce\Api\Infrastructure\Main::register_graphql_endpoint()` — guarded by `method_exists()`. No `WC_VERSION` check, no `class_exists( 'WooCommerce' )`, anywhere.
- **Committed source**: imports of `Api\Attributes\*`, `Api\ApiException` and subclasses, `Api\Pagination\*`.
- **Committed generated tree**: `Api\Infrastructure\GraphQLControllerBase`, `Api\Infrastructure\Schema\*` (the engine-decoupling surface), `ResolverHelpers`, `QueryInfoExtractor`, `MetadataController`. Never `Vendor\GraphQL\*`, never `Internal\Api\*` — by design.
- **Build time**: a *dev-mode WooCommerce monorepo checkout* (`WC_PATH` + core's `vendor/autoload.php`), because `ApiBuilder` is only reachable through core's `autoload-dev` and `bin/` is excluded from release builds.

Consequence: **any provider that exposes the same namespace satisfies existing consumers transparently**. The namespace *is* the contract. That single fact is what makes Flavor A cheap and Flavor B's rename the dominant cost.

## 3. Flavor A: WooCommerce extension (the WFP model)

### Shape

Following WFP (`automattic/woocommerce-fraud-protection`):

```text
woocommerce-dual-api/
├── woocommerce-dual-api.php          ← headers, kill switches, one require
├── bin/api-builder/                  ← moved wholesale
├── lib/packages/GraphQL/             ← vendored engine + Mozart config
├── src/Api/                          ← same namespace: Automattic\WooCommerce\Api
├── src/Internal/Api/                 ← same namespace: Automattic\WooCommerce\Internal\Api
└── tests/php/...
```

`composer.json` maps `"Automattic\\WooCommerce\\": "src/"` exactly as WFP does. Collision avoidance is by FQCN, not prefix: two autoloaders may both register the `Automattic\WooCommerce\` prefix and fall through when a file isn't in their own `src/`. Unlike WFP (which needed the `FraudProtectionPlugin` suffix because core had shipped the same subnamespace), the dual API needs **no rename at all** — provided the extension only runs against core versions that no longer ship the classes.

Bootstrap follows WFP's proven pattern: hook `woocommerce_loaded`, verify `WC_VERSION` against a floor, only then `require` the plugin's autoloader and call `Main::register()`. Headers: `Requires Plugins: woocommerce`, `Requires PHP: 8.1`.

### What keeps working unchanged

- **All consumer plugins, verbatim.** The `method_exists()` guard finds `Main` whether core or the extension provides it. Committed generated trees keep resolving. Even better than today: when the extension is inactive, the class simply doesn't exist, so consumers no-op cleanly — the same behavior the feature flag produces now.
- **The DI container.** `wc_get_container()->get()` resolves the extension's classes because they're under the allowed prefix. `ClassResolver`'s default keeps working for consumers too.
- **The settings section** (the `woocommerce_get_*_advanced` filters work from any plugin), all option and filter names, Action Scheduler, the engine vendoring (`Automattic\WooCommerce\Vendor\GraphQL` prefix moves as-is).
- **The engine-decoupling guarantee** carries over untouched.

### What changes

- **Feature flag → plugin activation.** Drop `dual_code_graphql_api`; `Main::is_enabled()` reduces to the PHP 8.1 check. This matches WFP's philosophy (no `FeaturesUtil`; presence is the gate) and is arguably *more* correct: activation is visible, per-site, and admin-controlled, where the hidden flag required a `wp option update`. A `WC_DUAL_API_DISABLED` kill switch (WFP-style) can substitute for the emergency-off role.
- **Core removal PR** (small): the one line at `class-woocommerce.php:437`, the flag block at `FeaturesController.php:637`, the composer entries, the phpcs/phpstan/phpunit config blocks, `.github/workflows/api-staleness.yml`, `.distignore` lines, and a docs pointer. Two already-stale leftovers go with it (see §6).
- **Overlap window handling.** WC 10.9+ ships the classes; the extension must not load its tree alongside them (duplicate FQCNs → whichever autoloader registered first wins, nondeterministically under the Jetpack autoloader). Simplest: extraction and core removal land in the same release cycle, and the extension enforces `WC_VERSION >=` that release as a hard floor, bailing gracefully below it (WFP does exactly this, and even pins the overlap WC version in its CI matrix to prove no conflict).
- **Builder distribution decision.** Today's biggest consumer-DX pain — needing a full dev-mode monorepo checkout to build — can be fixed in passing: ship `bin/api-builder/` in the extension's release build (it's 220K), or publish it as a `require-dev` Composer package. `WC_PATH` becomes "path to the installed dual-API plugin" at worst. `phpcbf` stays a dev dependency (`--no-linter` already exists as the escape hatch).
- **Text domain**: the extension's own strings move to its own domain, and the builder should emit the *consumer's* text domain instead of the hardcoded `'woocommerce'`.
- **CI/tooling migration**: the staleness workflow, the `wc-phpunit-graphql` suite, phpcs exclusions and PHPStan paths move to the new repo. The test harness can reuse core's, WFP-style (bootstrap against a `WC_DIR` checkout, core's `TestingContainer`/`MockableLegacyProxy`).
- **A structural simplification for free**: all the PHP 7.4/8.0 gymnastics — the `wc-phpunit-graphql` suite split, the bootstrap "skipped GraphQL tests" announcement, the `LegacyPhpApi` shim test, the deferred flag check in `Main::register()` — exist only because core must load on PHP 7.4. A plugin with `Requires PHP: 8.1` deletes all of it.

### The "extension → extension → extension" chain, assessed

The chain (third-party plugin → dual-API extension → WooCommerce) is the flavor's main perceived risk. Broken down:

- **Runtime ordering**: a non-issue. Registration happens on `plugins_loaded`/`woocommerce_loaded`/`rest_api_init`, all of which fire after every plugin's autoloader is registered. The documented `method_exists()` guard pattern remains valid verbatim.
- **Dependency declaration**: WordPress 6.5+ `Requires Plugins` is non-transitive, so a consumer declares both: `Requires Plugins: woocommerce, woocommerce-dual-api`. Mildly awkward, standard practice.
- **Version matrix**: consumers now track two release streams (extension + WC) instead of one. Mitigated by how tiny the contract is (one static method plus stable attribute/`Schema\*` surfaces, all `method_exists()`-guarded) and by the extension owning its cadence — infrastructure fixes no longer wait for the WC release train (that cuts both ways: it's also one more thing for site owners to keep updated).
- **Precedent**: the Woo ecosystem already runs layered extension stacks (extensions of WooCommerce Subscriptions, of payment gateways, of Product Add-Ons). WFP itself is consumed by downstream gateway integrations. This is a known, workable pattern — not novel risk.

### Merge-back story

Near-perfect, and better than WFP's own position: namespaces are identical from day one (no `FraudProtectionPlugin`-style rename debt), directory layout mirrors core, phpcs/DI conventions carry over, and consumers are unaffected in *both* directions — extraction and re-absorption — because their guard is `method_exists()`, not "is WooCommerce providing this". On merge-back the extension does the WFP dance in reverse: detect a core version that ships the API and bail.

### What's lost in Flavor A

Essentially nothing functional. The changes are: flag semantics become activation semantics; a WC version floor appears; distribution moves from "bundled with WooCommerce" to "install another plugin" (a real adoption cost for an experiment trying to attract users, and its main strategic downside).

## 4. Flavor B: WordPress extension

### The premise holds

The question "what's left of WooCommerce in it?" has a concrete answer after §2: **wiring and names, plus the PoC**. The auth model is already WordPress-generic (WP users, WP capabilities), the transport is WP REST, the caching is WP object cache + uploads dir + OPcache. Nothing in the infrastructure's *logic* needs WooCommerce.

### Complete replacement list

1. **Namespace rename** — `Automattic\WooCommerce\Api\*` → e.g. `Automattic\DualApi\*`, including `Internal\Api\*`, the `Vendor\GraphQL` prefix, the builder templates, and the docs. This is the dominant cost: it breaks every consumer's committed source (attribute imports) *and* generated tree, and requires all of them to regenerate. Permitted (experimental), but it's a one-time, ecosystem-wide break — versus zero in Flavor A.
2. **Feature flag** → plugin activation (same as Flavor A; here it's forced, since `FeaturesUtil` is gone).
3. **Class resolution / DI** — the default `ClassResolver` becomes plain `new $class()` (commands are conventionally stateless; core's own PoC commands never use the container), and `Main`'s three internal `wc_get_container()` calls become direct instantiation or a micro-factory. The convention-class override mechanism already exists, so WooCommerce-ecosystem consumers that want container autowiring ship a ~10-line `ClassResolver` — `simple-events` ships one today.
4. **Settings UI** — rewrite `Settings.php` against the WP Settings API (own settings screen; `WC_Admin_Settings::add_error()` → `add_settings_error()`). One file, mechanical.
5. **Scheduling** — Action Scheduler calls get a `wp_schedule_single_event()` fallback. (Worth doing anyway: today, if AS is somehow absent, OPcache cleanup silently never schedules.)
6. **Helpers and defaults** — inline `wc_string_to_bool()`; `Principal::can_introspect()` gates on `manage_options` instead of `manage_woocommerce`; drop or relocate the `RequiresManageWoocommerce` trait.
7. **Renames** — options, filters, route default, cache group, uploads dir, text domain.

Items 2–7 are small. Item 1 is the flavor's price tag.

### What's genuinely lost

- **The merge-back-to-core path.** Re-absorbing a `Automattic\DualApi\*` tree into WooCommerce later means the full rename in reverse, breaking consumers again. Flavor B effectively forecloses cheap merge-back.
- **Free container autowiring** for Woo-ecosystem consumers (small, see item 3).
- **The WooCommerce settings surface, release train, QA and distribution** — the experiment loses the "already installed on every Woo site, one flag away" property (shared with Flavor A, but here there's also no Woo-branded home for it).
- **The WooCommerce PoC code API cannot live in the plugin.** It's WooCommerce domain code inside a now-WordPress-generic framework. Its destinations: (a) a *second*, thin WooCommerce extension ("WooCommerce GraphQL API") consuming the framework exactly as `simple-events` does — the architecturally honest option; (b) back into core as a consumer — not shippable, core can't depend on a plugin; or (c) dropped. Option (a) is attractive but means maintaining two plugins.

### What's gained

- **Honest layering.** The triple chain dissolves: a consumer depends on the framework and on WooCommerce as two *independent* peers, and WooCommerce's own code API becomes just another consumer. The "extension using an extension that uses an extension" oddity disappears by construction.
- **A wider audience**: any WordPress plugin can build a dual API; nothing about the value proposition (code-first, in-process PHP API + generated, engine-decoupled GraphQL) is commerce-specific.
- Same PHP 8.1 simplifications as Flavor A.

### A strategic caveat

A generic "GraphQL for WordPress" plugin lands next to WPGraphQL, a mature ecosystem project. The dual API's differentiator is real — WPGraphQL is schema-registration-based and GraphQL-only, while this is code-first with an in-process PHP API and committed, engine-decoupled generated code — but Flavor B turns an internal experiment into a public framework product with a positioning and maintenance-ownership question attached. That's an organizational decision, not a technical one; it just shouldn't be made implicitly by choosing a repo layout.

## 5. Work items common to both flavors

- New repo scaffolding: composer/PSR-4, Mozart config + vendored engine, phpcs (`WooCommerce-Core` ruleset in Flavor A), PHPStan, release packaging (`.distignore` equivalent), changelog tooling.
- Port the staleness check + CI workflow; port the test suites and the DummyApi fixture; decide test harness (reuse core's via `WC_DIR` like WFP, or standalone).
- Builder distribution (release-bundled vs. Composer package) and the text-domain parameterization.
- Core removal PR + docs relocation (leave a pointer under `docs/apis/`).
- Transition messaging for the experimental cohort: flag → activation migration (the options can keep their names in Flavor A, so settings survive).

## 6. Incidental findings (worth fixing regardless of any extraction)

- `plugins/woocommerce/package.json` lint-staged entry for `src/Api/**/*.php` points at `src/Internal/Api/DesignTime/Scripts/check-api-staleness.php`, which no longer exists (the script lives at `bin/api-builder/check-api-staleness.php`). The hook is silently broken.
- `plugins/woocommerce/composer.json` `autoload.exclude-from-classmap` still lists the removed `src/Internal/Api/DesignTime` directory.
- The builder hardcodes the `'woocommerce'` text domain into generated code, including third-party plugins' trees (`ApiBuilder.php:2535`).
- `woocommerce-simple-events` `.gitignore`s its `src/Internal/Api/Autogenerated/` tree, contradicting both its own README and the core docs' "commit the generated tree" instruction — as the canonical reference, it currently demonstrates the wrong practice.

## 7. Verdict

**Feasible in both flavors; the code was evidently built with these seams in mind.** The infrastructure/PoC split is clean, the consumer contract is one namespace and one static method, and core's side of the coupling is a handful of lines.

**Recommended: Flavor A.** It is mostly mechanical (repo scaffold + file moves + bootstrap + CI), breaks no consumer, keeps the merge-back option that motivated the WFP model in the first place, and resolves the user-facing questions (flag → activation, monorepo-checkout builds → self-contained builds) as side effects. The three-plugin chain is the only structural cost, and it is a solved problem in the ecosystem.

**Flavor B is viable but should be a deliberate product decision**, not an extraction default: its technical delta over Flavor A is modest (rename + settings/DI/scheduler rewrites), but it forfeits merge-back, forces the PoC into a second plugin, and commits Woo to maintaining a WordPress-generic framework in WPGraphQL-adjacent territory.

The pragmatic path: **extract as Flavor A now, and while doing so, land the changes that shrink WooCommerce coupling anyway** (text-domain parameterization, WP-Cron fallback for cleanup, keeping `wc_get_container()` usage confined behind `ClassResolver`/`Main`). That keeps Flavor B a cheap future pivot — the only remaining cost would be the rename — instead of a fork in the road today.
