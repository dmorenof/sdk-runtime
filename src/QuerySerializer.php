<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

use GuzzleHttp\Psr7\Query;

/**
 * Query scalars and explicitly declared lists. Item encoding precedes CSV joining.
 *
 * @internal Implementation detail: not covered by the backward-compatibility promise (see docs/PUBLIC_API.md).
 */
final class QuerySerializer
{
    /**
     * @param array<array-key, scalar|list<scalar>|DTO|Map|TypedArray|\stdClass|null> $parameters
     * @param array<array-key, 'multi'|'csv'|'ssv'|'tsv'|'pipes'|'deepObject'|'form-object'|'form-object-explode'> $formats
     * @param array<string, true> $reserved Names whose data uses reserved expansion.
     * @param \Closure(string): string $encode
     */
    private function __construct(
        private readonly array $parameters,
        private readonly array $formats,
        private readonly array $reserved,
        private readonly \Closure $encode,
    ) {}

    /**
     * @param array<array-key, scalar|list<scalar>|DTO|Map|TypedArray|\stdClass|null> $parameters
     * @param array<array-key, 'multi'|'csv'|'ssv'|'tsv'|'pipes'|'deepObject'|'form-object'|'form-object-explode'> $formats Query name => normalized wire format.
     * @param list<string> $allowReserved Query names whose data uses reserved expansion.
     */
    public static function encode(array $parameters, array $formats = [], int $encoding = PHP_QUERY_RFC3986, array $allowReserved = []): string
    {
        $encode = match ($encoding) {
            PHP_QUERY_RFC3986 => rawurlencode(...),
            PHP_QUERY_RFC1738 => urlencode(...),
            default => throw new \InvalidArgumentException('Unsupported query percent-encoding standard.'),
        };
        if ($allowReserved !== [] && $encoding !== PHP_QUERY_RFC3986) {
            throw new \InvalidArgumentException('Reserved expansion requires RFC3986 encoding.');
        }
        foreach ($formats as $name => $format) {
            self::assertFormat($format, (string) $name);
        }
        $reserved = self::reservedNames($allowReserved); // invalid metadata is refused even when there is nothing to write
        if ($parameters === []) {
            return '';
        }
        $serializer = new self($parameters, $formats, $reserved, $encode);
        $pairs = [];
        foreach ($parameters as $name => $value) {
            if ($value !== null) {
                $pairs = $serializer->add($pairs, $name, $value);
            }
        }
        return Query::build($pairs, false);
    }

    /**
     * Adds the query pairs of one parameter to those already built.
     *
     * @param array<string, string|list<string>> $pairs
     * @return array<string, string|list<string>>
     */
    private function add(array $pairs, int|string $name, mixed $value): array
    {
        $format = $this->formats[$name] ?? null;
        $encodeValue = $this->valueEncoder($name, $format);
        $encodedName = ($this->encode)((string) $name);
        if ($format === null) {
            if (is_array($value)) {
                throw new \UnexpectedValueException("Query array $name requires declared serialization metadata.");
            }
            $pairs[$encodedName] = $encodeValue(ParameterValues::scalar($value));
            return $pairs;
        }
        return match ($format) {
            'form-object', 'form-object-explode' => $this->addObject($pairs, $name, $value, $format, $encodeValue),
            'deepObject' => $this->addDeepObject($pairs, $name, $value, $encodeValue),
            default => $this->addList($pairs, $name, $value, $format, $encodeValue),
        };
    }

    /**
     * The encoder for a parameter's values: reserved expansion keeps the characters its delimiter may need.
     *
     * @return \Closure(string): string
     */
    private function valueEncoder(int|string $name, ?string $format): \Closure
    {
        if (!isset($this->reserved[$name])) {
            return $this->encode;
        }
        $delimiter = match ($format) { 'ssv' => ' ', 'tsv' => "\t", 'pipes' => '|', default => null };
        $reservedDelimiter = in_array($format, ['csv', 'form-object'], true) ? ',' : $delimiter;
        return static fn (string $text): string => self::reserved($text, $reservedDelimiter);
    }

