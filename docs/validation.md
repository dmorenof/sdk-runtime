# Validation

The runtime checks data against the API's schema at three points, so mistakes surface where they are made and not as a confusing server error.

| When | What is checked | Failure |
| --- | --- | --- |
| Before sending | Parameters, then the request body (required fields, types, rules) | `UnexpectedValueException` (or `InvalidArgumentException` for something that cannot be sent at all); nothing is sent |
| After receiving | The response body against its declared type and rules | `UnexpectedValueException` / `JsonException` |
| When you ask | `$model->validate()` | `UnexpectedValueException` |

Message format: `Validation <rule> failed at <where> (<limit>).` For example `Validation maxLength failed at Acme\Accounts\DTOs\NewUser::name (limit 50).`

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
