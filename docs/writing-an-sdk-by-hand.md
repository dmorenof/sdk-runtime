# Writing an API client by hand

You do not need an OpenAPI file to use this runtime. A generated SDK is a client class, some operation arrays and some
models; you can write the same three things yourself for an API that has no specification (a payment gateway, an internal
service) and get the transport, retries, authentication, typed models and errors for free.
[redsys-sdk](https://github.com/dmorenof/redsys-sdk) is a real one, written this way.

## 1. The client: servers and operations

```php
use Dmorenof\SdkRuntime\ApiClient;

class Client extends ApiClient
{
    // Environments are servers: choose one with Configuration::$server, or ask Client::serverUrl('test').
    protected const array SERVERS = [
        ['url' => 'https://api.example.com', 'name' => 'live'],
        ['url' => 'https://sandbox.example.com', 'name' => 'test'],
    ];

    // The shape of a call. Only method and path are required; see operation-metadata.md for every key.
    private const array CREATE = [
        'method' => 'POST',
        'path' => '/v1/orders',
        'requestBody' => ['type' => '\\Acme\\Order', 'nullable' => false, 'required' => true, 'rules' => []],
        'responseType' => '\\Acme\\Receipt',
    ];

    public function create(Order $order): Receipt
    {
        /** @var Receipt $receipt */
        $receipt = $this->executeBody(self::operation(self::CREATE), $order);
        return $receipt;
    }
}
```

`Client::connect($configuration)` gives you the client; timeouts, a proxy, retries, middleware and the HTTP client itself
are `Configuration` options, the same as for a generated SDK ([Transport and servers](transport.md)).

## 2. The models

A model extends `DTO`. PHP properties are checked where a value is assigned (a PHP 8.4 `set` hook calling `assigning()`),
against the rules you declare; `WIRE_PROPERTY_MAP` renames properties for the wire:

```php
use Dmorenof\SdkRuntime\DTO;

final class Order extends DTO
{
    protected const array REQUIRED_FIELDS = ['reference'];
    protected const array VALIDATION = ['reference' => ['pattern' => '^[0-9]{4}[0-9A-Za-z]{0,8}$'], 'cents' => ['minimum' => 0]];
    protected const array WIRE_PROPERTY_MAP = ['reference' => 'Order_Ref', 'cents' => 'Amount'];

    public string $reference {
        set { $this->assigning('reference', $value); $this->reference = $value; }
    }

    public int $cents {
        set { $this->assigning('cents', $value); $this->cents = $value; }
    }
}
```

`$order->reference = 'abc'` is refused on that line; `$order->validate()` checks the whole model on demand. An answer is
read the way the API sent it: only PHP's own type errors refuse a value. The names an API writes in answers go in
`PROPERTY_MAP` (wire name => property); an answer with a field the model does not declare keeps it in `additionalProperties()`
if the model declares `ADDITIONAL_PROPERTIES_TYPE = 'mixed'` (and `ADDITIONAL_PROPERTIES_NULLABLE = true`).

## 3. Errors and security

An HTTP failure is an `ApiException` (status, headers, decoded body). Declare `'errors' => [422 => '\\Acme\\ProblemDetails']` on an
operation to decode a failure into a model. Authentication is declared once as `SECURITY_SCHEMES` and given as
`Configuration::$securityCredentials` ([Authentication](authentication.md)): API keys, basic, bearer, OAuth2 with
automatic renewal, OpenID Connect, mutual TLS.

## When the API signs or transforms what it sends

Do it before the call and after it, in your own class, not in the runtime: build the body model, sign it, call
`executeBody()`, then verify and read the answer. Redsys signs the parameters with a key derived for each operation, and answers
with a signed envelope; `Gateway` in redsys-sdk does exactly that around two operations.

## What a generated SDK adds

The generator writes these three things for you from a specification, and the models for every schema in it. If you have a
specification, use [sdk-generator](https://github.com/dmorenof/sdk-generator); if you do not, this page is the whole recipe.
