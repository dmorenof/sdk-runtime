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
