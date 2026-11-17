# Troubleshooting

Messages you may meet, what they mean and what to do. Each message is quoted as the runtime writes it; `…` stands for names that depend on your SDK.

## Before a request is sent

| Message | Cause | Fix |
| --- | --- | --- |
| `No configured credentials satisfy this operation security requirement.` | The operation needs credentials and none of its alternatives can be built from `securityCredentials`. | Add the credential under the scheme name the API uses. See [Authentication](authentication.md). |
| `Required JSON property …::name is absent.` | A required field of the body was never set. | Set it before calling. |
| `Validation <rule> failed at … (limit …).` | A value breaks a rule of the schema (`minLength`, `pattern`, `enum`, a format…). | Fix the value. See [Validation](validation.md). |
| `Unknown JSON property …::field.` | A model was given a field its schema does not declare. | Remove it, or regenerate the SDK if the API added it. |
| `Missing path parameter for …` / `Path parameter … cannot be null.` | A path value is missing. | Pass it. |
| `Query array … requires declared serialization metadata.` | A list or object was passed where the API declares no way to write it. | Pass a scalar, or fix the API's specification. |
| `Conflicting values for request cookie …` | The same cookie name arrives twice with different values (default header, parameter, API key). | Send it once. See [Sending data](sending-data.md#cookies). |
| `Server variable … must be one of: …` | A value in `serverVariables` is not allowed by the API. | Use one of the listed values. |
| `… declares no server #3` / `… declares no server named 'x'` | `Configuration::$server` names a server the operation does not have. | Use an index or name the API declares. |

## When you connect

| Message | Cause | Fix |
| --- | --- | --- |
| `Configuration::baseUrl must not be empty.` | No base URL, and the SDK has no default (the API declares only relative servers). | Set `baseUrl`. |
| `Middleware, retry and redirect policies need the default PSR-18 transport.` | `middleware`, `retryPolicy` or `redirectPolicy` were set together with a custom `transport`. | Drop the custom `transport` and pass your PSR-18 client as `httpClient`. |
| `Server variables map nonempty names to string values.` | `serverVariables` holds something other than strings. | Use `['name' => 'value']`. |
| `Resource must belong to this SDK: …` | `resource()` was given a class from another SDK. | Use a resource of the same SDK. |
| `OAuth2 … must be an absolute https URL …` | A token, authorization or issuer URL is not https (or localhost). | Use https. |
| `LogicException: Configure the SDK with Client::configure(Configuration) first.` | A static facade was used before `configure()`. | Call `configure()` once at start-up. |

## After the response arrives

| Exception | Cause | Fix |
| --- | --- | --- |
| `ApiException: Unexpected HTTP status from GET /…: 404` | The API answered a status the operation does not declare as success. | Read `$e->response` and `$e->payload`. See [Responses](responses.md). |
| `JsonException: Syntax error` | A success body was empty or not JSON, but the API promised JSON. | Check the endpoint. The raw body is not attached to this exception. |
| `UnexpectedValueException: Invalid scalar value at …: expected int.` | The body has a value of the wrong type. | The API and its specification disagree; regenerate or report it. |
| `InvalidArgumentException: Unknown JSON property …` | The body has a field the schema does not declare. | Regenerate the SDK. See [Using an SDK](using-an-sdk.md#unknown-properties). |
| `ValueError: "x" is not a valid backing value for enum …` | The body has an enum value the schema does not list. | Regenerate the SDK. |
| `UnexpectedValueException: Response media type '…' is not declared for … (status 200).` | The server answered with a media type the operation does not declare. | Check the `Content-Type`; see [Responses](responses.md#one-status-several-media-types). |
| `RedirectException` | A redirect was refused (downgrade, too many, cross-origin). | See [Transport](transport.md#redirects-opt-in). |
| `ReauthorizationRequiredException` | OAuth2: no token, or the refresh token was rejected. | Send the user through the authorization flow again. |
| `Error: Typed property …::name must not be accessed before initialization` | A model property that was never set was read. | Test with `isset()`. |

## Still stuck

Capture one failing exchange with a [middleware](transport.md#middleware) that logs `$request->getMethod()`, `(string) $request->getUri()` and `$response->getStatusCode()`, and compare it with the API's documentation. Never log `Authorization` or `Cookie` headers.