    /**
     * @param array<string, string|list<string>> $pairs
     * @param 'form-object'|'form-object-explode' $format
     * @param \Closure(string): string $encodeValue
     * @return array<string, string|list<string>>
     */
    private function addObject(array $pairs, int|string $name, mixed $value, string $format, \Closure $encodeValue): array
    {
        $items = [];
        foreach (ParameterValues::objectFields($value, (string) $name, deep: false) as $key => $item) {
            $encodedKey = $format === 'form-object' ? $encodeValue((string) $key) : ($this->encode)((string) $key);
            if ($format === 'form-object-explode') {
                if (((string) $key !== (string) $name && array_key_exists($key, $this->parameters)) || array_key_exists($encodedKey, $pairs)) {
                    throw new \UnexpectedValueException("Expanded query property $key collides with another parameter.");
                }
                $pairs[$encodedKey] = $encodeValue(ParameterValues::scalar($item));
            } else {
                $items[] = $encodedKey;
                $items[] = $encodeValue(ParameterValues::scalar($item));
            }
        }
        if ($items !== []) {
            $pairs[($this->encode)((string) $name)] = implode(',', $items);
        }
        return $pairs;
    }

    /**
     * @param array<string, string|list<string>> $pairs
     * @param \Closure(string): string $encodeValue
     * @return array<string, string|list<string>>
     */
    private function addDeepObject(array $pairs, int|string $name, mixed $value, \Closure $encodeValue): array
    {
        foreach (ParameterValues::objectFields($value, (string) $name) as $key => $item) {
            $expanded = $name . '[' . $key . ']';
            if (array_key_exists($expanded, $this->parameters)) {
                throw new \UnexpectedValueException("Deep query parameter $expanded collides with another query parameter.");
            }
            $pairs[($this->encode)($expanded)] = $encodeValue(ParameterValues::scalar($item));
        }
        return $pairs;
    }

    /**
     * @param array<string, string|list<string>> $pairs
     * @param \Closure(string): string $encodeValue
     * @return array<string, string|list<string>>
     */
    private function addList(array $pairs, int|string $name, mixed $value, string $format, \Closure $encodeValue): array
    {
        $delimiter = match ($format) { 'ssv' => ' ', 'tsv' => "\t", 'pipes' => '|', default => null };
        $items = ParameterValues::encodedList($value, (string) $name, $delimiter, $encodeValue);
        if ($items === []) {
            return $pairs; // Empty list expansion has no URI-template members.
        }
        $encodedName = ($this->encode)((string) $name);
        $pairs[$encodedName] = $format === 'multi' ? $items : implode($delimiter === null ? ',' : ($this->encode)($delimiter), $items);
        return $pairs;
    }

    /**
     * @param array<array-key,mixed> $names
     * @return array<string,true>
     */
    private static function reservedNames(array $names): array
    {
        $result = [];
        foreach ($names as $name) {
            if (!is_string($name) || $name === '') {
                throw new \InvalidArgumentException('Invalid reserved query parameter metadata.');
            }
            $result[$name] = true;
        }
        return $result;
    }

    /** OpenAPI query data, not a second URI-template parser. Names use normal encoding. */
    private static function reserved(string $text, ?string $delimiter): string
    {
        if (strpbrk($text, '#[]&+=' . ($delimiter ?? '')) !== false
            || ($delimiter !== null && $delimiter !== ',' && str_contains(rawurldecode($text), $delimiter))) {
            throw new \UnexpectedValueException('Reserved query data must pre-encode forbidden characters and preserve unambiguous list delimiters.');
        }
        $encoded = strtr(rawurlencode($text), [
            '%3A' => ':', '%2F' => '/', '%3F' => '?', '%40' => '@', '%21' => '!', '%24' => '$',
            '%27' => "'", '%28' => '(', '%29' => ')', '%2A' => '*', '%2C' => ',', '%3B' => ';',
        ]);
        // Valid triples pass through once; stray percent bytes retain native encoding.
        return preg_replace('/%25([0-9A-Fa-f]{2})/', '%$1', $encoded)
            ?? throw new \LogicException('Reserved query encoding failed.');
    }

    private static function assertFormat(mixed $format, string $name): void
    {
        if (!in_array($format, ['multi', 'csv', 'ssv', 'tsv', 'pipes', 'deepObject', 'form-object', 'form-object-explode'], true)) {
            throw new \InvalidArgumentException("Unsupported query array format for $name.");
        }
    }

}
