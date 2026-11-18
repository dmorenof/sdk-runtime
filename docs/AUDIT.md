# Audit: sdk-runtime + sdk-generator against OpenAPI Generator

Question: is an SDK produced by this project clearly better than one produced by [OpenAPI Generator](https://openapi-generator.tech) (`-g php`, version 7.10.0) in **generated code**, **ease of use** and **junk code**?

Short answer: yes on all three, with two honest exceptions: **speed** (about 2.4× slower per call) and **security scheme coverage** (`oauth2`/`openIdConnect` are not generated yet). Both are listed under [Where we are not better](#where-we-are-not-better).

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
- In `sdk-generator`, superseded milestone documents, the Spanish quickstart and the benchmarks of the old architecture were deleted. A dead-code sweep of the runtime and generator sources has not been done with a tool yet; that is still open.

## Where we are not better

1. **Speed.** One mocked call (`audit/bench.php`, 20,000 iterations, PHP 8.3): OpenAPI Generator 73–87 µs, ours 142–172 µs (≈1.7–2.4×, the spread is run-to-run noise). The extra time is validation of requests and responses, which happens before the HTTP call is made, so an invalid request costs no round trip; init time (≈1–1.5 ms) and peak memory (4.0 MiB) are equal. Against our own 1.0.0 (same spec, same benchmark) a call takes 157–175 µs, so the current runtime is not slower than before. Memory did not grow either: in a clean probe both versions peak at 2.93 MiB (the 4.0 MiB above includes the benchmark harness, identical for both tools). Against real network latency this is noise, but it is slower and we claim no performance advantage.
2. **Security schemes.** OpenAPI Generator supports `oauth2` and `openIdConnect` in generated code; our generator rejects those schemes (the runtime has OAuth2 providers you can use by hand). Until the generator supports them, "any API" is not true for those APIs.
3. **`lastResponse()` is instance state**, so one client instance must not be shared between concurrent tasks.
4. **Other languages.** OpenAPI Generator targets dozens; we target PHP only.

## Conclusion

On generated code (4–10× less, fully typed), ease of use (client-side validation, one exception for bad answers, decoded errors) and junk (nothing generated that is not needed), the claim holds and is measured. The claim does not hold for speed or for oauth2/openIdConnect specs; those stay on the list above.
