# SDK Runtime

[![PHP package CI](https://github.com/dmorenof/sdk-runtime/actions/workflows/ci.yml/badge.svg?branch=main)](https://github.com/dmorenof/sdk-runtime/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)

Shared PHP 8.3+ runtime for SDKs produced by [sdk-generator](https://github.com/dmorenof/sdk-generator). Generated SDKs contain only their API-specific code (DTOs, requests, responses, enums and resources) and depend on this package for everything else, so the infrastructure is written, tested and fixed once.

The runtime holds no global state: each client owns its transport and credentials.

## Contents

| Namespace | Purpose |
| --- | --- |
| `Dmorenof\SdkRuntime\ApiClient` | `connect()`, `resource()`, `withMiddleware()` and operation execution. SDKs extend it and declare `SECURITY_SCHEMES`. |
| `DTO`, `Request`, `Response` | Typed hydration, validation and directional (`readOnly`/`writeOnly`) serialization. |
| `TypedArray`, `Map`, `AnyOf`, `OneOf` | Typed collections, keyed maps and composition wrappers. |
| `Security\*` | Bearer, Basic and API-key authentication with AND/OR requirements. Credentials are redacted from debug output. |
| `Transport\*` | `TransportInterface`, the PSR-18 `Psr18Transport` (Guzzle by default) and `HttpMiddlewareInterface`. |
| `ApiException` | Unexpected HTTP status with the raw response and, when decodable, the typed error. |

## Use from a generated SDK

~~~php
$client = Client::connect(
    'https://api.example.com',
    securityCredentials: new SecurityCredentials(['HTTPBearer' => $token]),
);
~~~

## Development

Use PHP 8.3+ and Composer 2:

~~~sh
composer install
bash ci/run-tests.sh
~~~

Source code belongs in `src/` under `Dmorenof\SdkRuntime\`; tests belong in `tests/`.

## GitHub Flow

Publish each completed, validated increment through `main`. Use a PR only when required by repository protections and complete publication in the same task. See [AGENTS.md](AGENTS.md), [CONTRIBUTING.md](CONTRIBUTING.md) and [RELEASING.md](RELEASING.md).

## License

MIT; see [LICENSE](LICENSE).

## Polymorphic models (v0.4.0)

Version 0.4.0 supplies the discriminator and original-schema evaluation support
used by SDK Generator 0.3.0. Generated packages require `dmorenof/sdk-runtime: ^0.4.0`.
See [CHANGELOG.md](CHANGELOG.md) for the coordinated release and compatibility notes.

Generated discriminator metadata is consumed by the shared `Hydrator` for nested
properties, collection items, maps and operation responses. `Pet::fromValue()`
also dispatches; PHP constructors cannot replace `new Pet(...)` with a subclass.
Inherited discriminator metadata does not redirect construction of a concrete
subtype. Unknown/missing tags fail explicitly, and subtype validation rejects a
tag that disagrees with its PHP class, including after mutation.

Composition wrappers may declare original branch descriptors and local schema
definitions. These are evaluated against an independent JSON snapshot before
hydration: the discriminator never reduces the number of valid `oneOf` branches.
A single selected mutable variant supplies outbound JSON, with cardinality and
discriminator checked again before serialization. Multiple `anyOf` variants remain
alternative views of the original snapshot; editing several views is not a payload
merge API. Legacy wrappers without descriptors retain their hydration-based matching.
This is a bounded evaluator for generated descriptors, not full JSON Schema support.

## Operation servers (v0.5.0)

Runtime v0.5.0 supplies the operation-server support used by SDK Generator
v0.4.0. Generated packages depend on stable runtime `^0.5.0`. Operations may declare a literal absolute HTTP(S) `serverUrl`;
root operations continue to use the configured client `baseUrl`. A declared
server path prefix is preserved when adding the operation path.

For other environments, pass `serverOverrides` to `Client::connect()` or the
constructor, mapping a declared server URL to a replacement base URL. The map is
copied to resources together with the transport and credentials; separate clients
keep separate maps. Keys ignore trailing slashes. Relative/template server URLs,
credentials, queries and fragments in operation server URLs remain unsupported
and fail before transport. Existing operations without `serverUrl` keep their
current routing.


## Query lists (v0.6.0)

An operation declares normalized list formats using
`querySerialization: ['ids' => 'multi', 'codes' => 'csv']`. Values `[1, 2]`
produce `ids=1&ids=2` or `ids=1,2`. Each name/value is RFC 3986 encoded before
joining; a comma inside an item is `%2C` while the CSV separator remains literal.
Scalars keep their existing serialization. Empty lists, absent parameters and
null values expand to no query members; empty string list members expand to
explicit empty values. Query API-key credentials retain scalar precedence.

Native `array` DTO fields hydrate JSON lists. Declare item rules such as
`VALIDATION = ['ids' => ['minItems' => 1, 'items' => ['type' => 'int',
'rules' => ['minimum' => 1]]]]`; type/item rules are checked on hydration and
again before sending, including after mutation. This is bounded list validation,
not general JSON Schema support. Nested/object/null items, undeclared query
arrays, other styles/locations and `allowReserved`/`allowEmptyValue` remain outside
this query-list slice. Generated SDKs declare metadata and use this common
executor rather than implementing serialization themselves.

### Delimited query lists (v0.7.0)

Normalized `ssv`, `tsv` and `pipes` formats serialize `[1, 2]` as
`ids=1%202`, `ids=1%092` and `ids=1%7C2`. Separators are percent-encoded to
produce legal RFC3986 URIs; item content is encoded once. These formats reject
items containing the corresponding literal space, tab or pipe, including values
introduced by mutations, because decoding would make them ambiguous. Literal
percent sequences are encoded as data (`%7C` becomes `%257C`). Repeated-key and
CSV formats keep their existing behavior. The generator must reject undefined
style/explode combinations rather than guessing how they should expand.

### Flat deepObject query parameters (v0.8.0)

`querySerialization: ['filter' => 'deepObject']` expands an object such as
`(object) ['city' => 'Madrid', 'active' => false]` to
`filter%5Bcity%5D=Madrid&filter%5Bactive%5D=false` for GET, POST or any other method.
Validated DTOs and typed Maps are accepted; DTO JSON names, backed enum values
and request-direction fields apply. Empty objects and absent/null outer values
are omitted; empty strings produce explicit empty property values.

Only scalar properties are defined by this slice. Nested objects/arrays, null
properties, non-finite numbers, names containing brackets or empty names, and
collisions with explicit query keys are rejected before sending. DTO/Map
mutations are revalidated. Query objects coexist with the operation's JSON body;
additional form shapes and multipart capabilities depend on the generator's supported subset.

### URL-encoded form bodies (v0.9.0)

Operations can declare `requestMediaType: 'application/x-www-form-urlencoded'`
and `bodySerialization: ['ids' => 'multi', 'codes' => 'csv']`. The body remains a
typed DTO, independent of path/query/header parameters. The shared serializer
validates it, applies JSON names and request-direction fields, normalizes backed
enums and primitive lists, and encodes spaces as + and literal +/~/% as data.
Null fields/empty lists are omitted; empty strings remain explicit. A missing
body has no Content-Type; a present empty DTO sends empty bytes with the form
Content-Type. Existing operations retain application/json by default.

Only scalar fields and primitive repeated-key/CSV lists are supported. Nested
objects, files and other URL-encoded form styles fail explicitly. Mutations
are validated before transport; query API-key handling remains separate.

## Shared execution and configuration (v0.10.0)

Generated SDKs can inherit ApiClient::executeArguments and Configuration instead
of emitting common field mapping, execution and connection options repeatedly.
SDK classes supply their typed operation data, default base URL and per-SDK
connection state. Existing generated executor overrides remain supported.

Query assembly uses the installed Guzzle PSR-7 Query builder. The remaining
adapter implements OpenAPI-specific repeated/CSV/delimited/deepObject semantics,
validation and omission rules. Forms select native RFC1738 percent-encoding;
JSON bodies continue through the validated DTO request representation. Generic
Guzzle query/form_params options use indexed PHP arrays and do not by themselves
represent all OpenAPI styles. Transport remains PSR-18-compatible.

### Buffered multipart bodies

Set `requestMediaType: 'multipart/form-data'` and `bodyParts` on an Operation.
Each wire field declares `['contentType' => 'text/plain']`; binary file fields
use e.g. `['contentType' => 'application/octet-stream', 'filename' => 'upload.bin']`.
DTO properties contain scalar values, primitive lists or raw binary strings;
new binary DTOs can also declare the PSR upload union below.
Lists become repeated parts. Guzzle's PSR-7 `MultipartStream` builds MIME and
boundaries; no MIME encoder is maintained here. Filenames and field names must
be printable ASCII without quotes/backslashes; content types are single MIME
types without parameters. Filename `0` is rejected because older supported PSR-7
versions treat it as absent; use e.g. `0.bin`. Numeric field names are supported.
Nulls and empty lists are omitted; empty strings
and empty files produce parts. DTO validation and direction rules still apply.

The standard PSR-18 adapter preserves the MultipartStream since v0.13.0; legacy
custom TransportInterface implementations still receive complete strings. Use
file-backed PSR uploads for large files. Nested fields and arbitrary part headers are not supported; binary lists are
supported as described below. JSON operations continue to use JSON serialization.

### PSR uploaded files (v0.12.0)

A binary DTO property can declare `string|\Psr\Http\Message\UploadedFileInterface`
(or add `|null` when nullable). Construct files using the existing Guzzle PSR-7
`UploadedFile`, with a readable, seekable stream or local file path. Its client
filename replaces the static fallback; the same ASCII/quoted-name restrictions
apply. Filename `0` remains rejected for compatibility with older PSR-7 versions.

A binary `bodyParts` entry can set `contentType` to null to use the upload's MIME
type, falling back to application/octet-stream when absent. A non-null contentType
is a contract restriction: conflicting uploaded MIME metadata is rejected
(case-insensitive matching). MIME types must be single concrete types without
parameters. Strings retain the declared type or octet-stream fallback and fixed
filename. Existing string-only SDKs and transport signatures keep working.

Failed uploads, moved/unreadable streams and non-seekable streams fail before
transport. Guzzle rewinds seekable streams for complete repeat sends. Upload
objects are allowed only in direct binary multipart fields; JSON, URL-encoded
forms and nested file values reject them explicitly. General native schema-union
support is unchanged. Since v0.13.0 the standard PSR-18 adapter passes multipart
streams directly to the client; legacy custom transports keep buffering.


### Streaming multipart through PSR-18 (v0.13.0)

ApiClient passes Guzzle MultipartStream directly to the existing Psr18Transport.
Its send method accepts PSR streams in addition to the original string/null body;
the legacy TransportInterface signature stays unchanged. No transport interface,
HTTP stack or stream wrapper is added. Inject a standard PSR-18 client through
Client::connect/httpClient when implementing custom streaming HTTP behavior.

The adapter rewinds seekable request bodies after middleware inspection or
replacement, so repeated uploads send complete files. Each file part must use an
independent stream cursor; reusing the same PSR stream object in multiple parts is
rejected before HTTP to avoid truncating subsequent parts.

A 32 MiB file was sent twice with default Guzzle/cURL and real cloud-local HTTP,
under PHP memory_limit=16M, with a measured 4 MiB PHP peak. An independent MIME
parser checked the full file bytes, name, MIME type, headers and query. CI and the
published installation smoke also read two 32 MiB multipart requests in chunks
under that limit. These measurements describe the tested upload path. String
inputs are already in memory, legacy custom transports buffer, middleware or an
injected HTTP client can buffer, and response bodies are still collected as
strings. Non-seekable inputs and general streaming responses remain outside this feature;
binary lists are added in v0.14.0.


### Binary multipart lists (v0.14.0)

A binary field can contain a native `list<string|UploadedFileInterface>`. Declare
an array DTO property and `VALIDATION` items as `['type' => 'binary', 'rules' => []]`,
with minItems/maxItems as needed. Its existing bodyParts filename/contentType
metadata applies to each item; no extra list metadata or file wrapper is needed.
Lists become repeated parts with exactly the same field name, preserving order.
PSR items supply their own filename/MIME; string items keep the static fallback.
An explicit contract MIME is enforced independently for every item.

Null fields and empty lists produce no parts; empty strings produce empty file
parts. Nullable list items, non-list arrays, wrong item types, failed uploads and
shared PSR stream cursors fail before transport. Revalidate mutations before each
send. JSON/urlencoded traversal still rejects PSR uploads, including inside lists.
Text lists and singular files retain their encoding. Native binary-list metadata
is an input adaptation, not general native schema-union support.

Two independent 16 MiB files (32 MiB aggregate) were sent twice using default
Guzzle/cURL under a 16 MiB PHP limit, with a measured 4 MiB PHP peak and full bytes
verified by an independent MIME parser. CI and installed-package verification
repeat the bounded-memory case. Existing transport/client/middleware/response
memory limits remain as described above.


### Binary response streams (v0.15.0)

An operation whose responseType is `\Psr\Http\Message\ResponseInterface::class`
requests application/octet-stream and returns the original successful PSR-7
response through the common PSR-18 adapter. Status, multi-value headers and body
remain available; empty successful responses also return the response. Use
`GuzzleHttp\Psr7\Utils::copyToStream($response->getBody(), $destination)` to save
bytes without building a complete PHP string. Seekable responses rewind after
middleware inspection. Middleware must preserve non-seekable response cursors.

JSON hydration and ApiException/typed JSON errors retain their buffered behavior.
Legacy custom TransportInterface implementations adapt their already-buffered
HttpResponse into a standard Guzzle PSR response. Existing send() callers keep
their HttpResponse contract; sendResponse() exposes the native PSR response.

The download smoke copies and hashes two 32 MiB responses under PHP
memory_limit=16M, including default Guzzle/cURL against cloud-local HTTP. This
bounds the tested PHP heap, not RSS or network latency. Guzzle may use a temporary
disk-backed stream and injected clients/middleware can buffer. This feature does
not promise progressive network consumption or unbuffered error responses.


### JSON types selected by HTTP status (v0.16.0)

Optional Operation responseTypes metadata maps explicit successful statuses to
`['type' => User::class, 'schemaNullable' => false]` descriptors. A descriptor with
type null denotes no body. ApiClient selects the entry for the actual HTTP status
and reuses JsonHydrator/DTO validation; it never probes another schema when that
body is invalid. Thus 204 can return null while an empty 200 still fails decoding.

Existing responseType/schemaNullable/noContentAllowed behavior remains the
fallback for SDKs without the metadata and for individual unmapped statuses.
The constructor option is appended after the existing arguments. Status codes
still need to be declared in success; unexpected statuses retain ApiException
and typed/raw error behavior. Binary PSR responses retain their existing path.
This map describes JSON response selection, not mixed JSON/binary media codecs,
status ranges, or general JSON Schema/native-property-union support.


### Simple primitive path/header lists (v0.17.0)

Operation pathSerialization/headerSerialization maps declare list names with csv.
ApiClient inherits the existing DTO argument extraction and query list validation;
QuerySerializer::simpleList encodes each path item with rawurlencode before comma
joining. Raw header values keep their text: embedded comma, control bytes and
leading/trailing SP/HTAB are rejected rather than inventing header escaping or
silently letting PSR trim the field. Optional null headers are omitted; explicitly
empty lists expand to an empty path segment/header value, subject to DTO bounds.

Path/header arrays require declared metadata and finite non-null scalar elements.
Missing path placeholders and null path values now fail before transport.
The constructor adds options at the end, and executeArguments keeps its native
signature: generated single-list paths use a PathParams DTO just like composite
paths. Query, body, response hydration, legacy transports and scalar signatures
remain compatible. Objects, label/matrix, non-CSV header/path formats, nullable
list schemas and general cookie serialization are outside this bounded feature.

Version 0.17.1 also preserves numeric wire names such as `0` when PHP coerces metadata keys to integers; both runtime execution and generated-operation PHPStan shapes cover these keys.


### Flat parameter objects (v0.18.0)

Existing pathSerialization/headerSerialization also accept object (key,value CSV)
and object-explode (key=value CSV). Query metadata accepts form-object and
form-object-explode, alongside deepObject. Reuse QuerySerializer's DTO/Map field
extraction and native encoders. Generated DTO request validation and readOnly
filtering happen before expansion; finite primitive fields retain false/zero.

Exploded query properties cannot overwrite another parameter or expanded field.
Simple header objects keep raw text and reject commas, equals in exploded keys,
control bytes and outer whitespace; equals in values remain data. Path keys/values
are individually percent encoded. Null/nested properties and empty keys are
unsupported. Optional null parameters are omitted, empty query objects omit all
pairs and empty simple objects expand to an empty field/segment. DeepObject keeps
its bracket-key restriction; form/simple encode brackets as ordinary key data.
No executor signature or constructor position changes, wrappers or transport
implementations are introduced. Cookies, label/matrix and general nesting remain
outside this release.


### Label and matrix path parameters (v0.19.0)

Path metadata adds label/matrix scalar, list and flat-object modes with both
explode forms. QuerySerializer::path reuses existing finite scalar conversion,
list validation, DTO/Map extraction and native item encoding; it does not parse
arbitrary URI templates. Numeric object keys remain distinct from list indexes.
Empty scalar labels expand to a dot; empty matrix scalars to ;name. Empty lists
and objects contribute no expansion; empty members follow RFC 6570.

Default Guzzle/cURL requests now preserve literal path segments via PATH_AS_IS,
so an empty label is not silently normalized away. Stream-handler fallback is
retained when cURL is unavailable; injected HTTP clients/servers must likewise
preserve the request target. CI and installed-package smoke verify actual local
HTTP targets and reference RFC examples. No constructor or executor signatures
change, and header/query serialization remains unchanged.

Scalar operation cookies are percent-encoded using the existing query serializer and assembled as RFC 6265 cookie pairs. API key cookie credentials are already-wire-form strings (base64 characters preserved); invalid octets/conflicts fail before HTTP. Configured Cookie headers coexist case-insensitively; cookie names remain case-sensitive. No jar, Set-Cookie parsing or persistence is provided. Structured form cookies remain unsupported because OpenAPI form expansion uses incompatible delimiters.

Operation queryAllowReserved names opt their values into OpenAPI reserved expansion. Existing QuerySerializer and Guzzle assembly preserve safe reserved data and valid percent triples. Names and security credentials retain normal encoding; raw `#[]&=+` and ambiguous structural delimiters fail before HTTP. Applications must pre-encode such data where a legal unambiguous representation exists. This option does not affect headers, cookies, bodies or general URI templates.

Operation parameterContent metadata declares application/json or text/plain by location/name. Existing Serializer and DTO validation handle JSON request direction, then ordinary URI encoding is applied once for path/query. Headers retain raw content bytes subject to validation. Cookie content supports text/plain raw RFC6265 pairs; schema-form cookies retain their existing encoding. No new transport/executor API or cookie jar is added.

### Swagger delimited path/header lists — v0.23.0

Use existing pathSerialization/headerSerialization metadata with csv, ssv, tsv or pipes. Paths percent-encode each item and space/tab/pipe separators; headers preserve raw delimiters. Items containing their separator are rejected when ambiguous, and outer header whitespace is rejected rather than silently trimmed by HTTP. multi remains query/form-only. Existing DTO validation, executors and Guzzle/PSR transport are reused.

### Response selection block — v0.24.0

Existing success, responseTypes and errors metadata accepts exact status codes,
uppercase class ranges (2XX, 4XX, etc.) and default. Exact entries precede ranges,
which precede default; explicit null error types suppress fallback decoding.
default marks only unmatched HTTP 2xx responses as successes. Non-2xx remain
ApiException instances with raw status/headers/body and typed object errors where
declared. Existing integer-only metadata remains compatible.

A mixed success map may select a JSON type, PSR-7 ResponseInterface for binary
streams, or null for no body. JSON and octet-stream Accept values are combined
only when both are declared. The selected binary PSR response returns unchanged,
without reading its stream; the legacy string transport retains its existing
buffered behavior. This does not add MIME negotiation among schemas at one status,
binary error decoding, redirect policy, HEAD/205 rules or scalar error models.

### Form encoding block — v0.25.0

FormSerializer reuses QuerySerializer for multi/csv/ssv/tsv/pipes lists and flat
object form-object/form-object-explode/deepObject metadata, with RFC1738 encoding,
delimiter checks and expanded-name collision detection. Existing form behavior
and executor signatures stay compatible.

Multipart bodyParts can declare json: true with application/json or application/*+json.
The existing request serializer preserves JSON names, nested models and readOnly
filtering; arrays yield repeated JSON parts. Guzzle continues to own MIME and
binary streams. JSON parts cannot carry file metadata or UploadedFileInterface.
Part headers, explode-false multipart lists, root form maps and representation
selection remain outside this block.

### JSON Schema rules block — v0.26.0

Constraints reuses opis/json-schema for exclusiveMinimum/exclusiveMaximum,
multipleOf, uniqueItems, minProperties/maxProperties and const. Existing primitive
validation stays compatible. Values are converted through the shared JSON
serializer; numeric and object equality follow the validator rather than PHP
strict identity. Fixed generated rule sets are compiled and reused.

MODEL_VALIDATION, MAP_VALIDATION and COLLECTION_VALIDATION constants carry root
constraints. Boundary validation catches mutations, while request/response
property counts follow the serialized direction. TypedArray stringification
validates and retains JSON array shape after removals.

Operation appends responseValidation without moving constructor parameters;
responseTypes entries optionally carry rules for their selected status. This
also validates scalar/array JSON roots rather than relying on object DTOs.
Only generated declarative keywords are passed to the validator; there is no
remote schema loading or runtime interpretation of arbitrary API documents.

### Native schema guards — v0.27.0

Primitive union hydration preserves the incoming JSON kind before conversions.
Integral finite JSON numbers also hydrate PHP integers and integer-backed enums.
Declarative `schema` rules reuse the existing Opis validator for boolean schemas
and primitive type sets, including array item guards; they validate the original
value and mutations. This does not enable arbitrary document loading or class unions.
Optional null HTTP parameters remain absent on the wire. Required parameters that
would otherwise disappear now fail before transport; required nullable JSON
properties still serialize as JSON null.

### JSON request bodies — v0.28.0

Generated clients may declare optional `requestBody` metadata with `type`,
`nullable`, `required` and `rules`. The protected `executeBody` entry point accepts
a value and explicit presence, distinguishing omission from JSON null. Combined
parameter DTOs use their initialized `body` property for the same distinction.
The existing protected DTO executor signatures remain compatible with overrides.
All execution shares argument mapping, nested validation, Serializer and the
existing Guzzle/PSR transport. JSON and application/*+json bodies retain declared
media types; forms retain their existing DTO contracts. Text/binary bodies and
negotiation among request representations remain outside this release.

### Raw media — v0.29.0

Native requestBody metadata may use `encoding: text` for text/plain or
`encoding: binary` and `type: stream` for application/octet-stream. Text retains
string constraints. Binary accepts strings or readable seekable PSR-7 streams
without custom stream wrappers. PSR-18 preserves the stream and rewinds it;
legacy custom transports retain their string fallback. The installed smoke
uploads 32 MiB under a PHP 16 MiB memory limit. Binary constraints and
non-seekable input remain explicitly unsupported.

Single responses may set responseMediaType to text/plain; per-status entries may
set mediaType. They hydrate raw strings rather than decoding JSON. JSON, text,
binary PSR responses and bodyless statuses may coexist by status; Accept lists
the declared codecs. This does not negotiate multiple schemas at one status.
Raw null has no representation: optional omission and an empty byte string are
distinct, while explicit null fails before HTTP. Streams cannot serialize as JSON.

### General error payloads — v0.30.0

ApiException retains its HTTP response/status and DTO-only `error` property.
The new readonly `payload` exposes declared primitive/null, collection, map, enum,
composition or DTO errors. DTO errors share the same object in both properties.
Old constructor/fromResponse calls remain compatible.

Optional errorResponses operation metadata uses the existing response descriptor
shape and exact/range/default selection. A null type explicitly suppresses body
decoding. JSON/text/binary codecs and constraints reuse existing hydration and
validation; error media join Accept. Failed decoding leaves payload null, retains
the raw response and exposes the decoding/validation exception as getPrevious().
A valid nullable error also has payload null, with no decoding cause.

Binary errors remain buffered bytes in HttpResponse and payload, consistent with
the existing exception API; success binary responses retain their PSR streaming
behavior. No exception subclasses, response wrappers or new transport are added.

### Concrete MIME codecs — v0.31.0

Existing json/text/binary codecs retain declared MIME values. String schemas
can carry opaque CSV/XML; bounded binary schemas retain PSR input/output streams
for image/png, application/pdf and other concrete media. Vendor/problem +json
keeps JSON decoding and its declared Accept/Content-Type. Optional responseEncoding
and per-status encoding metadata select the codec independently of the MIME name.
Old text/plain and binary SDK metadata remains supported.

The installed smoke sends a 32 MiB PNG body and consumes a 32 MiB PDF response
under PHP 16 MiB, alongside mixed codecs and general error payloads. Raw CSV/XML
remain PHP strings: no model parser, charset conversion or new transport is added.
Wildcard selection and incompatible representation negotiation remain unsupported.
