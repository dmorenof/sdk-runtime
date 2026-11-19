# Validation

A model checks itself when a value goes into it, so a mistake surfaces where it is made and not as a confusing server error. Nothing is checked again when a request is sent.

| When | What is checked | Failure |
| --- | --- | --- |
| You assign a property (`$user->age = 5`) | The rules of that property, once, through a PHP 8.4 `set` hook that the generator writes on every property that has rules | `UnexpectedValueException`; the value is not stored |
| A model is built from JSON (an answer, or `new Model($json)`) | Each property as it is set (same hook), then which fields are required and the rules of the model as a whole. Models and collections inside it check themselves as they are built | `UnexpectedValueException` |
| A collection or map changes (`append`, `$list[] = x`, `$map['k'] = v`) | The item rules, and the rules of the whole collection | `UnexpectedValueException` |
| A request is sent | The parameters and the operation input (its required fields). The body is not walked | `UnexpectedValueException` (or `InvalidArgumentException` for something that cannot be sent at all); nothing is sent |
| You ask: `$model->validate()` | Everything, including what is inside the model | `UnexpectedValueException` |

`json_encode($model)` and `(string) $model` only serialize.

What a hook cannot see: a list stored in a plain array property, changed in place (`$model->ids[] = 1`), and a property you `unset`. Use a typed collection for lists with rules (its `append` and `offsetSet` check), and call `validate()` when you want everything looked at.

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

Models are checked when built from JSON and again when a request is sent, so a value you changed after loading the model is still validated. `json_encode($model)` and `(string) $model` only serialize; call `$model->validate()` first if you want the check. Direction matters: `readOnly` fields are not required or sent in a request; `writeOnly` fields are not expected in a response.

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
