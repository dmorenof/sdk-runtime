# Validation

Validation is about what **you** put into a model, and it happens where you put it: a mistake surfaces where it is made and not as a confusing server error. **A call only serializes the request, and an answer is read as the API sent it.**

| When | What is checked | Failure |
| --- | --- | --- |
| You assign a property (`$user->age = 5`) | The rules of that property, once, through a PHP 8.4 `set` hook that the generator writes on every property that has rules | `UnexpectedValueException`; the value is not stored |
| You build a model from JSON (`new Model($json)`) | Each property as it is set (same hook), then which fields are required and the rules of the model as a whole | `UnexpectedValueException` |
| A collection or map changes (`append`, `$list[] = x`, `$map['k'] = v`) | The item rules, and the rules of the whole collection | `UnexpectedValueException` |
| You ask: `$model->validate()` | Everything, including what is inside the model | `UnexpectedValueException` |
| A request is sent | **Nothing.** The parameters and the body are serialized (JSON, a form, multipart, raw bytes) as they are. Only what cannot be put on the wire at all fails: a missing path parameter, a value a format cannot express | `InvalidArgumentException` or `UnexpectedValueException`; nothing is sent |
| An answer is read | **Nothing of ours**: no rule, no required field, no model check. The API has the last word; if it is wrong, the API's owner fixes it at the source. Only PHP's own errors refuse a value that does not fit its type (`TypeError`), or an enum case that does not exist (`ValueError`) | `InvalidResponseException`, with the raw response and the original error as its cause |

`json_encode($model)` and `(string) $model` only serialize.

What a hook cannot see or allow: a list stored in a plain array property that is changed in place (`$model->ids[] = 1`) is not checked, because the generator puts no hook on array properties (PHP raises an Error when a hooked property is edited in place); use a typed collection for lists with rules (its `append` and `offsetSet` check), or assign the whole list. A property that has a hook cannot be unset: `unset($model->name)` is an Error in PHP 8.4, so assign a value instead. Call `validate()` when you want everything looked at.

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

A model you build is checked when built and when you assign to it; it is not checked again when a request is sent, so `$model->validate()` is the way to check a whole model you changed in place. `json_encode($model)` and `(string) $model` only serialize. Direction matters: `readOnly` fields are not required or sent in a request; `writeOnly` fields are not expected in a response.

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

There is nothing to turn off in a call or in an answer: they are not validated. What you assign is, and that is the part that catches your mistakes; if a rule in a generated SDK is wrong, fix the specification or regenerate.
