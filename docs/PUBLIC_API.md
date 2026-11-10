# Public API and compatibility promise

From **1.0.0** this package follows [Semantic Versioning](https://semver.org/). This document defines what is covered. Anything not listed as stable is free to change in any release.

The promise is checked mechanically: `tests/Fixtures/public-api.txt` is a reflection snapshot of every public and protected member of the stable classes below, and `tests/PublicApiTest.php` fails when the code and the snapshot differ (see [Checking the API](#checking-the-api)).

## Supported platform

- PHP 8.3 and newer. Dropping a PHP version is a major change.
- Runtime dependencies keep their current major ranges (`guzzlehttp/guzzle ^7.9`, `guzzlehttp/psr7 ^1.9.1 || ^2.0`, `opis/json-schema ^2.6.1`, `psr/http-client ^1.0`, `psr/http-message ^1.1 || ^2.0`, `typedarray/typedarray ^1.0`). Widening a range is a minor change; narrowing or raising a major is a major change.

## Tier 1: stable public API

Application code and generated SDKs may rely on these classes: constructors, public methods and properties, and, for the extension classes, their protected members.

**Client and operations**

- `ApiClient`: `connect()`, `resource()`, `withMiddleware()`, `withRetryPolicy()`, `withRedirectPolicy()`, `useServer()`, `withServerVariables()`, `serverUrl()`, `lastResponse()`, and the protected `executeOperation()`, `executeArguments()`, `executeBody()`, `SECURITY_SCHEMES` and `SERVERS` for SDK authors.
- `Configuration`: the connection options an SDK `Configuration` extends.
- `Operation`: the operation metadata format (see [Operation metadata](#operation-metadata)).
- `ApiException`: `response`, `error`, `payload` and the previous exception.

**Models and collections** (extension points for generated code)

- `DTO`, `Request`, `Response`: construction from JSON, validation, serialization and additional properties; protected constants `VALIDATION`, `MODEL_VALIDATION`.
- `TypedArray`: constants `ITEM_VALIDATION`, `COLLECTION_VALIDATION`.
- `Map`: constants `VALUE_TYPE`, `VALUE_NULLABLE`, `VALUE_VALIDATION`, `MAP_VALIDATION`.
- `AnyOf`, `OneOf`: constants `TYPES`, `BRANCH_SCHEMAS`, `SCHEMA_DEFINITIONS`, `DISCRIMINATOR_PROPERTY`, `DISCRIMINATOR_MAPPING`, and `fromValue()` / `matchedVariants()`.

**Security**

- `SecurityCredentials`, `BasicAuthCredentials`.
- `TokenProviderInterface`, `TokenStoreInterface`, `InMemoryTokenStore`, `OAuth2Token`, `OAuth2ClientCredentials`.

**Transport**

- `TransportInterface`, `HttpMiddlewareInterface` (interfaces you implement), `Psr18Transport`, `HttpResponse`.
- `RetryPolicy`, `RedirectPolicy`, `RedirectException`.
- `ResponseHeaders`, `ResponseInfo`.

### Operation metadata

Generated SDKs describe each operation as an array that `Operation::fromArray()` turns into an `Operation`. This format is part of the contract between a generated SDK and the runtime:

- Existing keys keep their name, type and meaning within a major version.
- Constructor positions never change; new keys are appended with a default, so an SDK generated for runtime 1.x keeps working with every later 1.y.
- Unknown keys are rejected, so an SDK that uses a newer key requires a runtime that knows it. Generated SDKs therefore require `^1.x` of the runtime version that introduced the keys they use.

## Tier 2: generator contract

`sdk-generator` calls a few runtime helpers at build time. They are tagged `@internal` because applications must not use them, but they are frozen within a major version for the generator of the same major:

- `Operation::allowsResponseBody()` and `Operation::DEFAULTS`
- `ServerUrl::normalize()`
- `CookieHeader::pair()`
- `Constraints::compileSchema()` and `Constraints::validate()`
- `FormatValidator::check()` and `FormatValidator::asserted()` (see [FORMATS.md](FORMATS.md))

## Tier 3: internal

Everything tagged `@internal` is an implementation detail and may change or disappear in any release: `Hydrator`, `JsonHydrator`, `Serializer`, `SchemaValidator`, `QuerySerializer`, `FormSerializer`, `MultipartSerializer`, `Discriminator`, `MediaType`, `SecurityApplier`, plus individual members tagged `@internal` inside stable classes. Private members are never API.

## What counts as a breaking change

Major version (removal or incompatible change):

- Removing or renaming a stable class, method, property or constant, or changing its visibility to a narrower one.
- Changing a signature incompatibly: removing or reordering parameters, adding a required parameter, narrowing a parameter type or widening a return type.
- Adding an abstract method to a stable class or a method to a stable interface that applications implement (`TransportInterface`, `HttpMiddlewareInterface`, `TokenProviderInterface`, `TokenStoreInterface`). New capabilities arrive as new interfaces.
- Making a stable non-final class `final`.
- Changing the type of exception a documented failure raises.
- Rejecting input that was valid under the documented rules, or changing the bytes sent on the wire for an unchanged request in a way servers can observe, except to fix a bug against the OpenAPI/HTTP rules.

Minor version (backward compatible):

- New classes, methods, constants, optional parameters (appended last) and operation metadata keys.
- New opt-in behavior, such as `RetryPolicy`; defaults stay unchanged.

Patch version: bug fixes and stricter conformance to the specifications that do not reject previously valid input.

Messages of exceptions, `__debugInfo()` output and the order of unordered collections are not covered.

## Deprecation

A feature is deprecated with a `@deprecated` tag naming the version and the replacement, and an entry in [CHANGELOG.md](../CHANGELOG.md). It stays for at least one minor version and is removed only in the next major.

## Checking the API

- `tests/PublicApiTest.php` compares the code with `tests/Fixtures/public-api.txt` and checks that every stable class is named in this document.
- After an intentional change, regenerate the snapshot with `php ci/api-snapshot.php > tests/Fixtures/public-api.txt`, review the diff, and record the change in the CHANGELOG. A removed or incompatible line means a major version.
