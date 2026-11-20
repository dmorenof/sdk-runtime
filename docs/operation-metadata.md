# Operation metadata

*For people who write or change a generator.* SDK users never touch this.

A generated resource method describes its HTTP call with a small array and hands it to the runtime:

```php
private const array GET_BY_ID = [
    'method'       => 'GET',
    'path'         => '/users/{id}',
    'responseType' => '\\Acme\\Accounts\\DTOs\\User',
];

public function getById(int $id, ?GetParams\Users\ById $params = null): DTOs\User
{
    /** @var DTOs\User $result */
    $result = $this->executeArguments(self::operation(self::GET_BY_ID), path: ['id' => $id], parameters: $params);
    return $result;
}
```

Only `method` and `path` are required. Every other key falls back to `Operation::DEFAULTS`, and a generator omits keys equal to their default so the output stays small. (`Operation::fromArray($spec)` turns the array into a validated, immutable `Operation`.) Generated code passes the constant through `self::operation(...)`, which builds the `Operation` once per resource class and route and reuses it on every call; the execute methods also accept the plain array.

## Executing

Three protected methods on `ApiClient` run an operation. Choose by what the method receives:

| Method | Use when |
| --- | --- |
| `executeArguments($op, $path = [], ?DTO $parameters = null, ?DTO $body = null)` | The generated method takes path values and an optional parameter object (`GetParams\…`). `$path` is an array of path values, or a parameter object that declares them. Also the way to call an operation whose optional body the caller left out. |
| `executeBody($op, $body, $path = [], ?DTO $parameters = null)` | The request body is the main argument. A body is always present here, even when it is JSON `null`. |
| `executeOperation($op, RequestInput $input = new RequestInput())` | You already have the values split by where they travel: `new RequestInput(path: [...], query: [...], headers: [...], body: ...)`. |

The runtime reads query, header and cookie values from the parameter object's `OPERATION_FIELDS` (see [Models](#models)).

## Keys

### Request

| Key | Default | Meaning |
| --- | --- | --- |
| `method`, `path` | required | HTTP method and path template (`/users/{id}`). |
| `label` | `"METHOD path"` | Name used in error messages. |
| `security` | `[]` | Alternatives (OR) of scheme names (AND), as `[['ApiKey' => []], ['Bearer' => []]]`. An empty entry means optional auth. |
| `validation` | `[]` | Parameter name → rules (see [Validation](validation.md)). |
| `requestBody` | `null` | `['type' => Class, 'nullable' => bool, 'required' => bool, 'rules' => [...], 'encoding' => 'json'\|'text'\|'binary']` |
| `requestMediaType` | `application/json` | Also `application/x-www-form-urlencoded`, `multipart/form-data`. |
| `bodySerialization` | `[]` | Form array fields → `multi`, `csv`, `ssv`, `tsv`, `pipes`, `deepObject`, `form-object`, `form-object-explode`. |
| `bodyParts` | `[]` | Multipart parts → `contentType`, `filename`, `json`. |
| `querySerialization` | `[]` | Query list/object names → wire format (`multi`, `csv`, `ssv`, `tsv`, `pipes`, `deepObject`, `form-object`, `form-object-explode`). |
| `queryAllowReserved` | `[]` | Query names that use reserved expansion. |
| `pathSerialization` | `[]` | Path names → `csv`, `ssv`, `tsv`, `pipes`, `object`, `object-explode`, and `label*`/`matrix*` styles. |
| `headerSerialization` | `[]` | Header names → `csv`, `ssv`, `tsv`, `pipes`, `object`, `object-explode`. |
| `parameterContent` | `[]` | Parameters carried as JSON or text media (by location). |

### Response

| Key | Default | Meaning |
| --- | --- | --- |
| `success` | `[200]` | Statuses that are not errors. Entries may be `204`, `'2XX'` or `'default'`. |
| `responseType` | `null` | Class or scalar the success body becomes. `null` = no body. A PSR-7 `ResponseInterface` class name hands the raw response over. |
| `schemaNullable` | `false` | The JSON body may be `null`. |
| `noContentAllowed` | `false` | An empty body returns `null`. |
| `responseValidation` | `[]` | Rules applied to the decoded value. |
| `responseMediaType` | `application/json` | Sent in `Accept`. |
| `responseEncoding` | `json` | `json`, `text`, `binary`, or a streaming one: `jsonl`, `json-seq`, `sse` (the call then returns a `\Generator`). |
| `responseTypes` | `[]` | Per-status entries (below). Overrides the single-response keys above. |
| `errors` | `[]` | Status (or `default`) → error model class. |
| `errorResponses` | `[]` | Per-status error entries, same shape as `responseTypes`, for non-DTO payloads. |
| `responseHeaders` | `[]` | Status (or `default`) → header name → `['type' => 'string'\|'integer'\|'number'\|'boolean'\|'array', 'items' =>, 'required' =>, 'rules' =>]`. |

