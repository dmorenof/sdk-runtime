# Changelog

## [Unreleased — implementation in progress, not validated]

- Share schema compilation with the generator and accept JSON-encoded declarative guards without losing object/list identity. Disable implicit defaults and Opis execution extensions; format remains an annotation.
- Evaluate model guards against original input JSON and retain validation on mutable values, explicit null bodies and composition direction.
- Extend native union hydration to stdClass/array without converting wire types; delegate enum equality to Opis, including nullable assertions and composition evaluation.
- Centralize HTTP response body eligibility for HEAD and bodyless statuses, skip incompatible payload decoding while preserving raw error status/response, and reject actual TRACE request bodies.
- Coordinated generator implementation is in release/0.2.0. No tests, analysis or release run for this accumulated development, at the user's request; stable runtime remains v0.31.0.
- Add OAuth2 client credentials (RFC 6749 section 4.4): `OAuth2ClientCredentials` obtains, caches and renews tokens over any PSR-18 client, with HTTP Basic or body client authentication, scopes and refresh-token renewal that falls back to a new grant when the refresh token is rejected.
- Add `TokenProviderInterface`, `TokenStoreInterface`, `InMemoryTokenStore` and `OAuth2Token`. `SecurityCredentials` accepts a provider wherever a bearer token string is accepted; `SecurityApplier` applies provider tokens to `http bearer`, `oauth2` and `openIdConnect` schemes. Existing string credentials behave as before.
- `ApiClient` replays a request once with a renewed token after a 401 when a token provider was used and the body is replayable. Static tokens are never replayed. Secrets stay out of debug output and error messages.
- Authorization-code/PKCE, OpenID Connect discovery, scope checking and mTLS remain pending (see pendientes.md).
- Add opt-in `RetryPolicy` for the PSR-18 transport (`ApiClient::withRetryPolicy()`): retries 429/502/503/504 and network errors for idempotent methods only by default, with exponential backoff and equal jitter, honoring `Retry-After` (seconds or HTTP date) up to a cap; non-seekable bodies are never replayed. Disabled unless configured.
- Expose typed response headers: `ApiClient::lastResponse()` returns the last response's status and a case-insensitive `ResponseHeaders` view. Operations may append `responseHeaders` metadata (by status, range or default) declaring `string`/`integer`/`number`/`boolean`/simple-list headers, required flags and rules; `get()` converts and validates on demand, so a malformed header never discards a successful body. `Set-Cookie` lines are parsed with Guzzle's `SetCookie` and stay separate on the PSR-18 transport (legacy transports join repeated lines). Signatures of `execute*` are unchanged.
- Add a configurable redirect policy (`RedirectPolicy`, `ApiClient::withRedirectPolicy()`), applied by the PSR-18 transport on every hop (middleware included). Default: follow up to 5 of 301/302/303/307/308 with a `Location`; 301/302 turn POST into GET, 303 turns any method except HEAD into GET, 307/308 keep method and body (a non-seekable body is never resent: the 3xx is returned). Safeguards: no https→http downgrade, only `http(s)` targets without embedded credentials, and only an allowlist of safe headers crosses origins (so `Authorization`, `Cookie` and API-key headers never reach another host). `RedirectPolicy::none()` returns the 3xx response untouched. Violations raise `RedirectException` carrying the response.
- Behavior change: the default Guzzle client is now built with `allow_redirects => false` so this policy decides (same 5-hop default); https→http downgrades now fail instead of being followed, and 301/302 keep non-POST methods (RFC 9110) where Guzzle rewrote them to GET. A custom PSR-18 client that follows redirects itself bypasses the policy.

## [0.31.0] — 2026-11-10

