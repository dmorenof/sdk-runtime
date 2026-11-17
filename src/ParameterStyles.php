<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/**
 * How path and header parameters, and parameters carried as media (`content`), are written: simple lists and
 * objects, label and matrix path styles, JSON and plain text. Query strings are QuerySerializer's job.
 *
 * @internal Implementation detail: not covered by the backward-compatibility promise (see docs/PUBLIC_API.md).
 */
final class ParameterStyles
{
    /** Serialize a parameter representation once, before its location's normal encoding. */
    public static function content(mixed $value, string $media, bool $header = false): string
    {
        if ($media === 'text/plain') {
            if (!is_string($value)) { throw new \UnexpectedValueException('text/plain parameters require strings.'); }
            return $header ? self::headerText($value) : $value;
        }
        if ($media !== 'application/json') { throw new \InvalidArgumentException('Unsupported parameter media type.'); }
        if ($value === null || (!is_scalar($value) && !is_array($value)
            && !$value instanceof DTO && !$value instanceof Map && !$value instanceof TypedArray)) {
            throw new \UnexpectedValueException('JSON parameters require typed models, collections or non-null scalar values.');
        }
        DTO::validateNestedValue($value, 'request');
        $json = json_encode(Serializer::value($value, 'request'), JSON_THROW_ON_ERROR);
        return $header ? self::headerText($json) : $json;
    }

    /** Primitive simple lists reuse the query list validation and native item encoding. */
    public static function simpleList(mixed $value, string $name, bool $header = false): string
    {
        return self::simple($value, $name, 'csv', $header);
    }

    /** Existing metadata keeps csv; object forms use the same flat DTO/Map extraction. */
    public static function simple(mixed $value, string $name, string $format, bool $header = false): string
    {
        if (in_array($format, ['csv', 'ssv', 'tsv', 'pipes'], true)) {
            $delimiter = match ($format) { 'ssv' => ' ', 'tsv' => "\t", 'pipes' => '|', default => ',' };
            $encode = $header ? self::headerText(...) : rawurlencode(...);
            $items = ParameterValues::encodedList($value, $name, $header || $format !== 'csv' ? $delimiter : null, $encode);
            $text = implode($header || $format === 'csv' ? $delimiter : rawurlencode($delimiter), $items);
            return $header ? self::headerText($text) : $text;
        }
        if (!in_array($format, ['object', 'object-explode'], true)) {
            throw new \InvalidArgumentException('Unsupported simple parameter format.');
        }
        $explode = $format === 'object-explode';
        $encode = $header ? self::headerText(...) : rawurlencode(...);
        $items = [];
        foreach (ParameterValues::objectFields($value, $name, deep: false) as $key => $item) {
            $key = (string) $key;
            $text = ParameterValues::scalar($item);
            if ($header && (str_contains($key, ',') || str_contains($text, ',')
                || ($explode && str_contains($key, '=')))) {
                throw new \UnexpectedValueException('Simple header object contains its structural delimiter.');
            }
            if ($explode) {
                $items[] = $encode($key) . '=' . $encode($text);
            } else {
                $items[] = $encode($key);
                $items[] = $encode($text);
            }
        }
        return implode(',', $items);
    }

    /** Expand one declared path parameter, not an arbitrary URI-template language. */
    public static function path(mixed $value, string $name, string $format): string
    {
        if (in_array($format, ['csv', 'ssv', 'tsv', 'pipes', 'object', 'object-explode'], true)) {
            return self::simple($value, $name, $format);
        }
        if (preg_match('/^(label|matrix)(?:-(list|object)(-explode)?)?$/D', $format, $matches) !== 1) {
            throw new \InvalidArgumentException('Unsupported path parameter format.');
        }
        $label = $matches[1] === 'label';
        $kind = $matches[2] ?? 'scalar';
        $explode = isset($matches[3]);
        $named = static fn (string $key, string $text): string => ';' . $key . ($text === '' ? '' : '=' . $text);
        if ($kind === 'scalar') {
            $text = rawurlencode(ParameterValues::scalar($value));
            return $label ? '.' . $text : $named(rawurlencode($name), $text);
        }
        if ($kind === 'list') {
            $items = ParameterValues::encodedList($value, $name, null, rawurlencode(...));
            if ($items === []) { return ''; }
            if ($label) { return '.' . implode($explode ? '.' : ',', $items); }
            if (!$explode) { return $named(rawurlencode($name), implode(',', $items)); }
            return implode('', array_map(static fn (string $item): string => $named(rawurlencode($name), $item), $items));
        }
        $items = [];
        foreach (ParameterValues::objectFields($value, $name, deep: false) as $key => $item) {
            $key = rawurlencode((string) $key); $text = rawurlencode(ParameterValues::scalar($item));
            if (!$explode) { $items[] = $key; $items[] = $text; }
            else { $items[] = $label ? $key . '=' . $text : $named($key, $text); }
        }
        if ($items === []) { return ''; }
        if ($label) { return '.' . implode($explode ? '.' : ',', $items); }
        return $explode ? implode('', $items) : $named(rawurlencode($name), implode(',', $items));
    }

    private static function headerText(string $text): string
    {
        if ($text !== trim($text, " \t") || preg_match('/^[\x09\x20-\x7E\x80-\xFF]*$/D', $text) !== 1) {
            throw new \UnexpectedValueException('Simple header items must preserve HTTP field whitespace and contain valid header bytes.');
        }
        return $text;
    }
}