A **response entry** (`responseTypes[200]`, `errorResponses[404]`) is:

```php
['type' => User::class, 'schemaNullable' => false,
 'rules' => [], 'mediaType' => 'application/json', 'encoding' => 'json']
```

To offer several decodable representations for one status, give the entry `alternatives`:

```php
200 => ['type' => null, 'schemaNullable' => false, 'alternatives' => [
    ['type' => Report::class, 'schemaNullable' => false, 'mediaType' => 'application/json', 'encoding' => 'json'],
    ['type' => 'string',      'schemaNullable' => false, 'mediaType' => 'text/csv',         'encoding' => 'text'],
    ['type' => '\\Psr\\Http\\Message\\ResponseInterface', 'schemaNullable' => false, 'mediaType' => 'image/*', 'encoding' => 'binary'],
]]
```

The response `Content-Type` picks the alternative, most specific first (`application/vnd.api+json`, then `application/*+json`, `text/*`, `*/*`).

### Servers

| Key | Default | Meaning |
| --- | --- | --- |
| `serverUrl` | `null` | A fixed server for this operation. Absolute, or relative to the base URL. |
| `servers` | `[]` | Selectable servers: `['url' => 'https://{region}.api.test', 'name' => 'prod', 'variables' => ['region' => ['default' => 'eu', 'enum' => ['eu','us']]]]`. The first is the default. Mutually exclusive with `serverUrl`. |

A client class may also declare `protected const SERVERS = [...]` (same shape) so `Client::serverUrl()` can build the base URL, and `SECURITY_SCHEMES` (below).

### Security schemes

Declared once on the client:

```php
protected const array SECURITY_SCHEMES = [
    'ApiKeyAuth' => ['type' => 'apiKey', 'scheme' => null,     'location' => 'header', 'parameterName' => 'X-Api-Key'],
    'BearerAuth' => ['type' => 'http',   'scheme' => 'bearer', 'location' => null,     'parameterName' => null],
    'BasicAuth'  => ['type' => 'http',   'scheme' => 'basic',  'location' => null,     'parameterName' => null],
    'OAuth'      => ['type' => 'oauth2', 'scheme' => null,     'location' => null,     'parameterName' => null],
];
```

`apiKey` supports `header`, `query` and `cookie`. The runtime sends `oauth2` and `openIdConnect` schemes as bearer tokens (sdk-generator 1.3.0 and later emit them; an `openIdConnect` scheme also carries its `openIdConnectUrl`, and `mutualTLS` is emitted from 1.9.0) (see [Authentication](authentication.md)). A security requirement lists the scopes the operation needs, which the runtime checks against the token's before sending.

## Models

Generated models extend `DTO` and declare:

| Constant | Meaning |
| --- | --- |
| `REQUIRED_FIELDS` | Field names that must be present. Optional: `DTO` declares it empty, so a model with no required fields leaves it out. |
| `VALIDATION` | Field → rules. |
| `MODEL_VALIDATION` | Rules for the model as a whole. |
| `OPERATION_FIELDS` | (Parameter objects only) `['path' => [...], 'query' => [...], 'header' => [...]]`, property → wire name. |

Collections extend `TypedArray` (`ITEM_VALIDATION`, `COLLECTION_VALIDATION`), maps extend `Map` (`VALUE_TYPE`, `VALUE_NULLABLE`, `VALUE_VALIDATION`, `MAP_VALIDATION`), and `oneOf`/`anyOf` wrappers extend `OneOf`/`AnyOf` (`TYPES`, `DISCRIMINATOR_PROPERTY`, `DISCRIMINATOR_MAPPING`, `BRANCH_SCHEMAS`, `SCHEMA_DEFINITIONS`).

## Stability

The metadata format is a contract between a generator and a runtime of the **same major version**. It is not for application code. See [Public API and versioning](PUBLIC_API.md).