- Preserve concrete declared MIME types for JSON, opaque text and binary request/success/error codecs. CSV/XML string schemas use raw strings; PNG/PDF and other bounded binary schemas reuse PSR streams. Vendor/problem +json retains JSON hydration with its actual Accept/Content-Type.
- Append optional responseEncoding and per-status encoding metadata without changing earlier constructor positions. Retain legacy binary/text codec inference and header ordering for existing SDKs; explicit codec/media metadata takes precedence.
- Verify actual CSV/XML/vendor JSON exchanges, mixed status codecs, raw errors, a 32 MiB image upload and 32 MiB PDF download under PHP 16 MiB. No XML/CSV model parsing, charset transcoding, wildcard media selection or incompatible representation negotiation is added.

## [0.30.0] — 2026-11-10

- Decode declared HTTP error payloads through existing JsonHydrator, native types, DTO/Map/TypedArray/composition validation and raw text/binary codecs. Append optional Operation errorResponses metadata with exact/range/default selection and bodyless overrides.
- Add readonly ApiException payload while preserving its DTO-only error property, constructor positions and legacy fromResponse path. Valid DTO errors expose the same object through both properties; scalar/null/list/map/enum/composition payloads use payload. Invalid decoding preserves the HTTP response/status and records the cause instead of replacing the HTTP failure.
- Include declared error codecs in Accept and verify actual HTTP, constraints/directions, malformed JSON, bodyless precedence and independent installation. Binary errors remain buffered in the existing HttpResponse; this does not add error-response streaming or arbitrary media negotiation.

## [0.29.0] — 2026-11-10

- Execute text/plain and application/octet-stream native request bodies as raw bytes. Reuse string/PSR-7 stream inputs, existing transport rewinding and middleware; readable seekable streams retain identity and avoid materialization in PSR-18. Legacy transports keep their string fallback.
- Select text/plain response hydration by single or per-status media metadata alongside existing JSON, binary PSR responses and bodyless statuses. Build Accept from declared codecs and retain response constraints. Append optional responseMediaType without changing earlier constructor positions.
- Preserve optional omission versus empty text, reject explicit raw null/non-seekable streams and prevent streams leaking into JSON. Verify actual 32 MiB upload under PHP 16 MiB, mixed response codecs and independent published-package smoke. Raw binary schema constraints and representation negotiation remain unsupported.

## [0.28.0] — 2026-11-10

