# Audit: sdk-runtime + sdk-generator against OpenAPI Generator

Question: is an SDK produced by this project clearly better than one produced by [OpenAPI Generator](https://openapi-generator.tech) (`-g php`, version 7.10.0) in **generated code**, **ease of use** and **junk code**?

Short answer: yes on all three, with one honest exception: **speed** (about 1.5× slower per call). The other gap found, `oauth2`/`openIdConnect` schemes, was closed in generator 1.3.0. What is still open is listed under [Where we are not better](#where-we-are-not-better).

State at the time of writing: sdk-runtime 1.1.1, sdk-generator 1.3.0 and typedarray 1.1.0, all on Packagist.

## What the audit changed

Running the same scenarios against both tools found weaknesses in ours, which were fixed and released:

- A field the schema does not declare broke a call; answers now ignore it ([D14](DECISIONS.md)). Hand-built models stay strict.
- Bad answers surfaced as four unrelated exception types; they are now one `InvalidResponseException` that keeps the raw response.
- A call was about 25% slower than it needed to be; class kinds and property types are cached per class and collection items are validated once.
- The generator rejected `oauth2` and `openIdConnect` schemes; it supports them since 1.3.0.
- The typed-collection package had bugs (`exchangeArray()` skipped validation, interfaces were refused, `(string) $array` threw on PHP 8); typedarray 1.1.0 fixes them and adds union types.
- Unreferenced code was removed from the generator (see [Junk code](#3-junk-code)).

## Method

Three specs (two OpenAPI 3.0, one 3.1) were fed to both tools. `accounts` and `catalog` are small (2 routes); `casafari` is a real, large API (96 operations, 2,244 model properties). Everything is reproducible from `audit/`:

```bash
java -jar openapi-generator-cli-7.10.0.jar generate -g php -i audit/specs/accounts-openapi30.json -o out/oag-accounts-openapi30 --skip-validate-spec
# our generator, same spec, into out/ours-accounts-openapi30 (see sdk-generator docs)
python3 audit/measure.py out/oag-accounts-openapi30 out/ours-accounts-openapi30
AUDIT_AUTOLOAD=… AUDIT_OUT=out php audit/scenarios.php   # identical user scenarios, mocked HTTP
AUDIT_AUTOLOAD=… AUDIT_OUT=out php audit/bench.php ours   # and: oag
```

`measure.py` strips comments and counts only PHP code. "Repeated lines" are code lines that occur more than once inside the same SDK.

## 1. Generated code

| Spec | Tool | Files | PHP code lines | Methods | Untyped params | Untyped props | Repeated-line share |
| --- | --- | ---: | ---: | ---: | ---: | ---: | ---: |
| accounts | OpenAPI Generator | 27 | 1,363 | 171 | 86 / 148 | 41 / 47 | 46% |
| accounts | **ours** | 13 | **128** | 8 | **0** / 8 | **0** / 14 | 17% |
| catalog | OpenAPI Generator | 27 | 1,403 | 177 | 89 / 151 | 41 / 47 | 47% |
| catalog | **ours** | 13 | **133** | 8 | **0** / 8 | **0** / 17 | 22% |
| casafari | OpenAPI Generator | 744 | 62,116 | 8,116 | 3,250 / 4,832 | 1,482 / 1,886 | 89% |
| casafari | **ours** | 939 | **14,343** | 96 | **0** / 94 | **0** / 2,244 | 72% |

- **4–10× less generated PHP** (10.7× on the small specs, 4.3× on Casafari). Serialization, validation, auth, retries and error handling live once in the runtime, not in every SDK.
- **Fully typed.** Ours has no untyped parameter or property. OpenAPI Generator leaves ~60% of parameters and ~80% of properties untyped.
- **Methods:** 96 for 96 Casafari operations (one per route). OpenAPI Generator emits 8,116 (getters, setters, `*WithHttpInfo`, `*Async`, `*Request` variants).
- Our file count on Casafari is higher because each model is its own small class; code lines are what a reader and a reviewer carry.

## 2. Ease of use

The same user scenarios were run against both SDKs with a mocked HTTP client (`audit/scenarios.php`). "http" is the number of requests actually sent.

| Scenario | OpenAPI Generator | Ours |
| --- | --- | --- |
| Typed GET, create from a model | works | works |
| Required field missing in the request model | **sends the request** (http=1) | rejected before sending (http=0), names model and field |
| Wrong type for a path id (`"abc"` for an int) | **sends the request** | `TypeError`, nothing sent |
| Value outside an enum | `InvalidArgumentException` | `TypeError` (typed enum property) |
| 404 with a JSON body | `ApiException` (body as text) | `ApiException` with the decoded error model in `$payload` and the raw response |
| Server adds an unknown field | works | works (ignored when reading) |
| `id` is `"x"` where an int is declared | **silently returns `'0'`** | `InvalidResponseException` with the response |
| Empty body on a 200 | `ApiException` (message only) | `InvalidResponseException` with the response and the JSON error |

What this means for a developer:

- Mistakes are caught on the client, before a network call, and the message names the field.
- A bad answer is never silently coerced. One exception type (`InvalidResponseException`) covers every unreadable success body and keeps the raw response.
- Typed properties, enums and collections work with IDE completion and static analysis.
- Documentation is written once for every SDK (`docs/`) rather than generated per SDK, and every public name is covered by a snapshot test.

Two weaknesses found by running these scenarios were fixed as a result: unknown response fields used to throw, and bad answers surfaced as four unrelated exception types ([D14](DECISIONS.md)).

## 3. Junk code

- Generated SDKs carry no tests, CI files, `git_push.sh`, composer boilerplate or per-SDK docs: 13 files instead of 27 for the small specs; `src` only.
- Runtime and generator were reviewed for dead code, over-long methods, over-long argument lists and duplicate logic ([DECISIONS.md](DECISIONS.md), R1–R5, D1–D13). `ApiClient` went from 797 to 363 lines; no public constructor takes more than 6 arguments (configuration objects only); the only long constructor (`Operation`, 28 properties) is private.
- In `sdk-generator`, superseded milestone documents, the Spanish quickstart and the benchmarks of the old architecture were deleted. A dead-code sweep checked every class, method and constant for a reference anywhere in `src`, `tests`, `ci` and the docs, and every property for a read. The runtime had nothing unreferenced (three public `DTO` methods are API for users). The generator had a class and five methods, all removed (generator PR #68). A coverage run of the runtime's 514 tests found 16 methods the suite never executes; all are reachable or public API, so they are untested, not dead. Three contract fields in the generator are stored but not read (`unparsedSchema`, `hasDefault`, `links`); they describe the parsed document and were kept.

## Where we are not better

1. **Speed.** One mocked call through Guzzle, measured by the manual workflow `audit-speed.yml` (same runner, PHP 8.4, our SDK from the published generator 1.6.0 and runtime 1.4.2 against OpenAPI Generator 7.26.0, best of 7 interleaved runs of 20,000 calls): OpenAPI Generator 30.6 µs, ours 44.2 µs (≈1.44×). Initialization (≈0.7–1 ms) and peak memory (4.0 MiB) are equal. The gap did not move with the validation change (D19): validation was never the main cost. What OpenAPI Generator does not do per call is what we do: security selection, error decoding, retry and redirect policies, and checking the answer. We claim no performance advantage. To repeat the measurement: Actions → Audit speed → Run workflow. **Where the time goes** (Actions → Audit speed → profile mode: Xdebug, PHP 8.4, one `getById`): Guzzle 48% of the self time (both SDKs pay it), our runtime 40%, PHP built-ins 8%, typedarray 2%, the generated SDK 0.4%. Inclusive: the transport 44% (Guzzle's dispatch alone 36%), building the answer's models 22%, building the request 6%, wrapping the response 4%. **What we tried:** a fast path for scalars of the declared type (−5.7% on PHP 8.3 without hooks, −1.2% on PHP 8.4 with hooks, measured in CI against the published runtime), caching a collection's item type (−2%), skipping the level check for models with nothing to check, caching the discriminator test and guards on the request path (no measurable change). None was worth a release and they were not kept. **What moved it:** hydration generated per model (runtime 1.5.0 with sdk-generator 1.7.0): a generated model converts scalars, enum cases, plain nested models and lists of scalars inline and leaves everything else to the general path. Measured in the same workflow, mode "checkout", on PHP 8.4 (best of 7, same runner, one `getById`): published runtime and generator 53.6 µs, branches 48.4 µs (−9.7%), against 37.4 µs for OpenAPI Generator: about 1.29× instead of 1.43×. Hydrating the answer's models went from about 23% of a call to 16%. **What would move it further:** a flatter transport chain (about 5–8%), and inline handling of lists of models.
2. **Security schemes: supported, with one limit.** Since generator 1.3.0 `oauth2` and `openIdConnect` schemes are validated and generated, and the runtime sends a token or a token provider (`OAuth2ClientCredentials`, `OAuth2AuthorizationCode`) as a Bearer token. The generated SDK does not carry the scheme's endpoints or scopes, so the provider is built by hand with them. Mutual TLS schemes are still not generated.
3. **`lastResponse()` is instance state**, so one client instance must not be shared between concurrent tasks.
4. **Other languages.** OpenAPI Generator targets dozens; we target PHP only.

## Conclusion

On generated code (4–10× less, fully typed), ease of use (client-side validation, one exception for bad answers, decoded errors) and junk (nothing generated that is not needed), the claim holds and is measured. The claim does not hold for speed, which stays on the list above. The generated-code numbers were measured before generator 1.3.0 and do not change for the three audited specs.
