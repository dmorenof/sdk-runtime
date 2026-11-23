# Sending data

How the values you pass end up in the request. The SDK knows from the API's specification where each value goes and how to write it; you only provide the values.

## Where values travel

| Location | In the method | Example |
| --- | --- | --- |
| Path | An argument | `getById(7)` → `/users/7` |
| Query string | A property of the parameters object | `$params->verbose = true` → `?verbose=true` |
| Header | A property of the parameters object | `$params->XTrace = 'abc'` → `X-Trace: abc` |
| Cookie | A property of the parameters object | `$params->theme = 'dark'` → `Cookie: theme=dark` |
| Body | The model argument, or a property of the parameters object | `post($newUser)` |

The parameters object (`GetParams\…` for reads, `PostParams\…` for writes) has one typed property per query, header or cookie value. When an operation has both parameters and a body, the body is the object's `$body` property:

```php
$params = new PostParams\Items();
$params->name = 'q';            // query string
$params->XTrace = 'abc';        // header
$params->body = $form;          // the request body
$items->post($params);
```

A parameter whose schema lists its allowed values is an enum in the SDK (sdk-generator 1.5 and later): `getByState(StateEnum::Open)`, `$params->sort = SortEnum::Desc`. The request carries the case's value (`open`, `desc`), in the path, query string, headers and cookies alike, and a list of enum cases is sent as the list of their values. Older SDKs type these as plain strings or integers, and that keeps working.

Properties you do not set are not sent. Names that are not valid PHP identifiers are mapped (`X-Trace` is `XTrace`, `city-name` is `cityName`); the wire name never changes.

Everything is percent-encoded for you. Lists and objects in the query, path or headers are written in the style the API declares (comma-separated, space- or pipe-delimited, `deepObject`, label, matrix…); a list or object without a declared style is rejected instead of guessed.

## Bodies

| The API says | You pass | Sent as |
| --- | --- | --- |
| `application/json` | A model (or a list, map or scalar the API declares) | JSON |
| `application/x-www-form-urlencoded` | A model | `a=1&b=2` |
| `multipart/form-data` | A model; files as `UploadedFileInterface` | Multipart |
| `text/plain`, `application/octet-stream`, other raw media | A `string`, or any readable PSR-7 `StreamInterface` | The bytes as they are |

A call only serializes the body, as it is: build it with care, because it is checked when you set a value (see [Validation](validation.md)) and not again when it is sent. When the API makes the body optional, simply call the method without it.

### Uploading a file

A file field accepts any PSR-7 `UploadedFileInterface` (Guzzle's `UploadedFile`, or your framework's):

```php
use GuzzleHttp\Psr7\{UploadedFile, Utils};

$form = new DTOs\Form();
$form->name = 'Ada';
$form->file = new UploadedFile(Utils::streamFor(fopen('avatar.png', 'r')), filesize('avatar.png'), UPLOAD_ERR_OK, 'avatar.png', 'image/png');

$params = new PostParams\Items();
$params->body = $form;
$items->post($params);
```

The file's own name and type are used (when its type conflicts with what the API declares, the call throws). A file's stream must be readable and seekable, and the same stream cannot back two parts. A field may also take a plain `string` of bytes.

### Large bodies

A raw body may be any readable stream, including one that cannot be rewound (a pipe, a socket). It is sent once and is never retried or replayed after a redirect or a `401`. Multipart file streams must be seekable.

## Cookies

Cookies reach the server from three places, merged into one `Cookie` header:

1. A `Cookie` header in `Configuration::$headers` (for example `locale=es`);
2. cookie parameters (`$params->theme = 'dark'`);
3. an API key that the API places in a cookie.

```
Cookie: locale=es; enabled=true; theme=dark; session=tok
```

The same cookie name with two different values throws `UnexpectedValueException: Conflicting values for request cookie …`. Cookies set by the server (`Set-Cookie`) are not stored; read them with `lastResponse()->headers->cookies()` ([Responses](responses.md#headers)).

## Empty, omitted and null

| You do | The wire gets |
| --- | --- |
| Leave a property unset | Nothing |
| Set a nullable property to `null` | JSON bodies carry `null`; query, form and multipart values are left out |
| Set an empty string, `0` or `false` | That value, as written (`false` is sent as `false`) |
| Pass `null` for a nullable JSON body | JSON `null` |
| Omit an optional body | No body |