- Support declared native JSON request bodies: primitive values, null, arrays, maps and existing models/collections. Distinguish body absence from explicit JSON null and enforce required/nullability/constraints before transport.
- Reuse shared argument mapping, nested validation, Serializer and the existing HTTP executor; retain protected executeOperation/executeArguments signatures for consumer overrides. Append optional Operation requestBody metadata without changing constructor positions.
- Preserve query/header/body separation, request-direction model filtering, application/*+json media and mutation checks. Exercise actual HTTP and installed-package smoke; text/binary request bodies and representation negotiation remain separate.

## [0.27.0] — 2026-11-10

- Hydrate primitive PHP unions preserving original JSON kinds, accept finite integral JSON numbers for integers and integer-backed enums, and evaluate declarative boolean/type-set schema guards through existing Opis validation.
- Validate null and post-construction mutations without bypassing false schemas; optional null wire parameters remain omitted, while required parameters cannot silently disappear. Required nullable JSON properties retain JSON null.
- Exercise native schemas, aliases, parameter content and request/response mutations through actual HTTP and an independent installed-package smoke. Object/class unions remain bounded by existing composition support.

## [0.26.1] — 2026-11-10

- Recheck inherited TypedArray item types and rules at validation/serialization boundaries. ArrayObject exchangeArray can bypass append/offsetSet, so boundary validation now delegates item types to the parent library and reapplies declarative rules. The v0.26.0 tag remains immutable.

## [0.26.0] — 2026-11-10

- Reuse maintained opis/json-schema ^2.6.1 for exclusive numeric bounds, multipleOf, uniqueItems, property counts and const instead of adding a second JSON equality/arithmetic implementation. Compile fixed rule sets once; preserve JSON shapes and request/response direction.
- Add declarative model/map/collection constraints and recheck them at hydration/serialization boundaries and after mutations. Nullable const values are validated; composition nullability no longer bypasses const.
- Validate root JSON responses through appended responseValidation metadata or optional per-status rules. Keep old constructor/executor positions and shared collection behavior compatible; direct TypedArray stringification now preserves array shape and validates mutations.
- Verify Unicode code-point length, numeric/object equality, both response metadata paths and actual HTTP in source and installed-package smoke. JSON number precision remains bounded by PHP numeric types.

## [0.25.0] — 2026-11-10

- Complete a form-encoding block through existing QuerySerializer: URL-encoded ssv/tsv/pipes lists and flat object form/exploded/deepObject fields, retaining native form encoding, collision rejection and DTO validation.
- Add JSON multipart part metadata using request-direction DTO/collection serialization and json_encode; Guzzle MultipartStream still builds MIME and preserves uploads/streams. Complex arrays emit repeated JSON parts; explicit JSON scalar parts retain JSON quoting.
- Verify URL/query/header/body separation, Unicode, false/zero, readOnly filtering, nested models, repeated JSON parts and binary bytes with actual HTTP and installed-package smoke. No transport or executor API changes.

## [0.24.0] — 2026-11-10

- Select responses by exact HTTP status, class range and default, in that order, using existing operation metadata. Explicit bodyless errors suppress fallback hydration. Default success applies only to HTTP 2xx.
- Mix JSON, binary PSR-7 streams and bodyless successes by selected status, retaining both transport paths and old metadata behavior. Negotiate JSON/octet-stream when both are declared and preserve binary streams without materialization on PSR-18.
- Verify precedence, typed errors/raw failure retention, exact empty responses, request query/body isolation and real GET/POST HTTP in source and immutable-package smoke.

## [0.23.0] — 2026-11-10

- Support Swagger ssv/tsv/pipes list metadata in path/header through existing QuerySerializer list validation, native encoding and shared ApiClient. Preserve CSV and object formats and executor signatures.
- Percent-encode path delimiters; preserve raw header delimiters and reject ambiguous data or header whitespace that HTTP would trim. Verify GET/POST query/body separation and all three delimiters with actual HTTP and installed-package smoke.

## [0.22.1] — 2026-11-09

- Preserve JSON array shape after removing or explicitly indexing TypedArray elements: append serialized values rather than retaining PHP keys. Map/object keys stay unchanged. Reuse the shared Serializer for root/nested collections, parameters and bodies; verify sparse mutations with real HTTP and installed-package smoke.

## [0.22.0] — 2026-11-09

- Add optional per-location parameterContent operation metadata after existing arguments. Serialize application/json through existing request-direction validation/Serializer/json_encode and text/plain as strings before normal path/query encoding or raw header transmission.
- Support typed model/collection/map parameters without new wrappers or executor signatures. Preserve empty JSON arrays/strings, false/zero, numeric map keys, readOnly filtering and optional-null omission; reject style/media conflicts, malformed headers, invalid JSON and unsupported cookie JSON.
- Assemble text/plain cookie values as RFC6265 raw pairs alongside configured, schema-form and security cookies, without query percent encoding. Verify both transports, mutations and actual GET/POST/scalar-path HTTP in source and installed-package smoke.

## [0.21.0] — 2026-11-09

- Add per-query allowReserved metadata to Operation after existing arguments and reuse QuerySerializer/Guzzle query assembly. Preserve safe reserved characters and valid percent triples in values; names, unflagged parameters and security credentials keep ordinary encoding.
- Support existing primitive lists and flat DTO/Map form/deepObject objects. Reject raw query syntax/illegal URI characters and ambiguous structural delimiters; documented pre-encoding remains the caller's responsibility under OpenAPI Appendix C. RFC1738 reserved expansion is not supported.
- Preserve executor signatures, query/header/cookie/body separation, false/zero/empty/null behavior and existing parameter constraints. Verify both transports, exact wire bytes, credential precedence, mutations and real GET/POST HTTP in source and immutable-package smoke.

## [0.20.0] — 2026-11-09

- Add scalar operation cookies through existing DTO argument execution and percent encoding. Preserve query/body/header independence without changing executor signatures; reject structured values.
- Support raw API key cookie credentials with existing security alternatives/AND selection. Assemble one RFC 6265 Cookie header across operation fields, security and configured headers, preserving case-sensitive cookie names and rejecting malformed/conflicting values without exposing secrets.
- Reuse Guzzle/PSR transport and native parsing/encoding. No cookie jar, response parser or persistence is added. Verify both transports, mutations, conflicts and real HTTP GET/POST in source and installed-package smoke.

## [0.19.0] — 2026-11-09

- Add label/matrix expansion for explicit scalar, list and flat-object path metadata, including both explode modes, finite booleans/numbers, numeric keys, encoded data and empty values. Reuse the existing scalar/list/DTO/Map routines instead of a second URI-template engine.
- Preserve literal path segments in the default Guzzle/cURL client so dot-prefixed empty labels survive transport. Keep injected clients, stream-handler fallback, constructor/executor APIs and existing simple/query/header behavior compatible.
- Verify reference examples from RFC 6570 sections 3.2.5/3.2.7, empty/null/invalid shapes and actual HTTP request targets. Include the real HTTP smoke in source CI and immutable-package installation verification.

## [0.18.0] — 2026-11-09

- Extend the existing flat DTO/Map parameter extraction to simple path/header objects and form query objects. Reuse field serialization, scalar conversion and percent encoding; preserve readOnly filtering, enum values and validation without new transport or object wrappers.
- Add object/object-explode modes to existing path/header metadata and form-object/form-object-explode query modes. Reject expanded query collisions and ambiguous raw header delimiters; retain deepObject and list/legacy execution behavior. Preserve numeric keys, false/zero values and selected native encoding.
- Verify both transports, DTO mutations, typed maps, empty/null/nested policies, query collisions, structural header delimiters and installed-package smoke. Nested objects, cookies and label/matrix remain outside this change.

## [0.17.1] — 2026-11-09

- Preserve numeric path/header list wire names such as 0 after PHP coerces array keys to integers. Model numeric metadata/validation keys honestly and stringify the protocol name before invoking the shared list serializer. Verify both transports and the installed-runtime consumer.

## [0.17.0] — 2026-11-09

- Add optional pathSerialization/headerSerialization CSV list metadata after existing constructor arguments. Reuse scalar-list validation/native item encoding in QuerySerializer::simpleList and the inherited DTO argument executor; keep executeArguments signature unchanged for older SDK overrides.
- Encode path items before comma joining; keep raw simple headers with explicit rejection of commas, control bytes and outer item whitespace. Preserve query/body separation, scalar paths/headers, both transport paths and response behavior. Missing/null path values and lists without metadata fail before HTTP.
- Verify finite scalar lists, exact bytes, encoding/percent/Unicode/slash data, bounds, mutations, empty-list policy, invalid formats, PSR/legacy transport and installed-runtime smoke. Objects, other path/header formats and general nullable/cookie expansion remain pending.

## [0.16.0] — 2026-11-09

- Add optional Operation responseTypes status-to-JSON-type metadata, appended to the constructor without changing existing positional arguments. Select exactly the declared status type/nullability through the existing JsonHydrator; a no-body status returns null.
- Preserve responseType/schemaNullable/noContentAllowed fallback for older SDKs and unmapped statuses, binary PSR responses and typed/raw ApiException errors. Explicit body-bearing statuses reject empty/null/wrong-schema bodies even when another success status permits no content; do not probe alternative schemas.
- Verify both PSR-18 and legacy transport execution, validation, nullable/empty/error cases, partial metadata fallback and independently installed runtime behavior, without new hydrator or response wrappers.

## [0.15.0] — 2026-11-08

- Preserve successful binary response streams through the existing PSR-18 adapter using native ResponseInterface responseType, without a custom download wrapper. Retain status, multi-value headers, empty responses and middleware replacement/rewind behavior.
- Keep JSON hydration, typed/raw ApiException errors, TransportInterface and buffered send() callers compatible. Legacy custom transports adapt via the existing Guzzle Response.
- Add behavior tests plus CI/installed-package 32 MiB download copy/hash smoke under PHP memory_limit=16M. Document client/middleware buffering, temporary disk storage and error-response limits.

## [0.14.0] — 2026-11-08

- Expand binary multipart list fields into repeated parts with the exact same wire name, preserving item order, per-file PSR filename/MIME and raw string bytes. Reuse the existing Guzzle MultipartStream encoder and seekable-stream transport.
- Add the bounded binary item descriptor for native PHP list validation (string or UploadedFileInterface). Revalidate list shape, bounds and mutations; permit PSR objects only in multipart traversal, keeping explicit JSON/urlencoded rejection. Text lists, scalar files and independent-cursor checks retain their behavior.
- Verify mixed inputs, names, per-item MIME conflicts, failed uploads, duplicates, null/empty policies, direction and JSON behavior. Extend CI and installed-package streaming smoke to two independent 16 MiB files (32 MiB aggregate) per request, repeated twice under PHP memory_limit=16M; real default Guzzle/cURL HTTP measured 4 MiB PHP peak and complete files verified by an independent MIME parser.

## [0.13.0] — 2026-11-08

- Preserve Guzzle MultipartStream through the existing PSR-18 adapter instead of casting multipart bodies to strings before sending. Widen Psr18Transport::send contravariantly to accept StreamInterface; keep TransportInterface and legacy custom transports compatible via the existing string fallback. No new transport interface or stream wrapper.
- Rewind seekable request bodies after middleware inspection/replacement and before every PSR-18 send. Reject duplicate PSR stream cursors across parts before they can truncate a subsequent file. Preserve query, headers, body metadata, validations, empty/absent behavior and legacy sends.
- Verify unread streams at the PSR-18 boundary, repeat sends, middleware replacement, legacy custom transport fallback and aliases. Add CI/published-package smokes transferring two 32 MiB multipart bodies under PHP memory_limit=16M; default Guzzle/cURL real HTTP measured a 4 MiB PHP peak, with full bytes checked by an independent MIME parser.
- Document the upload scope: file-backed seekable inputs enable low-memory transfer in the tested standard path; legacy transports, strings, configured clients/middleware and response buffering retain their own memory behavior.

## [0.12.0] — 2026-11-08

- Accept PSR UploadedFileInterface values for multipart binary DTO fields, preserving the existing string input. Reuse Guzzle's UploadedFile/streams and MultipartStream; no new upload wrapper or transport interface.
- Hydrate the explicit string|UploadedFileInterface property union, including nullable properties. Preserve wire names, required/directional rules and mutation validation. JSON serialization rejects upload objects instead of silently emitting empty JSON objects; arbitrary schema unions remain outside this change.
- Null binary part contentType accepts the upload's MIME type or falls back to application/octet-stream. Explicit contract content types remain enforced. Use the client filename with existing safe-name checks, or the declared fallback filename; reject failed uploads and unreadable/non-seekable streams before transport. Seekable streams rewind for repeat sends through Guzzle.
- Multipart requests still buffer complete bodies under the compatible string transport contract. Input streams do not imply streaming HTTP or bounded upload memory. Verify native PSR metadata/bytes, replay, mutations, JSON rejection and immutable installed-package smoke.

## [0.11.1] — 2026-11-08

- Preserve numeric multipart wire names when PHP coerces metadata keys to integers. Reject filename `0`, which older supported Guzzle PSR-7 releases interpret as absent, rather than silently changing file semantics. Add regression coverage before generator adoption.

## [0.11.0] — 2026-11-08

- Add buffered multipart DTO requests using the existing Guzzle PSR-7 MultipartStream, with primitive text fields, repeated primitive lists and binary string file parts. Optional Operation::bodyParts declares static content types and filenames; JSON/form defaults and public transport interfaces remain compatible.
- Preserve binary bytes, wire names, direction filtering, required/value validation and mutation checks. Omit nulls and empty lists, retain empty fields/files, distinguish absent and empty multipart bodies; reject unsupported shapes and unsafe part metadata before transport.
- No streaming/large-file memory guarantees: TransportInterface receives complete string bodies. General per-part headers, nested objects, CSV multipart arrays and request representation negotiation remain outside this slice.

## [0.10.1] — 2026-11-08

- Keep path, query and header buckets separate in the inherited executor so the minimum supported PHPStan version retains each array's exact value types. Remove the result-shape override; wire behavior and public signatures are unchanged.
- Validate both the latest and minimum analysis dependency sets before the generator adopts the shared executor release. The v0.10.0 tag remains immutable.

## [0.10.0] — 2026-11-08

- Move the common generated argument executor and DTO operation-field mapping into ApiClient so new SDKs inherit them once. Add shared Configuration fields for generated configurations to extend while preserving per-SDK defaults/state and public types.
- Reuse Guzzle PSR-7 Query::build for query assembly, retaining only OpenAPI format adaptation, scalar validation and per-item delimiter encoding. Support native RFC3986/RFC1738 encoding selection so forms use urlencode rules directly instead of transforming a completed query string.
- Declare the already-used guzzlehttp/psr7 package as a direct compatible dependency. Keep repeated-key/CSV/delimited/deepObject semantics, boolean text, null omission, percent data, field names, DTO validation and JSON/form payload behavior.
- Existing SDK executor overrides and transport injection remain compatible. Verify inherited execution, independent configuration, encoding and immutable installed runtime behavior; record reuse/inheritance rules in AGENTS.md.

## [0.9.0] — 2026-11-08

- Add URL-encoded form bodies through optional Operation requestMediaType/bodySerialization metadata. Preserve existing JSON defaults and positional querySerialization constructor compatibility.
- Serialize validated DTO request fields using HTML form encoding: spaces as +, escaped literal plus/tilde/percent, scalar backed enums and primitive repeated-key/CSV lists. Preserve wire names, request direction and mutation validation; omit null fields and empty lists, retain empty strings.
- Keep query, headers and body independent. Distinguish absent body (no content type/body) from present empty form (empty bytes with form content type). Reject nested fields, null list items, non-finite values, unsupported modes/media and JSON operations carrying form metadata before transport.
- Verify exact body bytes, headers, legacy behavior and clean installation of the immutable release. Multipart, files, objects and other form encoding styles remain pending.

## [0.8.0] — 2026-11-08

- Add `deepObject` query serialization for flat scalar objects, shared by every HTTP method. Accept stdClass, validated DTOs and typed Maps; use JSON wire names, backed enum values and request-direction filtering.
- Encode brackets and scalar property values once, preserve empty strings, omit absent/null outer parameters and empty objects. Reject nested/null property values, non-finite numbers, bracketed/empty names and collisions with explicit query keys before transport.
- Revalidate DTO/Map mutations before serialization and retain API-key scalar precedence. This does not add HTTP forms, nested deepObject semantics or other object styles.
- Verify encoding, directional fields, mutation rejection, ambiguous names, legacy serializers and the immutable published installation.

## [0.7.0] — 2026-11-08

- Extend normalized query-list formats with `ssv`, `tsv` and `pipes`. Encode separators as `%20`, `%09` and `%7C` so URLs remain RFC3986-compatible; encode item content once and preserve existing repeated-key/CSV behavior.
- Reject items containing their format's space, tab or pipe delimiter before transport, including mutations. These formats cannot distinguish such data from delimiters after percent-decoding; callers can choose repeated-key or CSV serialization when the contract allows it.
- Keep list validation, omission/empty-item behavior, scalar parameters and API-key precedence. Verify exact URLs, encoding, ambiguous values, mutation rejection and clean installation of the immutable release.
- This remains a query primitive-list slice; objects, other locations and undefined OpenAPI explode combinations are not implemented by these formats.

## [0.6.0] — 2026-11-07

- Add declarative query-list serialization to Operation: `querySerialization` maps wire names to `multi` (repeated keys) or `csv`. Encode items individually before joining; preserve legacy scalar order and encoding.
- Allow native JSON-list DTO properties through the shared hydrator and declarative `items` validation for primitive types and existing value rules. Revalidate list mutations before request transmission.
- Keep query API-key credentials scalar and preserve their existing precedence. Reject undeclared arrays, associative/nested/null-item lists, non-finite numbers and unsupported formats before transport.
- Empty lists expand to no URI members; null/absent parameters are omitted, while empty string members remain explicit values. General empty/null policies, objects, other locations and additional styles remain pending.
- Verify clean installation and exact query URLs from the immutable published runtime. Existing operation metadata defaults remain backward compatible through the new optional field.

## [0.5.0] — 2026-11-05

- Execute literal absolute HTTP(S) operation servers in the shared client, preserving path prefixes and existing root-base routing.
- Add per-connection `serverOverrides` for environment substitutions, preserved across shared resources. Reject unsupported operation server URLs before transport.
- Provide the operation-server runtime used by SDK Generator v0.4.0 and its stable `^0.5.0` dependency.
- Verified with 54 runtime tests / 108 assertions, PHPStan max, 216 generator tests / 2,061 assertions and seven exact-URL HTTP mock regressions for Casafari.

## [0.4.0] — 2026-11-05

Coordinated with SDK Generator 0.3.0 ([generator PR #19](https://github.com/dmorenof/sdk-generator/pull/19), [runtime PR #6](https://github.com/dmorenof/sdk-runtime/pull/6)).

### Added

- Shared discriminator dispatch for DTO properties, collections, maps and HTTP responses. `DTO::fromValue()` can construct a subtype while keeping the declared base return type; direct PHP constructors keep their explicitly requested class.
- Evaluate generated original-schema descriptors before composition hydration. `oneOf` requires exactly one valid branch and `anyOf` at least one; discriminator mappings never change schema cardinality.
- Revalidate selected composition values after mutation and serialize their current state. Reject discriminator values that disagree with a concrete subclass, including string-backed enum tags.
- Preserve required-field direction and validate supplied readOnly/writeOnly values in neutral mode.

### Compatibility and limits

- New discriminator/composition metadata requires this runtime release; SDK Generator 0.3.0 emits a stable `^0.4.0` dependency.
- Composition JSON is captured as an independent snapshot. Consumers must compare JSON contents rather than relying on the identity of the input object.
- Legacy composition wrappers without schema descriptors retain hydration-based matching. Multiple `anyOf` variants remain views of the original snapshot; editing several variants is not a payload merge API.
- The descriptor evaluator supports the generator's bounded schema subset, not general JSON Schema, `not` or arbitrary native PHP unions.

### Validation

- PHP 8.3.36: 50 tests / 101 assertions; PHPStan at maximum level.
- Generator integration: 181 tests / 1,916 assertions, including 28 discriminator tests and four installable generated SDKs checked with PHPStan max.
- GitHub CI covers PHP 8.3, 8.4 and 8.5, minimum compatible dependencies and dependency advisory audit.

Earlier versions are documented in [GitHub Releases](https://github.com/dmorenof/sdk-runtime/releases).
