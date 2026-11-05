# Changelog

## Unreleased

- Execute literal absolute HTTP(S) operation servers in the shared client, preserving path prefixes and existing root-base routing.
- Add per-connection `serverOverrides` for environment substitutions, preserved across shared resources. Reject unsupported operation server URLs before transport.
- Coordinated with generator PR #20 on `feature/p0-contract-fidelity`; no standalone release.

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
