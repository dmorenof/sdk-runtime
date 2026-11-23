# SDK Runtime

[![PHP package CI](https://github.com/dmorenof/sdk-runtime/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/dmorenof/sdk-runtime/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

**The engine for typed PHP API clients, from an OpenAPI file or by hand.** Transport, authentication (API key, basic, bearer, OAuth2 with automatic renewal, OpenID Connect with ID-token validation, mutual TLS), typed models validated where you assign, errors, retries, redirects, streaming and timeouts live here, written and tested once; an SDK holds only what is specific to its API. It is the base of every SDK made by [sdk-generator](https://github.com/dmorenof/sdk-generator), and you can [write your own client on it](docs/writing-an-sdk-by-hand.md) for an API that has no specification. PHP 8.4+.

A client holds its own transport and credentials, and nothing is shared between clients.

## Why use it

Measured against OpenAPI Generator 7.26 on PHP 8.4, with the SDK each generator makes from the same published contract, a clean process per measurement and the same real answer served to both:

| | This runtime + generator | OpenAPI Generator |
| --- | ---: | ---: |
| **PokeAPI** (102 operations): code + dependencies on disk | **3.6 MB** | 6.7 MB |
| PokeAPI: one Pokémon (350 KB of JSON), time per call | **0.47×** (4.2 ms) | 8.8 ms |
| PokeAPI: peak memory of that call | **0.68×** (9.8 MB) | 14.4 MB |
| **met.no** (Swagger, 12 operations): time per call | **0.34×–0.37×** | |
| **Casafari** (46 operations): code + dependencies on disk | **4.0 MB** | 6.2 MB |
| Casafari: peak memory, nested models | **2.5 MB** | 10 MB |
| Memory growth over thousands of calls | **4 KiB** | 163–3,130 KiB |

And less to write: OAuth2 tokens are fetched and renewed for you, models are plain typed properties (no getters and setters), and retries, redirects, timeouts and proxies are one `Configuration`.

Where it is **not** better, said plainly: on a very small API the runtime's own weight dominates (met.no installs 2.7 MB against 1.9 MB), the first call in a fresh process is about 1.4× slower, and it needs PHP 8.4. The method, every table and how to repeat the measurements are in [Why this runtime](docs/why.md).

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
| Write a client for an API with no specification | [Writing an API client by hand](docs/writing-an-sdk-by-hand.md) |
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
