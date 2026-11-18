# Design decisions

Decisions taken during the quality review that led to the next 1.x release. Each entry says what was decided and why, so a later change can be judged against the reason and not only the result. Newest decisions go at the bottom.

## Rules adopted

| # | Rule | Reason |
| --- | --- | --- |
| R1 | A function takes at most 3 arguments, 4 in justified cases. More options travel in a configuration object. | Long argument lists are hard to read, easy to misorder and impossible to extend without breaking callers. |
| R2 | A class that only carries named options (a *configuration object*) may list its options in its constructor, up to 6, always used with named arguments. Splitting it further would hide options inside nested objects without making anything simpler (KISS). | The rule R1 exists to keep logic simple; a record of settings is not logic. |
| R3 | A method named `with…` must not modify the object it is called on. A method that changes state is named `set…`, `add…` or `use…`. | A name must not lie about what a method does. |
| R4 | One class, one reason to change (SRP). A class larger than about 300 lines, or a method larger than about 40 lines, is reviewed and split unless splitting makes it harder to follow. | Smaller units are easier to read, test and replace. |
| R5 | Internal helpers are `final` and marked `@internal`. The public surface is what `tests/Fixtures/public-api.txt` lists. | The public API stays small and every public name is a promise. |

## Decisions

Filled in as the review progresses.

### D1. `Operation` is built only with `fromArray()`
Its constructor had 27 parameters and was never meant to be called by hand: generated code always supplies an array. The constructor is now private. One way in, no 27-argument signature to document or keep stable.

### D2. OAuth2 providers take three value objects
`OAuth2ClientCredentials` had 9 constructor parameters and `OAuth2AuthorizationCode` had 14. Both now take `(OAuth2Endpoints $endpoints, OAuth2Client $client, OAuth2Options $options = new OAuth2Options())`:

- `OAuth2Endpoints`: token URL, authorization URL, issuer (where the server is).
- `OAuth2Client`: id, secret, redirect URI, secret-in-body (who the application is).
- `OAuth2Options`: scopes, HTTP client, store, subject, leeway, clock (everything with a default).

Each value object validates its own data, so the providers only hold behavior. `requestLifetime` stopped being an option: a started authorization flow lives 10 minutes, and nobody needs to tune that (KISS). The credentials-in-body flag moved to `OAuth2Client` because it describes how that client authenticates.

### D3. `ApiException` only carries data; decoding moved to `ResponseDecoder`
The exception built itself from a response, with two decoding strategies, and took five constructor arguments. It is now `ApiException(HttpResponse $response, string $operation, mixed $payload = null, ?Throwable $previous = null)`: the response, what failed, the decoded error and the reason decoding failed. `$error` (the payload when it is a model) is derived, not passed. All decoding lives in the internal `Execution\ResponseDecoder`, next to the code that decodes successes, so a response has one place that knows how to read it.

### D4. `Operation::declares()` replaces looking into the raw array
The client used to keep the generated array only to ask "did the generator set `responseEncoding`, or is that the default?". `Operation` now remembers which keys were given, so nothing downstream needs the raw array and several methods lose a parameter.

