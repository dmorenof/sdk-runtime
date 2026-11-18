# Using an SDK

## Calling operations

Each route becomes a method. Path parameters are plain arguments, the request body is a model, and everything else (query, headers) travels in one optional *parameters* object.

```php
$users = $client->resource(Users::class);

$user = $users->getById(7);                       // GET /users/7

$params = new GetParams\Users\ById();
$params->verbose = true;
$user = $users->getById(7, $params);              // GET /users/7?verbose=true

$new = new DTOs\NewUser();
$new->name = 'Ada';
$created = $users->post($new);                    // POST /users, JSON body
```

Names follow the route: `GET /users/{id}` → `getById`, `POST /users` → `post`. The doc comment on each method shows the HTTP route and the API's `operationId`.

## Models

A model (`DTO`) is a class with typed public properties, one per field of the API schema.

```php
$user->id;                  // int
$user->name;                // string
$user->nickname;            // ?string (the API allows null)
$user->role;                // backed enum, e.g. RoleEnum::Admin
$user->labels;              // typed collection (StringCollection)
$user->profile;             // nested model
```

Rules that surprise people:

- **Properties start uninitialized.** Reading one that was never set (required or not) throws `Error`. Test with `isset($model->nickname)`, or read only what the API always returns.
- **Unknown fields in an answer are ignored.** If the API adds a field its schema does not declare, clients generated before still work. A model you build by hand from JSON (`new NewUser((object) [...])`) rejects fields it does not declare. See [Unknown properties](#unknown-properties).
- **Dates and times are strings.** A `date-time` field is a `string` in the model; formats can be checked (see [Validation](validation.md#formats)) but are never converted to `DateTimeImmutable`.
- **Models know their own rules.** Calling `$model->validate()` checks required fields and every declared rule (`minLength`, `pattern`, `enum`, formats…). Sending a request validates the body first.

### Creating and reading models

```php
$new = new DTOs\NewUser();                      // set properties one by one
$new->name = 'Ada';

$new = new DTOs\NewUser((object) ['name' => 'Ada']);   // or from decoded JSON

$user = DTOs\User::fromValue($decodedJsonObject);      // same, static form

json_encode($new);                              // {"name":"Ada"}
(string) $new;                                  // same text
$new->validate();                               // throws if invalid
```

### Read-only and write-only fields

When the API marks a field `readOnly` it is never sent; `writeOnly` fields are never read. `validateForRequest()`, `validateForResponse()`, `jsonSerializeForRequest()` and `jsonSerializeForResponse()` apply the matching direction. You rarely call them yourself: the client does.

### Collections and maps

| Class | Use | Example |
| --- | --- | --- |
| `TypedArray` (generated subclasses) | List of one type | `new StringCollection(['a'])`, `->append('b')`, `count()`, `foreach` |
| `Map` | String-keyed dictionary of one type | `$map['key'] = $value`, `->getArrayCopy()` |

Adding a wrong type throws `TypeError`.

### One-of and any-of

When a schema is `oneOf` or `anyOf`, the SDK uses `OneOf`/`AnyOf` wrappers. `Pet::fromValue($json)` picks the matching variant by the discriminator (or by shape), and `matchedVariants()` lists what matched. An unknown or missing discriminator value throws.

## Errors

Every failure is a standard PHP exception, so you can catch as broadly or narrowly as you like.

| Exception | When | Useful members |
| --- | --- | --- |
| `Dmorenof\SdkRuntime\ApiException` | The API answered with a status the operation does not declare as success (4xx, 5xx…). | `$e->getCode()` (status), `$e->response->status/headers/body`, `$e->payload` (decoded error when documented), `$e->error` |
| `Dmorenof\SdkRuntime\InvalidResponseException` | The API answered with a success status but a body that does not fit what the operation declares: empty, not JSON, a wrong type, a missing required field, a broken rule, an enum value the schema does not list. Extends `UnexpectedValueException`. | `$e->response` (raw), `$e->getPrevious()` (the original failure) |
| `UnexpectedValueException` | A request model does not match its schema (missing required field, wrong scalar type, broken rule), or the response media type is not declared. | message names the model and field |
| `InvalidArgumentException` | You passed something the SDK cannot send (unknown property, bad parameter, bad server variable), or the response has a field the schema does not declare. | |
| `ValueError` | You assigned a value that is not one of an enum's cases. | |
| `TypeError` | A typed collection received the wrong item type. | |
| `Dmorenof\SdkRuntime\Transport\RedirectException` | A redirect policy refused a redirect (downgrade, too many, cross-origin). | `$e->response` |
| `Dmorenof\SdkRuntime\Security\OAuth2Exception` / `ReauthorizationRequiredException` | Token endpoint failure / the user must sign in again. | `isRejection()` |
| PSR-18 exceptions | Network failure. Never wrapped. | |

```php
use Dmorenof\SdkRuntime\ApiException;

try {
    $user = $users->getById(99);
} catch (ApiException $e) {
    if ($e->getCode() === 404) {
        // not found
    }
    $body = $e->response->body;     // raw text, always available
    $error = $e->payload;           // decoded error model, or null
}
```

`ApiException` always keeps the raw response, even when the error body could not be decoded.

## Unknown properties

When the API answers with a field the SDK's schema does not declare, the field is ignored: an API that adds a field never breaks a client generated before it. What you send is strict: a model built by hand from JSON rejects a field it does not declare (`InvalidArgumentException: Unknown JSON property …`). Schemas that declare `additionalProperties` accept extra fields: read them with `$model->additionalProperties()` or `getAdditionalProperty($name)`, write with `setAdditionalProperty($name, $value)`.

## Response metadata

After a call, `lastResponse()` returns the status and headers of the last response **received by that same client or resource instance**:

```php
$users = $client->resource(Users::class);
$users->getById(7);

$info = $users->lastResponse();      // null before the first request
$info->status;                       // 200
$info->operation;                    // "GET /users/{id}"
$info->headers->get('X-Rate-Limit'); // int when the operation declares it, else string
```

Clients are synchronous. Reuse one client for sequential calls; do not share one instance between concurrent tasks (fibers, workers), because `lastResponse()` belongs to the instance.

A client and its resources each keep their own `lastResponse()`: calling through `$users` does not update `$client->lastResponse()`. See [Responses](responses.md#headers).
