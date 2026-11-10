<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

use Opis\JsonSchema\Format;
use Opis\JsonSchema\Resolvers\FormatResolver;

/**
 * Asserts `format` values for the declarative `format` rule. String formats come from the maintained
 * Opis resolver (date, time, date-time, duration, email, hostname, ipv4, ipv6, uri, uuid, json-pointer,
 * regex, their IDN/IRI/reference variants and more); this class only adds the OpenAPI formats Opis
 * lacks: `byte` (base64) and the integer ranges `int32` and `int64`.
 *
 * Following JSON Schema, a format that is unknown, or that does not apply to the value's type, asserts
 * nothing: `float`, `double`, `password`, `binary` and custom formats stay annotations.
 *
 * @internal Not for application code. Frozen only as the contract with sdk-generator of the same major version (see docs/PUBLIC_API.md).
 */
final class FormatValidator
{
    private const string BASE64 = '~^(?:[A-Za-z0-9+/]{4})*(?:[A-Za-z0-9+/]{2}==|[A-Za-z0-9+/]{3}=)?$~D';

    /** @var array<string, array{int, int}> */
    private const array INTEGER_RANGES = [
        'int32' => [-2147483648, 2147483647],
        'int64' => [PHP_INT_MIN, PHP_INT_MAX],
    ];

    private static ?FormatResolver $resolver = null;

    /**
     * Whether the value satisfies the format; null when the format asserts nothing for it.
     */
    public static function check(string $format, mixed $value): ?bool
    {
        if (isset(self::INTEGER_RANGES[$format])) {
            // Integers beyond PHP's range arrive as floats and are rejected by the type rules.
            return is_int($value) ? $value >= self::INTEGER_RANGES[$format][0] && $value <= self::INTEGER_RANGES[$format][1] : null;
        }
        if (!is_string($value)) {
            return null;
        }
        if ($format === 'byte') {
            return preg_match(self::BASE64, $value) === 1;
        }
        $validator = self::resolver()->resolve($format, 'string');
        if ($validator === null) {
            return null;
        }
        return $validator instanceof Format ? $validator->validate($value) : (bool) $validator($value);
    }

    /**
     * Names of the formats that assert something, for tooling that wants to know before emitting a rule.
     *
     * @return list<string>
     */
    public static function asserted(): array
    {
        $names = array_keys(self::resolver()->__serialize()['formats']['string'] ?? []);
        $names = array_merge($names, array_keys(self::INTEGER_RANGES), ['byte']);
        sort($names);
        return array_values(array_unique($names));
    }

    private static function resolver(): FormatResolver
    {
        return self::$resolver ??= new FormatResolver();
    }
}
