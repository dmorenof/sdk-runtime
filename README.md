# SDK Runtime

[![PHP package CI](https://github.com/dmorenof/sdk-runtime/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/dmorenof/sdk-runtime/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

The shared engine behind every SDK made by [sdk-generator](https://github.com/dmorenof/sdk-generator). A generated SDK holds only what is specific to its API (models, operations, names); sending requests, authentication, validation, errors, retries and streaming live here, written and tested once. PHP 8.4+.

The runtime holds no global state: each client owns its transport and credentials.

## Why use it

Measured on a real contract (Casafari, 46 operations) against OpenAPI Generator 7.26, on PHP 8.4:

| | This runtime + generator | OpenAPI Generator |
| --- | ---: | ---: |
| Generated code | **1.10 MB** | 4.74 MB |
| Everything installed | **3.96 MB** | 6.2 MB |
| Peak memory, a call that builds nested models | **2.5 MB** | 10 MB |
| Time of a call | **0.74×** (flat) to 1.01× (nested) | |
| Starting the client | **0.57–0.96×** the time | |

And less to write: OAuth2 tokens are fetched and renewed for you, models are plain typed properties (no getters and setters), and retries, redirects and proxies are one `Configuration`. It needs PHP 8.4 and is a deliberate subset of OpenAPI. The honest limits, the method and how to repeat the measurement are in [Why this runtime](docs/why.md).

## In thirty seconds

```php
use Acme\Accounts\{Configuration, Users};
use Acme\Accounts\DTOs\NewUser;

Users::configure(new Configuration());   // an SDK generated from the API's specification

$new = new NewUser();
$new->name = 'Ada';

$user = Users::post($new);               // typed, validated model
echo $user->id;
```

## Documentation

| I want to… | Read |
| --- | --- |
| See what it costs and what it saves, measured | [Why this runtime](docs/why.md) |
| Make my first call | [Getting started](docs/getting-started.md) |
| Work with models, parameters and errors | [Using an SDK](docs/using-an-sdk.md) |
| Pass parameters, upload files, send cookies | [Sending data](docs/sending-data.md) |
| Authenticate (API key, bearer, basic, OAuth2) | [Authentication](docs/authentication.md) |
| Set timeouts, retries, redirects, proxies, servers | [Transport and servers](docs/transport.md) |
| Read headers, files, streams and error bodies | [Responses](docs/responses.md) |
| Understand validation and formats | [Validation](docs/validation.md) |
| Write or change a generator | [Operation metadata](docs/operation-metadata.md) |
| Look up a class | [API reference](docs/api-reference.md) |
| Know what may change between versions | [Public API and versioning](docs/PUBLIC_API.md) |

The full index, with a one-minute model of how the pieces fit, is [docs/README.md](docs/README.md). Notes on features as they were introduced in versions 0.4 to 0.31 are kept in [docs/archive](docs/archive/0.x-release-notes.md).

## Compatibility

From 1.0.0 the package follows Semantic Versioning. What is stable, what is internal and what counts as a breaking change is defined in [docs/PUBLIC_API.md](docs/PUBLIC_API.md).

## Development

Use PHP 8.4+ and Composer 2:

```sh
composer install
bash ci/run-tests.sh
```

Source code belongs in `src/` under `Dmorenof\SdkRuntime\`; tests belong in `tests/`.

## GitHub Flow

Develop on a branch and open a PR. A version is published only when its work is finished and proven and the owner confirms (changing `VERSION` on `main` publishes); versions are not published piecemeal. See [AGENTS.md](AGENTS.md), [CONTRIBUTING.md](CONTRIBUTING.md) and [RELEASING.md](RELEASING.md).

## License

MIT; see [LICENSE](LICENSE).
