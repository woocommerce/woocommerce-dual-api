# WooCommerce Dual API

The **dual API** is a code-first API architecture: you write plain PHP classes (the **code API**), and a build script generates a fully functional **GraphQL API** that mirrors them. The two are kept in sync from a single, manually maintained source (the code API) so there is one place to add behavior and two ways to consume it (in-process PHP calls and GraphQL-over-HTTP).

The engine that makes this possible (the build tooling, the attributes, the authorization model, the request pipeline) is provided by the **WooCommerce Dual API plugin**. A plugin that wants its own dual API defines its code API, runs the builder against it, and registers a GraphQL endpoint through the engine.

## Status: experimental

> **This feature is experimental.** Everything under the `Automattic\WooCommerce\Api` namespace can change in backwards-incompatible ways, or be removed, in any release. Do not use it in production extensions.

The dual API was introduced as an experimental feature of WooCommerce 10.9, together with a proof-of-concept API for products and coupons built on it. Starting with WooCommerce 11.2 the engine lives in its own plugin, and the proof-of-concept API is gone: the engine is what remains, and it is only ever used by the code APIs that plugins define.

## Requirements

- **WooCommerce 11.2 or newer.**
- **The WooCommerce Dual API plugin (this repository)**, installed and active. There is no feature flag: activating the plugin enables the engine, and a code-API plugin can declare the dependency with `Requires Plugins: woocommerce, woocommerce-dual-api`.
- **PHP 8.1+.** The code API uses enums, named arguments, and PHP 8 attributes. The plugin does not load on older PHP versions.

The plugin's settings and filters are site-wide and shared across all dual-API endpoints (see [Settings and caching](./caching-and-settings.md#scope-what-applies-where)).

### Older WooCommerce versions

WooCommerce 10.9 to 11.1 ship the engine inside core, gated by the hidden `dual_code_graphql_api` feature flag (`wp option update woocommerce_feature_dual_code_graphql_api_enabled yes`). The plugin stays dormant on those versions and the flag remains the switch there. These docs describe the plugin-based setup; the concepts are the same.

## Which document do I need?

| Your question | Start here |
| --- | --- |
| What is this and how does it fit together? | [Architecture](./architecture.md) |
| How do I build my own dual API in a plugin? | [Creating a dual API in a plugin](./creating-a-dual-api-in-a-plugin.md) |
| How do I write queries, mutations and types? | [Writing the code API](./writing-the-code-api.md) |
| How do I paginate a list query? | [Relay-style pagination](./pagination.md) |
| How does authentication and authorization work? | [Authentication and authorization](./authentication-and-authorization.md) |
| How do I attach and query schema metadata? | [Metadata and discovery](./metadata.md) |
| How do I configure the endpoints and caching? | [Settings and caching](./caching-and-settings.md) |
| How do I regenerate the GraphQL code, and what is the staleness check? | [Building and staleness checks](./building-and-staleness.md) |
| The engine or the builder is missing something, how do I change it safely? | [Extending the infrastructure](./extending-the-infrastructure.md) |

Reference material (lookup tables, exact signatures):

- [Recognized directories](./reference/directories.md)
- [Attributes](./reference/attributes.md)
- [Recognized methods and parameters](./reference/recognized-methods-and-parameters.md)
- [Infrastructure classes](./reference/infrastructure-classes.md)
- [Exceptions](./reference/exceptions.md)

## Audience

The primary audience for this documentation is **developers building their own dual API in a plugin**. The secondary audience is **maintainers of the dual-API engine** itself.

## A working example

The [`woocommerce-simple-events`](https://github.com/woocommerce/woocommerce-simple-events) plugin is a runnable reference that exercises the engine end to end: custom authentication, custom authorization attributes, granular field-level gates, pagination, scalars, and more. These docs use it for their examples and link to it for complete, copy-pasteable files.
