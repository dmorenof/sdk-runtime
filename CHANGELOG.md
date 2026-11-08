# Changelog

## Unreleased

No changes yet.

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
