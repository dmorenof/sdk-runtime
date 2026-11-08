# Changelog

## Unreleased

No changes yet.

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
