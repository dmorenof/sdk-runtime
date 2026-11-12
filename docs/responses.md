# Responses

What an operation returns depends on what the API declares for each status. You never configure this; this page explains what to expect.

## Success, errors and no content

| The API says | The method returns | If something else arrives |
| --- | --- | --- |
| `200`/`201`… with a JSON schema | A model, list, scalar or map | `ApiException` (status not declared as success) |
| `204`, or a success without body | `null` (or `void`) | |
| Several success statuses with different shapes | A union of those types, chosen by the actual status | |
| A status range (`2XX`) or `default` as success | The declared type, or `null` when empty | |
| A non-success status (`404`, `5xx`…) | Never returned: always an `ApiException` | |

An empty or malformed body where the API promised JSON throws `JsonException` (valid JSON of the wrong shape throws `UnexpectedValueException`); it is never silently `null`. A `204` is `null` only when the API declares it.

### Errors with a body

When the API documents the error body (`404` → `{"message": …}`), `ApiException::$payload` holds it decoded, as a model. When it does not, or decoding fails, `$payload` is `null` and `$e->response->body` still has the raw text.

```php
try {
    $users->getById(99);
} catch (\Dmorenof\SdkRuntime\ApiException $e) {
    $e->getCode();            // 404
    $e->payload?->message;    // decoded error model, if documented
    $e->response->body;       // raw body, always
}
```

## Headers

Every response's headers are readable after the call:

```php
$users->getById(7);

$h = $users->lastResponse()->headers;
$h->get('X-Rate-Limit-Remaining');   // int if the API declares it as an integer, else string
$h->first('Content-Type');           // first raw value
$h->values('Set-Cookie');            // all raw values
$h->has('ETag');                     // case-insensitive
$h->names();                         // names as received
$h->cookies();                       // list<GuzzleHttp\Cookie\SetCookie>
```

When the API declares a response header (`responses[200].headers`), `get()` converts it to its declared type (`integer`, `number`, `boolean`, a list for `array`) and enforces its rules (`required`, `minimum`, `enum`…). A header that breaks its declaration throws at `get()`; it never hides a successful body. Undeclared headers are plain strings.

`lastResponse()` belongs to the instance you called, and is `null` before the first request. Error responses are recorded too, so after catching an `ApiException` you can still read the headers.

## Files and raw bodies

| The API says | You get |
| --- | --- |
| `application/octet-stream`, `image/*`, any `format: binary` | A `Psr\Http\Message\ResponseInterface` |
| `text/plain` or other text media with a string schema | A `string` |

For downloads, copy straight to disk without building a string:

```php
$response = $files->download('report.pdf');
\GuzzleHttp\Psr7\Utils::copyToStream($response->getBody(), fopen('/tmp/report.pdf', 'w'));
```

Request bodies work the same way: a raw body may be a string or any readable PSR-7 stream (a one-shot, non-seekable stream is sent once and never retried or replayed). Multipart uploads need seekable streams.

## One status, several media types

If the API offers the same status as JSON, CSV or an image, the return type is the union, and the server's `Content-Type` decides how the body is decoded. `Accept` lists everything the operation can decode.

```php
$report = $reports->export();            // Report | string | ResponseInterface
if ($report instanceof Report) { /* JSON */ }
elseif (is_string($report))    { /* text/csv */ }
else                           { /* image or other binary */ }
```

A success with a media type the operation does not declare throws `UnexpectedValueException`.

## Streaming

Some operations return a lazy `\Generator` instead of one value: one item per line (`application/jsonl`, `application/x-ndjson`), per record (`application/json-seq`) or per event (`text/event-stream`).

```php
foreach ($client->users() as $user) {         // JSON Lines: one User per line
    handle($user);                            // arrives as the server sends it
}

foreach ($client->events() as $event) {       // Server-Sent Events
    echo $event->event, ': ', $event->data, "\n";
    $event->item;                             // the decoded model, when the API declares a schema
}
```

- Items are validated as they arrive. Leave the loop (`break`) to stop early; the connection closes when the generator finishes or is destroyed.
- A single line or record may not exceed 1 MiB.
- Error statuses are never streamed: they raise `ApiException` as usual.
- Guzzle is asked not to buffer. Any other PSR-18 client decides for itself; an endless event stream needs one that streams.
- Middleware must not read the body. Guzzle's read timeout (`default_socket_timeout`, 60 s) applies between chunks, so a quiet stream needs it raised.
- There is no automatic reconnection. Use each event's `id` and `retry` to reconnect yourself.
