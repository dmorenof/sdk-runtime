<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Transport;

use Dmorenof\SdkRuntime\Constraints;
use GuzzleHttp\Cookie\SetCookie;

/**
 * Read-only, case-insensitive view of a response's headers, optionally typed by the
 * operation's declared response headers (OpenAPI `responses[*].headers`).
 *
 * @phpstan-type HeaderSpec array{type:'string'|'integer'|'number'|'boolean'|'array',items?:'string'|'integer'|'number'|'boolean',required?:bool,rules?:array<string,mixed>}
 */
final readonly class ResponseHeaders
{
    /** @var array<string, list<string>> keyed by lower-case name */
    private array $values;

    /** @var array<string, string> lower-case name => name as received */
    private array $names;

    /** @var array<string, HeaderSpec> keyed by lower-case name */
    private array $declared;

    /**
     * @param array<array-key, array<string>|string> $headers As returned by PSR-7 getHeaders(); strings are one value.
     * @param array<string, array<string, mixed>> $declared Header name => declared type, as generated for the selected status; validated here.
     */
    public function __construct(array $headers = [], array $declared = [])
    {
        $values = [];
        $names = [];
        foreach ($headers as $name => $list) {
            $key = strtolower((string) $name);
            $names[$key] ??= (string) $name;
            foreach (is_array($list) ? $list : [$list] as $value) {
                $values[$key][] = (string) $value;
            }
        }
        $specs = [];
        foreach ($declared as $name => $spec) {
            if (!in_array($spec['type'] ?? null, ['string', 'integer', 'number', 'boolean', 'array'], true)
                || ($spec['type'] === 'array' && !in_array($spec['items'] ?? 'string', ['string', 'integer', 'number', 'boolean'], true))) {
                throw new \InvalidArgumentException("Invalid declared response header $name.");
            }
            /** @var HeaderSpec $spec */
            $specs[strtolower((string) $name)] = $spec;
        }
        $this->values = $values;
        $this->names = $names;
        $this->declared = $specs;
    }

    /** @return list<string> Names as received. */
    public function names(): array
    {
        return array_values($this->names);
    }

    public function has(string $name): bool
    {
        return isset($this->values[strtolower($name)]);
    }

    /** @return list<string> Every received line for this header, never comma-split. */
    public function values(string $name): array
    {
        return $this->values[strtolower($name)] ?? [];
    }

    public function first(string $name): ?string
    {
        return $this->values[strtolower($name)][0] ?? null;
    }

    /**
     * Value converted by the declared type. Undeclared headers return their raw first line.
     * Throws when a required header is absent or a value does not match its declaration.
     *
     * @return string|int|float|bool|list<string|int|float|bool>|null Null for an absent optional header.
     */
    public function get(string $name): string|int|float|bool|array|null
    {
        $key = strtolower($name);
        $spec = $this->declared[$key] ?? null;
        $lines = $this->values[$key] ?? [];
        if ($lines === []) {
            if ($spec !== null && ($spec['required'] ?? false)) {
                throw new \UnexpectedValueException("Required response header $name is missing.");
            }
            return null;
        }
        if ($spec === null) {
            return $lines[0];
        }
        // HTTP allows repeated lines to be combined with commas; simple-style lists use the same form.
        $raw = $spec['type'] === 'array' ? implode(',', $lines) : $lines[0];
        $value = $spec['type'] === 'array'
            ? array_map(
                static fn (string $item): string|int|float|bool => self::convert(trim($item, " \t"), $spec['items'] ?? 'string', $name),
                $raw === '' ? [] : explode(',', $raw),
            )
            : self::convert($raw, $spec['type'], $name);
        if (isset($spec['rules'])) {
            Constraints::validate($value, $spec['rules'], "response header $name", 'response');
        }
        return $value;
    }

    /**
     * Set-Cookie lines parsed with Guzzle's SetCookie. Repeated lines stay separate,
     * which only the PSR-18 transport preserves; legacy transports join them.
     *
     * @return list<SetCookie>
     */
    public function cookies(): array
    {
        return array_map(static fn (string $line): SetCookie => SetCookie::fromString($line), $this->values('Set-Cookie'));
    }

    private static function convert(string $raw, string $type, string $name): string|int|float|bool
    {
        return match ($type) {
            'string' => $raw,
            'integer' => preg_match('/^-?\d+$/', $raw) === 1 && (string) (int) $raw === ltrim($raw, '+')
                ? (int) $raw
                : throw new \UnexpectedValueException("Response header $name is not an integer."),
            'number' => is_numeric($raw) && is_finite((float) $raw) && preg_match('/^-?\d+(\.\d+)?([eE][+-]?\d+)?$/', $raw) === 1
                ? (preg_match('/^-?\d+$/', $raw) === 1 ? (int) $raw : (float) $raw)
                : throw new \UnexpectedValueException("Response header $name is not a number."),
            'boolean' => match (strtolower($raw)) {
                'true' => true,
                'false' => false,
                default => throw new \UnexpectedValueException("Response header $name is not a boolean."),
            },
            default => throw new \InvalidArgumentException("Unsupported response header type $type."),
        };
    }

    /** @return array<string, int> Header names only: values may be sensitive. */
    public function __debugInfo(): array
    {
        return array_map('count', $this->values);
    }
}
