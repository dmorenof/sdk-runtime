# sdk-runtime documentation

The runtime is the shared engine behind every SDK made by [sdk-generator](https://github.com/dmorenof/sdk-generator). A generated SDK only contains what is specific to its API (models, operations, names). Everything else lives here: sending requests, authentication, validation, errors, retries, streaming.

Pick your starting point.

| You are… | Read |
| --- | --- |
| Using a generated SDK | [Getting started](getting-started.md), then [Using an SDK](using-an-sdk.md) |
| Authenticating | [Authentication](authentication.md) |
| Tuning HTTP behavior (timeouts, retries, proxies, servers) | [Transport and servers](transport.md) |
| Reading responses (errors, headers, files, streams) | [Responses](responses.md) |
| Looking up validation rules and formats | [Validation](validation.md) |
| Writing or changing a generator | [Operation metadata](operation-metadata.md) |
| Looking for a class or method | [API reference](api-reference.md) |
| Wondering what may change between versions | [Public API and versioning](PUBLIC_API.md) |

## The model in one minute

```
generated SDK                       sdk-runtime
─────────────                       ───────────
Client  ── extends ──────────────►  ApiClient        sends operations
Resources\Users ── extends Client   (one per route group)
DTOs\User ── extends ────────────►  DTO              typed, validated models
Collections\Tags ── extends ─────►  TypedArray       typed lists
Configuration ── extends ────────►  Configuration    connection options
```

- A **client** owns its transport, credentials and server choice. There is no global state inside the runtime.
- A **resource** is a client scoped to one group of routes. `$client->resource(Users::class)` shares the client's transport and credentials.
- An **operation** is a small array that describes one HTTP call (method, path, types, rules). Generated resources hold one per route; the runtime executes it. You never write these by hand unless you write a generator: see [Operation metadata](operation-metadata.md).

## Conventions in these docs

- Examples use a generated SDK called `Acme\Accounts`. Replace it with your own.
- “Throws X” always means the exception class listed in [Errors](using-an-sdk.md#errors).
- Everything marked *opt-in* is off unless you enable it, so upgrading never changes behavior silently.