### D5. `ApiClient` is split by responsibility (797 → 363 lines)
The client mixed sending, building requests, reading responses and server choice in one class. Request and response work moved to small internal classes in `Execution\`, each with one job:

| Class | Job |
| --- | --- |
| `InputBuilder` | Turn what a generated method receives (path values, a parameter object, a body) into a `RequestInput` |
| `RequestParameters` | Validate and serialize path, query, header and cookie values |
| `RequestBody` | Decide how the body is carried, check it against its contract, produce its bytes |
| `RequestBuilder` | Combine the two into a `PreparedRequest` (path, query, headers, body) |
| `ResponseDecoder` | The `Accept` header, decoding a success, building an `ApiException` |

The code was moved, not rewritten; the existing 499 tests and 19 smoke scripts are the proof it still behaves the same.

### D6. The three execute methods take at most 4 arguments
`executeArguments`, `executeBody` and `executeOperation` had 5 and 6 parameters (`identifier`, `identifierName`, `bodyPresent`, separate path/query/header arrays…). They are now:

- `executeArguments($operation, $path = [], ?DTO $parameters = null, ?DTO $body = null)`
- `executeBody($operation, $body, $path = [], ?DTO $parameters = null)`
- `executeOperation($operation, RequestInput $input = new RequestInput())`

`$path` is an array of path values or a parameter object that declares them, which replaces `identifier` + `identifierName`. "Body present" is no longer a flag: a method that sends an optional body calls `executeBody()` when it has one and `executeArguments()` when it does not. `RequestInput` is a configuration object (R2) with five named options.

### D7. A client is configured once, through `Configuration`, and never changes
`ApiClient::__construct` and `connect()` took six loose arguments, and five methods (`withMiddleware`, `withRetryPolicy`, `withRedirectPolicy`, `useServer`, `withServerVariables`) changed a client that other code might share. They lied twice: `with…` suggests a copy, and a policy set on a client silently reached every resource built from it, even earlier ones.

Now `connect(Configuration|string $configuration)` is the only way in (a string is shorthand for a base URL with the defaults) and `Configuration` carries every option: base URL, HTTP client, headers, credentials, transport, middleware, retry and redirect policies, server choice and variables, server overrides. After connecting, nothing changes; to use other options, connect another client, which is cheap. `resource()` hands the new resource the same transport and server choice. Options that need the default PSR-18 transport are rejected up front when a custom transport is given, instead of failing later.

`Configuration` is a bag of public properties set by name, not a constructor with eleven arguments (R2 is about constructors).

### D8. Security is applied by returning a value
`SecurityApplier::apply` took six arguments, three of them by reference to receive results, and one method did four things. It now takes three (`credentials`, `requirements`, `schemes`) and returns an `AppliedSecurity` (headers, query, token providers); the work is split into choosing a satisfiable requirement and building its headers and query. Outputs by reference are hard to follow and impossible to test without scaffolding.

### D9. `Psr18Transport` is immutable and sends a PSR-7 request
Its constructor took five arguments and three setters (`setRetryPolicy`, `setRedirectPolicy`, `addMiddleware`) changed it after creation, which broke D7 one level down. It is now `new Psr18Transport($client, new Psr18Options(defaultHeaders:, middleware:, retryPolicy:, redirectPolicy:, sleeper:))` with no setters. `sendResponse` takes a `RequestInterface` instead of method, URL, headers and body: the request already is the object that groups those four values.

### D10. Long methods were split where they mixed several jobs
`SchemaValidator::matches` (91 lines, 5 parameters) now has one small method per job (references, compositions, kinds, objects, items) and its extra parameters live in the instance. `Hydrator::convert` (87 lines) dispatches to one method per target type (`object`, `enum`, `model`). `QuerySerializer::encode` (75 lines) has one method per parameter format; `MultipartSerializer::encode` (65 lines) has `element()` and `uploadedFile()`. `ApiClient::sendWithAuthRetry` is now the retry loop only, with `exchange()` (one round trip) and `outcome()` (what the caller receives) next to it.

Left as they are on purpose: `StreamDecoder::sse` is a small state machine whose lines read in order, and `Constraints::validate` is a flat sequence of independent checks. Splitting either would scatter a single idea over several methods without making it easier to follow (KISS).

### D11. `Operation` keeps a 28-parameter private constructor
It is the one place where the length is honest: the object has 28 immutable properties, one per metadata key. The constructor is private (D1), so no caller sees it, and `fromArray()` is the only door. Replacing it with 28 setters or a builder would add code to hide a list that is already the specification of the format.

### D12. `QuerySerializer` was three things and is now three classes
At 357 lines it wrote query strings, wrote path and header styles (simple, label, matrix) and serialized parameters carried as media. They share only how to read a value, so it is now `QuerySerializer` (query strings), `ParameterStyles` (path, header and content parameters) and `ParameterValues` (finite scalars, flat objects, lists). Each can be read and changed without the others.

### D13. `ApiClient` handles PSR-7 responses only
It kept two code paths for the two kinds of transport (PSR-7 and a custom one answering a buffered `HttpResponse`), with the same `instanceof` test repeated five times. A custom transport's response is now wrapped into a PSR-7 response once, right after the round trip, and everything after that is a single path.

### D14. Tolerant reading and one failure type for bad answers (from the audit against OpenAPI Generator)
The audit ([AUDIT.md](AUDIT.md)) ran the same scenarios against both SDKs and found two things where the other tool behaved better:

- **A field the schema does not declare broke the call.** Now it is ignored when reading an answer; a model built by hand from JSON stays strict, so mistakes in what you send are still caught.
- **Bad answers raised four unrelated exception types** (`JsonException`, `ValueError`, `UnexpectedValueException`, `InvalidArgumentException`). Now one `InvalidResponseException` carries the raw response and the original failure.

Still open: `lastResponse()` is instance state (not safe to share between concurrent tasks); `sdk-generator` rejects `oauth2` and `openIdConnect` security schemes.

### D15. Versioning: the next release is a 1.x minor, not 2.0.0
The software is new and has no users, and the 1.0.0 that exists was published before this review. A new major for the redesign would signal a compatibility history that does not exist. The changes in this review therefore ship as the next 1.x release, and the changelog and the migration guide say plainly what changed. Major versions are reserved for packages that other projects already depend on (the TypedArray library is one). When this software has real users, the Semantic Versioning promise in [PUBLIC_API.md](PUBLIC_API.md) applies as written.
