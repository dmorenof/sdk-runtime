# Validation

The runtime checks data against the API's schema at three points, so mistakes surface where they are made and not as a confusing server error.

| When | What is checked | Failure |
| --- | --- | --- |
| Before sending | Parameters, then the request body (required fields, types, rules) | `UnexpectedValueException` (or `InvalidArgumentException` for something that cannot be sent at all); nothing is sent |
| After receiving | The response body against its declared type and rules | `UnexpectedValueException` / `JsonException` |
| When you ask | `$model->validate()` | `UnexpectedValueException` |

Message format: `Validation <rule> failed at <where> (<limit>).` For example `Validation maxLength failed at Acme\Accounts\DTOs\NewUser::name (limit 50).`

## When a model is validated

A model is a tree: a model can hold models and collections, and validating one validates everything below it. Each level is checked **once** per event below; nothing is checked when you assign a property (PHP's own type check applies, the rules do not).

| Event | What is validated | How often |
| --- | --- | --- |
| You call an operation with a body or parameters object | The body and the parameters object, in request mode (read-only fields skipped), before anything is sent | Once per call |
| An answer arrives | The model it hydrates into, in response mode (write-only fields skipped) | Once per call |
| An error answer with a documented model | The error model | Once, when the exception is built |
| `new Model($json)` or `Model::fromValue($json)` | The whole tree | Once, when the top model is built (nested models do not repeat it) |
| `$model->validate()`, `validateForRequest()`, `validateForResponse()` | The whole tree | Each time you call it |
| `json_encode($model)` or `(string) $model` | The whole tree, then it is serialized | Each time: a model is mutable, so the result of an earlier check cannot be reused |
| `$collection[] = $item`, `new Collection([...])` | The new items' type and item rules | At that moment, and again when a model holding the collection is validated |
| A streamed answer | Each item, as it arrives | Once per item |

A model with no rules, no required fields and nothing nested that could have them is skipped at no cost. Not validated: a model you only read, a model you only assign to, and models inside a request you never send.

## Rules

Rules come from the API's schema. You do not write them; this is what they mean.

| Rule | Applies to | Meaning |
| --- | --- | --- |
| `type`, `nullable`, required fields | all | Scalar type, whether `null` is allowed, which fields must be present |
| `enum`, `const` | all | One of the listed values / exactly this value |
| `minLength`, `maxLength` | strings | Length in Unicode characters (not bytes) |
| `pattern` | strings | Regular expression (PCRE; see [Differences](#differences-from-the-schema-language)) |
| `format` | strings, integers | See [Formats](#formats) |
| `minimum`, `maximum`, `exclusiveMinimum`, `exclusiveMaximum`, `multipleOf` | numbers | Range and step |
| `minItems`, `maxItems`, `uniqueItems` | lists | Count, and no duplicates |
| `minProperties`, `maxProperties` | objects, maps | Number of fields |
| composition and conditions (`allOf`, `anyOf`, `oneOf`, `not`, `if`/`then`/`else`, `contains`, dependent rules, tuple prefixes, `propertyNames`…) | all | Evaluated from the original schema by an embedded JSON Schema validator |

Models also re-check on every send, so a value you changed after loading the model is still validated. Direction matters: `readOnly` fields are not required or sent in a request; `writeOnly` fields are not expected in a response.

## Formats

`format` (`date-time`, `uuid`, `email`, `int64`…) is documentation by default, as JSON Schema and OpenAPI 3.1 specify. A generated SDK asserts formats only if its generator was asked to (`--validate-formats`). When asserted:

- an unknown format, or one that does not apply to the value's type, asserts nothing and never fails;
- `float`, `double`, `password` and `binary` are never asserted;
- validation checks the text only: `date-time` stays a `string`, it is not turned into a `DateTimeImmutable`.

Supported formats, and where they differ from the standard, are listed in [FORMATS.md](FORMATS.md).

## Differences from the schema language

- `pattern` uses PCRE with the `u` flag, not the ECMA-262 dialect JSON Schema names. Most expressions behave the same; a few (`\Z`, some Unicode escapes) do not.
- Numbers beyond the PHP integer range arrive as floats and fail integer rules; they are not silently truncated.
- There is no remote schema loading: only the rules generated into the SDK are evaluated.

## Turning validation off

There is no switch. Validation is part of the SDK's contract with its API. If a rule in a generated SDK is wrong, fix the specification or regenerate; do not bypass it.
