# Design decisions

Decisions taken during the quality review that led to 2.0.0. Each entry says what was decided and why, so a later change can be judged against the reason and not only the result. Newest decisions go at the bottom.

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
