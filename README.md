# SDK Runtime

[![PHP package CI](https://github.com/dmorenof/sdk-runtime/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/dmorenof/sdk-runtime/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

Shared PHP 8.3+ runtime for SDKs produced by [sdk-generator](https://github.com/dmorenof/sdk-generator). Generated SDKs contain only their API-specific code (DTOs, requests, responses, enums and resources) and depend on this package for everything else, so the infrastructure is written, tested and fixed once.

The runtime holds no global state: each client owns its transport and credentials.

## Contents

| Namespace | Purpose |
| --- | --- |
| `Dmorenof\SdkRuntime\ApiClient` | `connect()`, `resource()`, `withMiddleware()` and operation execution. SDKs extend it and declare `SECURITY_SCHEMES`. |
| `DTO`, `Request`, `Response` | Typed hydration, validation and directional (`readOnly`/`writeOnly`) serialization. |
| `TypedArray`, `Map`, `AnyOf`, `OneOf` | Typed collections, keyed maps and composition wrappers. |
| `Security\*` | Bearer, Basic and API-key authentication with AND/OR requirements. Credentials are redacted from debug output. |
| `Transport\*` | `TransportInterface`, the PSR-18 `Psr18Transport` (Guzzle by default) and `HttpMiddlewareInterface`. |
| `ApiException` | Unexpected HTTP status with the raw response and, when decodable, the typed error. |

## Use from a generated SDK

~~~php
$client = Client::connect(
    'https://api.example.com',
    securityCredentials: new SecurityCredentials(['HTTPBearer' => $token]),
);
~~~

## Development

Use PHP 8.3+ and Composer 2:

~~~sh
composer install
bash ci/run-tests.sh
~~~

Source code belongs in `src/` under `Dmorenof\SdkRuntime\`; tests belong in `tests/`.

## GitHub Flow

Create short-lived `feature/*`, `fix/*`, or `chore/*` branches from `main`, open a pull request and wait for CI. See [CONTRIBUTING.md](CONTRIBUTING.md) and [RELEASING.md](RELEASING.md).

## License

MIT; see [LICENSE](LICENSE).

## Polymorphic models (v0.4.0)

Version 0.4.0 supplies the discriminator and original-schema evaluation support
used by SDK Generator 0.3.0. Generated packages require `dmorenof/sdk-runtime: ^0.4.0`.
See [CHANGELOG.md](CHANGELOG.md) for the coordinated release and compatibility notes.

Generated discriminator metadata is consumed by the shared `Hydrator` for nested
properties, collection items, maps and operation responses. `Pet::fromValue()`
also dispatches; PHP constructors cannot replace `new Pet(...)` with a subclass.
Inherited discriminator metadata does not redirect construction of a concrete
subtype. Unknown/missing tags fail explicitly, and subtype validation rejects a
tag that disagrees with its PHP class, including after mutation.

Composition wrappers may declare original branch descriptors and local schema
definitions. These are evaluated against an independent JSON snapshot before
hydration: the discriminator never reduces the number of valid `oneOf` branches.
A single selected mutable variant supplies outbound JSON, with cardinality and
discriminator checked again before serialization. Multiple `anyOf` variants remain
alternative views of the original snapshot; editing several views is not a payload
merge API. Legacy wrappers without descriptors retain their hydration-based matching.
This is a bounded evaluator for generated descriptors, not full JSON Schema support.

## Operation servers (P0 development)

The `feature/p0-contract-fidelity` branch is coordinated with
[Generator PR #20](https://github.com/dmorenof/sdk-generator/pull/20), without a
separate release. Operations may declare a literal absolute HTTP(S) `serverUrl`;
root operations continue to use the configured client `baseUrl`. A declared
server path prefix is preserved when adding the operation path.

For other environments, pass `serverOverrides` to `Client::connect()` or the
constructor, mapping a declared server URL to a replacement base URL. The map is
copied to resources together with the transport and credentials; separate clients
keep separate maps. Keys ignore trailing slashes. Relative/template server URLs,
credentials, queries and fragments in operation server URLs remain unsupported
and fail before transport. Existing operations without `serverUrl` keep their
current routing.
