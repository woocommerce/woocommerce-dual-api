# Re-vendored dependencies

`lib/packages/` holds the GraphQL engine ([webonyx/graphql-php](https://github.com/webonyx/graphql-php)) re-namespaced to `Automattic\WooCommerce\Vendor\GraphQL\*` with [Mozart](https://github.com/coenjacobs/mozart), so that it can't collide with another copy of the library loaded by a different plugin. The re-namespaced code is committed; nothing under `lib/` is installed with Composer at runtime.

The plugin's `composer.json` maps the `Automattic\WooCommerce\Vendor\` prefix to `lib/packages/`. Only the engine-decoupling surface under `src/Api/Infrastructure/Schema/` may reference the vendored namespace in public signatures; see `src/Api/Infrastructure/Schema/README.md`.

## Updating the engine

1. Bump the `webonyx/graphql-php` constraint in `lib/composer.json`.
2. From the repository root, with the development dependencies installed (`composer install`), run `composer lib:update`. This runs `composer update` inside `lib/`, whose post-update script runs `mozart compose` to rewrite the package into `lib/packages/`.
3. Mozart rewrites namespace declarations and `use` statements but can miss stringified FQCNs (class names embedded in string literals). Audit the rebuilt package for any string that still references the bare `GraphQL\` namespace:

    ```sh
    grep -rn "'GraphQL\\\\\\|\"GraphQL\\\\" lib/packages/GraphQL/
    ```

    The grep should return no results. If it does, patch the offending file manually and commit it.

4. Regenerate the test fixture (`composer build:api:test`) and run the test suite.
5. Commit `lib/composer.json`, `lib/composer.lock` and `lib/packages/` together.
