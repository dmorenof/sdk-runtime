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

A generated SDK offers two equivalent ways to call it. Use whichever reads better in your code. (Both use a class called `Users` in this example: the one in `Acme\Accounts` is the static facade, the one in `Acme\Accounts\Resources` is the instance you get from a client.)

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
| `retryPolicy`, `redirectPolicy` | Opt-in retries and redirect following. See [Transport](transport.md). |
| `server`, `serverVariables` | Which declared server operations use, and values for templated servers. |
| `serverOverrides` | Map a declared server URL to another one (environments). |

A client takes a snapshot of its configuration when you connect and never changes afterwards.

## What can go wrong

| You see | It means |
| --- | --- |
| `Dmorenof\SdkRuntime\ApiException` | The API answered with a status the operation does not declare as success. `$e->response` has status, headers and body; `$e->payload` has the decoded error when the API documents one. |
| `UnexpectedValueException` | A request or response did not match the declared schema (missing required field, wrong type, rule broken). |
| `LogicException` | The facade was used before `configure()`, or `middleware`/`retryPolicy`/`redirectPolicy` were set together with a custom `transport`. |
| A PSR-18 exception | The network failed. It is passed through untouched. |

Details and the full list: [Errors](using-an-sdk.md#errors).

## Finding your way

Operations are grouped by route: `GET /users/{id}` is `Users::getById()`, `POST /users` is `Users::post()`. Each method's doc comment shows the HTTP route and the API's `operationId`, and every model, parameter object and enum is a normal PHP class your IDE can complete.

## Next

- [Using an SDK](using-an-sdk.md): models, errors and validation.
- [Sending data](sending-data.md): parameters, bodies, file uploads, cookies.
- [Authentication](authentication.md): API keys, bearer tokens, OAuth2.
- [Transport and servers](transport.md): timeouts, retries, middleware, testing without a network.
