# Settings and caching

The engine is configured under **WooCommerce → Settings → Advanced → GraphQL**. The section appears while the WooCommerce Dual API plugin is active.

These settings are **site-wide, not per-endpoint**: every setting below applies to *every* dual-API endpoint on the site. See [Scope: what applies where](#scope-what-applies-where).

## Settings

| Setting | Option name (`Main::` constant) | Type | Default | Effect |
| --- | --- | --- | --- | --- |
| Allow anonymous requests | `woocommerce_graphql_anonymous_requests_allowed` (`OPTION_ANONYMOUS_REQUESTS_ALLOWED`) | checkbox | `yes` | When off, a request whose principal reports itself as unauthenticated gets 401 `UNAUTHORIZED` before the query is parsed, which also makes operations marked `#[PublicAccess]` unreachable. See [Refusing requests up front](./authentication-and-authorization.md#refusing-requests-up-front). |
| Enable GET endpoint | `woocommerce_graphql_get_endpoint_enabled` (`OPTION_GET_ENDPOINT_ENABLED`) | checkbox | `yes` | When off, endpoints accept POST only; GET returns 404. Mutations are always rejected over GET. |
| Maximum query depth | `woocommerce_graphql_max_query_depth` (`OPTION_MAX_QUERY_DEPTH`) | number | `15` | Rejects queries nested deeper than this during validation. Falls back to default when unset or non-positive. |
| Maximum query complexity | `woocommerce_graphql_max_query_complexity` (`OPTION_MAX_QUERY_COMPLEXITY`) | number | `1000` | Rejects queries whose computed complexity score exceeds this. Connection fields multiply child cost by page size. |
| Parsed query cache TTL | `woocommerce_graphql_query_cache_ttl` (`OPTION_QUERY_CACHE_TTL`) | number | `86400` | Seconds before cached parsed queries expire (object cache and APQ paths). |
| Enable OPcache-based caching | `woocommerce_graphql_opcache_enabled` (`OPTION_OPCACHE_ENABLED`) | checkbox | `yes` | Cache parsed ASTs as PHP files served from OPcache shared memory. |
| Enable ObjectCache-based caching | `woocommerce_graphql_object_cache_enabled` (`OPTION_OBJECT_CACHE_ENABLED`) | checkbox | `yes` | Cache parsed ASTs in the WP object cache. |
| Enable APQ caching | `woocommerce_graphql_apq_enabled` (`OPTION_APQ_ENABLED`) | checkbox | `yes` | Support the Apollo Automatic Persisted Queries protocol (`persistedQuery` extension). When off, hash-only requests are rejected. |

Endpoint URLs are not a setting: each plugin chooses the route of its endpoint when it registers it (see [Creating a dual API in a plugin](./creating-a-dual-api-in-a-plugin.md#3-register-the-endpoint)).

The depth and complexity metrics are observable on a request by appending `?_debug=1` (when the principal may use debug mode); the response carries `extensions.debug.depth` and `extensions.debug.complexity`.

## Scope: what applies where

The engine has one set of switches and filters shared by every endpoint on the site, there is no per-plugin configuration surface. Concretely:

- **The WooCommerce Dual API plugin being active gates every dual-API endpoint.** When it is inactive, `Main` doesn't exist and no endpoint is registered (which is why plugins guard their `Main::register_graphql_endpoint()` call with `method_exists()`).
- **Every setting applies to all endpoints.** The anonymous-requests toggle, the GET toggle, max depth, max complexity, the three caching toggles, and the cache TTL are read from the shared engine, so every endpoint honours them (for example, all endpoints reject GET when the GET toggle is off).
- **The filters below are global.** A callback added to any of them affects *every* dual-API endpoint on the site. Each filter receives the `\WP_REST_Request`, so a callback that should apply to only one endpoint must branch on the request's route itself.

## Query caching

Parsing a GraphQL query into an AST is the expensive, repeatable step, so the engine caches parsed ASTs. On each request the resolution chain is:

1. **OPcache file backend**: when its toggle is on, the OPcache extension is loaded, and the cache directory is writable. Parsed ASTs are written as `return [...];` PHP files under `wp-content/uploads/wc-graphql-cache/v<engine-version>/`; OPcache serves them as compiled bytecode (no string parse, no `unserialize`, no remote cache call).
2. **WP object cache**: otherwise, when its toggle is on.
3. **No cache**: parse on every request.

Notes:

- The cache key/version is tied to the query string and the parser version, so there's no correctness TTL concern on the file backend; the configurable TTL applies to the object-cache and APQ paths.
- OPcache writes are atomic (temp file + `rename()`), drop a deny-all `.htaccess`, and pre-warm the bytecode. Expired files are cleaned up via a scheduled `woocommerce_graphql_opcache_cleanup` action.
- APQ always uses the object cache for hash-only lookups, regardless of the standard-query toggles, preserving persisted-query semantics.

### Persistence bounds

Caching happens right after parsing, before the query is validated against the schema and before any resolver authorizes the caller. Every syntactically valid query that reaches an endpoint (including anonymous requests, schema-invalid queries and queries the caller isn't allowed to run) would therefore persist a cache entry, so three bounds keep the footprint finite:

- **Maximum cacheable query size** (`QueryCache::DEFAULT_MAX_CACHEABLE_QUERY_BYTES`, 16 KB): a longer query is parsed and served on every request but never persisted, on any backend. An APQ registration of such a query succeeds for that request, but the hash isn't retained: the next hash-only request gets `PERSISTED_QUERY_NOT_FOUND` and the client falls back to sending the full query.
- **Maximum number of OPcache files** (`QueryCache::DEFAULT_MAX_OPCACHE_FILES`, 1000): once the cache directory holds this many AST files, queries that aren't cached yet are parsed on every request and not written, until the TTL cleanup frees room.
- **Maximum total size of the OPcache files** (`QueryCache::DEFAULT_MAX_OPCACHE_BYTES`, 32 MB): a query whose file would push the directory past this size isn't written either. This is what actually bounds disk usage and OPcache shared memory, since the exported AST of a query is 30 to 230 times the query's size (a 16 KB query made of two-character fields becomes a 2.7 MB file, and OPcache keeps a compiled copy about 1.4 times that size).

Refreshing a file that already exists is always allowed. The directory is measured without a lock, so concurrent cache misses can overshoot the limits by a few files. All three are limits on persistence, not on what the endpoint accepts: a query beyond them still runs. They're filterable (see below); setting one to `0` removes it. The object cache has no count or size bound of its own beyond the cacheable query size, its eviction policy and the TTL.

## Relevant filters

| Filter | Signature | Purpose |
| --- | --- | --- |
| `woocommerce_graphql_opcache_cache_dir` | `( string $dir )` | Override the OPcache file directory (default `{uploads}/wc-graphql-cache/v<n>`). Empty strings and stream wrappers are rejected. |
| `woocommerce_graphql_max_cacheable_query_bytes` | `( int $max_bytes )` | Maximum length of a query string whose parsed AST is persisted, on any backend (default 16384). `0` removes the limit. See [Persistence bounds](#persistence-bounds). |
| `woocommerce_graphql_opcache_max_files` | `( int $max_files )` | Maximum number of AST files kept in the OPcache directory (default 1000). `0` removes the limit. |
| `woocommerce_graphql_opcache_max_bytes` | `( int $max_bytes )` | Maximum total size of the AST files kept in the OPcache directory (default 32 MB). `0` removes the limit. |
| `woocommerce_graphql_request_allowed` | `( bool, object $principal, \WP_REST_Request )` | Decide whether a request is processed at all, before the query is parsed. See [Refusing requests up front](./authentication-and-authorization.md#refusing-requests-up-front). |
| `woocommerce_graphql_can_introspect` | `( bool, ?object $principal, \WP_REST_Request )` | Gate native introspection. See [Authentication and authorization](./authentication-and-authorization.md). |
| `woocommerce_graphql_can_use_debug_mode` | `( bool, ?object $principal, \WP_REST_Request )` | Gate debug mode. |
| `woocommerce_graphql_can_query_metadata` | `( bool, ?object $principal, \WP_REST_Request )` | Gate `_apiMetadata`. See [Metadata](./metadata.md). |

## Customizing the response HTTP status

A plugin can override the HTTP status of any response (for example, always return 200) by shipping an `HttpStatusResolver` convention class. Without one, the engine's per-error-code mapping applies. See [Creating a dual API in a plugin](./creating-a-dual-api-in-a-plugin.md) and [Infrastructure classes](./reference/infrastructure-classes.md).
