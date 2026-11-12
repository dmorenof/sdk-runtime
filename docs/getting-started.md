# Getting started

## Requirements and install

PHP 8.3 or newer. A generated SDK already requires this package; install the SDK and Composer brings the runtime with it.

```bash
composer require acme/accounts-sdk
```

Until the packages are on Packagist, declare the GitHub repositories in your project (Composer ignores repositories declared by dependencies):

```json
{
  "repositories": [
    { "type": "vcs", "url": "https://github.com/dmorenof/sdk-runtime", "no-api": true },
    { "type": "vcs", "url": "https://github.com/your-org/accounts-sdk", "no-api": true }
  ]
}
```

## Your first call

A generated SDK offers two equivalent ways to call it. Use whichever reads better in your code.

### 1. Static facade (configure once, call anywhere)

```php
use Acme\Accounts\{Configuration, Users};
use Acme\Accounts\DTOs\NewUser;

Users::configure(new Configuration());      // uses the base URL the API declares

$new = new NewUser();
$new->name = 'Ada';

$user = Users::post($new);                   // returns Acme\Accounts\DTOs\User
echo $user->id, ' ', $user->name;

$same = Users::getById($user->id);
```

`configure()` stores one shared connection for the whole process. It suits scripts and small apps.

### 2. Explicit client (no shared state)

```php
use Acme\Accounts\Client;
use Acme\Accounts\Resources\Users;

$client = Client::connect('https://api.example.com/v1');
$users  = $client->resource(Users::class);

$user = $users->getById(7);
```

Prefer this in services, tests and long-running workers: each client has its own transport, credentials and server choice, so two clients never interfere.

## Changing the connection

`Configuration` is a plain object. Set only what you need:

```php
use Acme\Accounts\Configuration;
use Dmorenof\SdkRuntime\Security\SecurityCredentials;

$config = new Configuration();
$config->baseUrl = 'https://staging.example.com/v1';       // default: what the API declares
$config->headers = ['X-Request-Source' => 'billing-job'];   // sent on every request
$config->securityCredentials = new SecurityCredentials(['ApiKeyAuth' => $key]);
$config->httpClient = new \GuzzleHttp\Client(['timeout' => 10]);

Users::configure($config);
```

| Property | Meaning |
| --- | --- |
| `baseUrl` | Where requests go. Required if the API declares none. |
| `headers` | Extra headers for every request. |
| `securityCredentials` | Secrets for the API's security schemes. See [Authentication](authentication.md). |
| `httpClient` | Any PSR-18 client. Default: Guzzle. Timeouts, proxies and TLS live here. |
| `transport` | Replace the whole transport (rare). See [Transport](transport.md). |
| `middleware` | Hooks around every request. See [Transport](transport.md#middleware). |
| `serverOverrides` | Map a declared server URL to another one (environments). |

## What can go wrong

| You see | It means |
| --- | --- |
| `Dmorenof\SdkRuntime\ApiException` | The API answered with a status the operation does not declare as success. `$e->response` has status, headers and body; `$e->payload` has the decoded error when the API documents one. |
| `UnexpectedValueException` | A request or response did not match the declared schema (missing required field, wrong type, rule broken). |
| `LogicException` | The facade was used before `configure()`. |
| A PSR-18 exception | The network failed. It is passed through untouched. |

Details and the full list: [Errors](using-an-sdk.md#errors).

## Next

[Using an SDK](using-an-sdk.md) covers models, parameters, errors and validation.
