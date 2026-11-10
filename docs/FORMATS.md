# `format` validation

## Policy

JSON Schema 2020-12 and OpenAPI 3.1 treat `format` as an **annotation**: a value is not rejected just because it does not match. This runtime follows that default and adds an **explicit assertion**:

- A model, parameter or response rule `'format' => 'date-time'` (next to `minLength`, `pattern`, ...) asserts the format.
- Without that rule `format` stays documentation. Nothing is asserted implicitly, so existing SDKs keep their behavior.
- A format that is unknown, or that does not apply to the value's type (for example `date` on a number or `int32` on a string), asserts nothing and never fails. `float`, `double`, `password`, `binary` and vendor formats are annotations.
- The `format` rule composes with the others and also applies to list items (`'items' => ['type' => 'string', 'rules' => ['format' => 'uuid']]`).

Which rules a generated SDK carries is the generator's decision: it emits the rule only when asked to assert formats. The runtime only provides the assertion.

## Supported formats

| Source | Formats |
| --- | --- |
| Opis (`FormatResolver`) | `date`, `time`, `date-time`, `duration`, `email`, `idn-email`, `hostname`, `idn-hostname`, `ipv4`, `ipv6`, `uri`, `uri-reference`, `uri-template`, `iri`, `iri-reference`, `uuid`, `json-pointer`, `relative-json-pointer`, `regex` |
| This runtime (OpenAPI legacy) | `byte` (standard base64 with padding, empty allowed), `int32` and `int64` (integer ranges) |

`FormatValidator::asserted()` returns the exact list. Validation is delegated to the maintained Opis resolver, so this table follows the installed Opis version.

## Differences to know

These come from the Opis implementation (2.6.x), reused rather than reimplemented:

- `time` and `date-time` accept a missing UTC offset, although RFC 3339 requires one, and accept a leap second (`:60`). `date` and `date-time` check that the day exists in the month (`2026-02-30` is rejected).
- `duration` accepts a missing leading `P`.
- `email` uses PHP's `FILTER_VALIDATE_EMAIL`.
- `regex` uses PCRE with the `u` flag, not the ECMA 262 dialect, and rejects `\Z`.
- `int64` always holds for PHP integers. JSON numbers beyond the PHP integer range arrive as floats and are rejected by the type rules, not by the format.
- Embedded JSON Schema guards (`schema`, `jsonSchema`) still ignore `format`; use the `format` rule.

## Validation is not conversion

Asserting `date-time` checks the text; it does not turn it into a `DateTimeImmutable`. Models keep the wire type (`string`). Converting to PHP classes would be an optional, separate generator feature and is not part of this runtime.
