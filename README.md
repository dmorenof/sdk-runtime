# SDK Runtime

[![PHP package CI](https://github.com/dmorenof/sdk-runtime/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/dmorenof/sdk-runtime/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

The shared engine behind every SDK made by [sdk-generator](https://github.com/dmorenof/sdk-generator). A generated SDK holds only what is specific to its API (models, operations, names); sending requests, authentication, validation, errors, retries and streaming live here, written and tested once. PHP 8.3+.

The runtime holds no global state: each client owns its transport and credentials.

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
| Make my first call | [Getting started](docs/getting-started.md) |
| Work with models, parameters and errors | [Using an SDK](docs/using-an-sdk.md) |
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

Use PHP 8.3+ and Composer 2:

```sh
composer install
bash ci/run-tests.sh
```

Source code belongs in `src/` under `Dmorenof\SdkRuntime\`; tests belong in `tests/`.

## GitHub Flow

Publish each completed, validated increment through `main`. Use a PR only when required by repository protections and complete publication in the same task. See [AGENTS.md](AGENTS.md), [CONTRIBUTING.md](CONTRIBUTING.md) and [RELEASING.md](RELEASING.md).

## License

MIT; see [LICENSE](LICENSE).
