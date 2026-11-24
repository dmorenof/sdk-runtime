# Why this runtime, and not a generic generator

A generic generator (OpenAPI Generator is the usual choice) gives you every feature of the standard and every language. This runtime and its [generator](https://github.com/dmorenof/sdk-generator) do one thing, PHP 8.4, and spend the savings on what you notice: **less code on disk, less memory, a call that is as fast or faster, and less to write**.

Everything below was measured on a real contract, not a toy: Casafari's published OpenAPI 3.1 (46 operations), against **OpenAPI Generator 7.26** (PHP, Guzzle, `modern` mode) generating from the same file. Each measurement is a clean PHP 8.4 process on a GitHub Actions runner, with the same mocked answer on both sides; speed is the median of 7 runs, and only ratios taken **inside one run** are compared, because a shared runner's absolute times drift. You can repeat it: the `audit-casafari` workflow of this repository (Actions → Audit Casafari) does exactly this.

## What it costs you

| | This runtime + generator | OpenAPI Generator |
| --- | ---: | ---: |
| Generated code | **1.10 MB** in 928 small files | 4.74 MB in 218 files |
| PHP code + dependencies | **4.3 MB** | 6.9 MB |
| PHP memory at the peak of a call that builds nested models | **2.5 MB** | 10 MB |
| Memory growth over 5,000 such calls | **4 KiB** | 163 KiB |
| Process peak (RSS) for that call | **60.8 MB** | 68.5 MB |
| Starting the client | **0.57–0.96×** the time | 1× |
| Time of a call (a flat model; nested models) | **0.74×** and 1.01× | 1× |

About a fifth of the generated code and 37 % less PHP to install, because the engine (transport, authentication, hydration, errors, retries) is written once in this runtime and the SDK only declares its models and operations. A generic generator repeats that engine in every SDK it makes, and writes several variants of every call (OpenAPI Generator's PHP client has a plain, a `WithHttpInfo`, an `Async` and an `AsyncWithHttpInfo` method per operation).

Two things the table does not hide: the dependencies alone weigh more here (3.2 MB of PHP against 2.1 MB, because the runtime is a full engine and not just Guzzle), and the call is faster only on the flat model (a quarter less time); on nested models it is level. What you gain is memory, disk and start-up, and above all less to write. This is one run on a shared runner: an earlier run of the same code gave 0.96× and 1.07×, so read the calls as "between a quarter faster and level", not as a precise figure.

## More APIs, measured the same way

Casafari is one contract. Three public, free ones repeat the measurement (workflow *Audit public APIs*, `audit-apis.yml`): same method (OpenAPI Generator 7.26, PHP 8.4, clean process, median of 7, a **real answer captured once** and served from a mock to both, and a check that the model comes back with what was sent). Each generated SDK is made from the contract as published; none of the 149 operations of the three was left out.

| | This runtime + generator | OpenAPI Generator |
| --- | ---: | ---: |
| **PokeAPI** (OpenAPI 3.1, 102 operations): generated code | **0.88 MB** in 788 files | 5.15 MB in 301 files |
| PokeAPI: PHP code + dependencies | **4.1 MB** | 7.0 MB |
| PokeAPI: one Pokémon (350 KB of JSON), time / peak memory | **4.2 ms (0.47×) / 9.8 MB (0.68×)** | 8.8 ms / 14.4 MB |
| PokeAPI: a list of 20, time / peak memory | **48 µs (0.76×) / 2.4 MB (0.53×)** | 63 µs / 4.6 MB |
| **Wikimedia** (Swagger 2.0, 35 operations): generated code / PHP code + dependencies | **0.25 MB / 3.5 MB** | 1.8 MB / 3.8 MB |
| Wikimedia: a short list / a top of 1,000, time | **0.52× / 0.53×** | 1× |
| **met.no** (Swagger 2.0 without a host, 12 operations): generated code / PHP code + dependencies | **55 KB / 3.3 MB** | 377 KB / **2.5 MB** |
| met.no: a 39 KB / 61 KB forecast, time / peak memory | **0.34× / 0.48×** and **0.37× / 0.49×** | 1× |
| Memory growth over thousands of calls | **4 KiB** | 1,575 and 3,130 KiB (PokeAPI list, met.no) |

What this says, and does not:

- **Time and memory per call favour this runtime in all three**, most where the answer is big: building a large nested answer is where a client that goes through setters pays most. Two runs of PokeAPI agree (0.46× and 0.47×).
- **Size is measured in bytes of PHP, never with `du`.** `du` counts the blocks the file system reserves, which depends on the disk and punishes many small files; what a user installs or downloads is the bytes. The generated code is 6× to 7× smaller everywhere. The runtime and its dependencies are 3.2 MB of PHP against Guzzle's 2.1 MB, **but the runtime is paid once**: for one API, ours is smaller from about 30 operations up (PokeAPI −42 %, Casafari −37 %, Wikimedia −10 %) and larger on a very small one (met.no alone: +32 %); with the three public APIs in one project it is 4.4 MB against 9.1 MB (**−52 %**), and each further API adds only its own code. Compressed (what Composer downloads), the three together are level: 687 KB against 705 KB, because the dependencies dominate.
- **Starting is slower on the smallest APIs** (Wikimedia 1.24–1.29×) and the first call in a fresh process about 1.4×, because more files are loaded (55–100 against 29–57).

## What you write

**Authentication that works.** The API declares OAuth2 client credentials, so you give the SDK a client id and a secret and it fetches the token, keeps it and renews it before it expires, replaying a call once after a `401`:

```php
$configuration->securityCredentials = new SecurityCredentials([
    'OAuth2ClientCredentials' => new OAuth2ClientCredentials(
        new OAuth2Endpoints('https://api.casafari.com/oauth/token'),
        new OAuth2Client($clientId, $clientSecret),
        new OAuth2Options(scopes: ['api']),
    ),
]);
```

OpenAPI Generator's client gives you `setAccessToken($token)`: getting the token, storing it and renewing it every 30 minutes is your code. The same goes for the authorization code flow with PKCE, OpenID Connect discovery, ID token validation and scope checks, which this runtime has.

**Models you use like PHP.** A property is a property, typed natively, with no getters and setters:

```php
$location->name;                 // string
$params->latitude = 41.3874;     // a float; "north" is a TypeError, 91 is refused
```

In OpenAPI Generator's model the same read is `$location->getName()`, and `$model->setLocationId('abc')` on an integer field stores the string without complaint: its setters have untyped parameters and check `null` and the limits the contract declares, not the PHP type of the value.

**An answer that does not fit fails loudly.** If a server sends `"location_id": "abc"` for an integer, this runtime raises `InvalidResponseException` carrying the raw response; OpenAPI Generator's client turns it into `0` and carries on. (Details of what is and is not checked, and why an answer is otherwise trusted, in [Validation](validation.md).)

**Policies, not plumbing.** Retries with `Retry-After`, redirects with the safeguards a browser has, middleware, proxies, servers and server variables are one `Configuration` object. The generated PHP client of OpenAPI Generator has none of these; you configure Guzzle yourself.

## Where a generic generator is the better choice

We would rather you choose well than choose us:

- **You are on PHP older than 8.4.** This runtime needs 8.4 (property hooks); OpenAPI Generator's client runs on PHP 8.1.
- **Your contract uses something we refuse.** This generator is a deliberate subset of OpenAPI and rejects, with a message, what it does not support (remote `$ref`, structured XML models and some exotic compositions; see [Supported scope](https://github.com/dmorenof/sdk-generator/blob/main/docs/SUPPORTED_SCOPE.md)). OpenAPI Generator covers more of the standard.
- **You need another language.** This is PHP only.
- **The first call matters more than the next thousand.** A first call in a fresh process takes about 1.35–1.45× as long (4.9 ms against 3.6–3.4 ms), because more files are loaded (54–57 against 29–30); with OPcache this mostly disappears, but we did not measure that.
- **You want answers checked.** An answer is read as the API sent it (decision D25): PHP's own type errors still refuse a value that does not fit, but a rule such as a length or a range is not checked on a response, and a field the API left out is simply not set. If your API is not trustworthy, validate in your own code.

## Why it is cheaper

- **One engine.** Hydration, serialization, authentication and errors are in the runtime; a fix reaches every SDK with a `composer update`.
- **Nothing is checked twice.** A value is checked where it enters a model (a PHP 8.4 `set` hook). A call only serializes, and an answer is read without a second pass.
- **Inline hydration.** The generator writes, per model, a `match` that converts the common members directly, and leaves only the unusual ones to the general path.
- **Typed collections and maps** reuse one small library, [TypedArray](https://github.com/dmorenof/TypedArray), instead of generating a class of array plumbing per list.
- **Only what you use is loaded.** Classes load on demand, and the installed package leaves out its tests and development files.
