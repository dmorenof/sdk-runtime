<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Execution;

use Dmorenof\SdkRuntime\CookieHeader;
use Dmorenof\SdkRuntime\DTO;
use Dmorenof\SdkRuntime\Map;
use Dmorenof\SdkRuntime\Operation;
use Dmorenof\SdkRuntime\QuerySerializer;
use Dmorenof\SdkRuntime\ParameterStyles;
use Dmorenof\SdkRuntime\RequestInput;
use Dmorenof\SdkRuntime\TypedArray;

/**
 * Path, query and header values of a call: validated, serialized as the operation declares, and ready for the wire.
 *
 * @internal Implementation detail: not covered by the backward-compatibility promise (see docs/PUBLIC_API.md).
 */
final class RequestParameters
{
    /**
     * @return array{path: string, query: array<string, scalar|list<scalar>|DTO|Map|TypedArray|\stdClass>, headers: array<string, string>}
     */
    public static function prepare(Operation $spec, RequestInput $input): array
    {
        $plan = OperationPlan::of($spec);
        if (!$plan->parametersChecked) {
            self::assertParameterContent($spec->parameterContent, $spec);
            self::assertSimpleFormats($spec->pathSerialization, path: true);
            self::assertSimpleFormats($spec->headerSerialization);
            $plan->parametersChecked = true;
        }
        $path = self::contentParameters($input->path, $spec->parameterContent['path'] ?? []);
        $query = self::contentParameters($input->query, $spec->parameterContent['query'] ?? []);
        $headers = self::contentParameters($input->headers, $spec->parameterContent['header'] ?? [], true);

        return [
            'path' => self::expandPath($spec, $path),
            'query' => array_filter($query, static fn ($value): bool => $value !== null),
            'headers' => self::stringifyAll($headers, $spec->headerSerialization),
        ];
    }

    /** @param array<string,scalar|list<scalar>|DTO|Map|TypedArray|\stdClass|null> $pathParameters */
    private static function expandPath(Operation $spec, array $pathParameters): string
    {
        $path = $spec->path;
        foreach ($pathParameters as $name => $value) {
            if ($value === null) {
                throw new \UnexpectedValueException("Path parameter $name cannot be null.");
            }
            if (isset($spec->pathSerialization[$name])) {
                $encoded = ParameterStyles::path($value, (string) $name, $spec->pathSerialization[$name]);
            } elseif (is_array($value) || is_object($value)) {
                throw new \UnexpectedValueException("Structured path parameter $name requires serialization metadata.");
            } else {
                $encoded = rawurlencode(self::stringify($value));
            }
            $path = str_replace('{' . $name . '}', $encoded, $path);
        }
        if (preg_match('/\{[^{}]+\}/', $path) === 1) {
            throw new \UnexpectedValueException('Missing path parameter for ' . $spec->label);
        }
        return $path;
    }

    /** @param array<array-key,mixed> $content Unvalidated nested metadata at the execution boundary. */
    private static function assertParameterContent(array $content, Operation $spec): void
    {
        foreach ($content as $location => $formats) {
            if (!in_array($location, ['path', 'query', 'header', 'cookie'], true) || !is_array($formats)) {
                throw new \InvalidArgumentException('Unsupported parameter content location.');
            }
            foreach ($formats as $name => $media) {
                $styles = match ($location) {
                    'path' => $spec->pathSerialization, 'query' => $spec->querySerialization,
                    'header' => $spec->headerSerialization, default => [],
                };
                if (!in_array($media, ['application/json', 'text/plain'], true)
                    || ($location === 'cookie' && $media !== 'text/plain') || isset($styles[$name])
                    || ($location === 'query' && in_array((string) $name, $spec->queryAllowReserved, true))) {
                    throw new \InvalidArgumentException('Parameter content cannot combine incompatible media or style metadata.');
                }
            }
        }
    }

    /**
     * @param array<string,scalar|list<scalar>|DTO|Map|TypedArray|\stdClass|null> $parameters
     * @param array<array-key,'application/json'|'text/plain'> $formats
     * @return array<string,scalar|list<scalar>|DTO|Map|TypedArray|\stdClass|null>
     */
    private static function contentParameters(array $parameters, array $formats, bool $header = false): array
    {
        foreach ($formats as $name => $media) {
            if (array_key_exists($name, $parameters) && $parameters[$name] !== null) {
                $parameters[$name] = ParameterStyles::content($parameters[$name], $media, $header);
            }
        }
        return $parameters;
    }

    /**
     * @param array<string, scalar|list<scalar>|DTO|Map|TypedArray|\stdClass|null> $values
     * @param array<array-key,'csv'|'ssv'|'tsv'|'pipes'|'object'|'object-explode'> $formats
     * @return array<string, string> Parameters that carry a value, as they appear on the wire.
     */
    private static function stringifyAll(array $values, array $formats = []): array
    {
        $result = [];
        foreach ($values as $name => $value) {
            if ($value !== null) {
                if (isset($formats[$name])) {
                    $result[$name] = ParameterStyles::simple($value, (string) $name, $formats[$name], header: true);
                } elseif (is_array($value) || is_object($value)) {
                    throw new \UnexpectedValueException("Structured header parameter $name requires serialization metadata.");
                } else {
                    $result[$name] = self::stringify($value);
                }
            }
        }
        return $result;
    }

    /** @param array<array-key,mixed> $formats */
    private static function assertSimpleFormats(array $formats, bool $path = false): void
    {
        foreach ($formats as $format) {
            if (!in_array($format, ['csv', 'ssv', 'tsv', 'pipes', 'object', 'object-explode'], true)
                && !($path && is_string($format) && preg_match('/^(label|matrix)(?:-(list|object)(-explode)?)?$/D', $format) === 1)) {
                throw new \InvalidArgumentException('Unsupported simple path/header parameter metadata.');
            }
        }
    }

    /**
     * Folds cookie parameters into the Cookie header, next to any Cookie header the caller already set.
     *
     * @param array<string,bool|float|int|string|list<bool|float|int|string>|DTO|Map|TypedArray|null> $headers
     * @param array<string,bool|float|int|string|list<bool|float|int|string>|DTO|Map|TypedArray|null> $cookies
     * @param array<array-key,mixed> $content Cookie names declared as raw text content.
     * @return array<string,bool|float|int|string|list<bool|float|int|string>|DTO|Map|TypedArray|null>
     */
    public static function withCookies(array $headers, array $cookies, array $content): array
    {
        $cookieHeaders = [];
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'Cookie') === 0) {
                if ($value !== null) {
                    if (!is_scalar($value)) { throw new \UnexpectedValueException('Cookie header must be scalar.'); }
                    $cookieHeaders[$name] = self::stringify($value);
                }
                unset($headers[$name]);
            }
        }
        $rawCookies = [];
        foreach ($content as $name => $media) {
            if ($media !== 'text/plain') { throw new \InvalidArgumentException('Cookie content supports only text/plain.'); }
            if (array_key_exists($name, $cookies) && $cookies[$name] !== null) {
                $rawCookies[] = CookieHeader::pair((string) $name, ParameterStyles::content($cookies[$name], $media));
            }
            unset($cookies[$name]);
        }
        return $headers + CookieHeader::merge($cookieHeaders, ['Cookie' => implode('; ', $rawCookies)],
            ['Cookie' => str_replace('&', '; ', QuerySerializer::encode($cookies))]);
    }

    private static function stringify(string|int|float|bool|null $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }
}
