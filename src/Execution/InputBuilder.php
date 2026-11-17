<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Execution;

use Dmorenof\SdkRuntime\DTO;
use Dmorenof\SdkRuntime\Map;
use Dmorenof\SdkRuntime\Operation;
use Dmorenof\SdkRuntime\RequestInput;
use Dmorenof\SdkRuntime\TypedArray;

/**
 * Turns what a generated method receives (path values, a parameter object, a body) into a RequestInput.
 *
 * @internal Implementation detail: not covered by the backward-compatibility promise (see docs/PUBLIC_API.md).
 */
final class InputBuilder
{
    /**
     * @param array<string, scalar|list<scalar>|DTO|Map|TypedArray|\stdClass|null>|DTO $path Path values by name, or a parameter object that declares them.
     * @param ?DTO $parameters Object holding the query, header, cookie and (optionally) body values.
     */
    public static function build(Operation $spec, array|DTO $path, ?DTO $parameters, mixed $body, bool $bodyPresent): RequestInput
    {
        $pathValues = $path instanceof DTO ? self::operationFields($path)['path'] : $path;
        $query = [];
        $headers = [];
        if ($parameters !== null) {
            $fields = self::operationFields($parameters);
            $query = $fields['query'];
            $headers = $fields['header'];
            if ($fields['cookie'] !== []) {
                $headers = RequestParameters::withCookies($headers, $fields['cookie'], $spec->parameterContent['cookie'] ?? []);
            }
            $body = $fields['body'];
            $bodyPresent = $fields['bodyPresent'];
        }
        return new RequestInput($pathValues, $query, $headers, $body, $bodyPresent);
    }

    /**
     * Transfer only initialized properties, preserving OpenAPI wire names.
     * Shared runtime DTO validation checks required and nested fields.
     *
     * @return array{
     *   path:array<string,bool|float|int|string|list<bool|float|int|string>|DTO|Map|TypedArray|null>,
     *   query:array<string,bool|float|int|string|list<bool|float|int|string>|DTO|Map|TypedArray|null>,
     *   header:array<string,bool|float|int|string|list<bool|float|int|string>|DTO|Map|TypedArray|null>,
     *   cookie:array<string,bool|float|int|string|list<bool|float|int|string>|DTO|Map|TypedArray|null>,
     *   body:mixed, bodyPresent:bool
     * }
     */
    private static function operationFields(DTO $dto): array
    {
        $dto->validateForRequest();
        $values = get_object_vars($dto);
        $bodyValue = $values['body'] ?? null;
        // A query or path parameter may also be named "body"; only an explicit operation body is a payload.
        $hasBody = defined($dto::class . '::OPERATION_BODY') && constant($dto::class . '::OPERATION_BODY') === true;
        $body = $hasBody ? $bodyValue : null;
        $bodyPresent = $hasBody && array_key_exists('body', $values);
        $fields = ['path' => [], 'query' => [], 'header' => [], 'cookie' => []];
        /** @var array<string,array<string,string>> $locations */
        $locations = constant($dto::class . '::OPERATION_FIELDS');
        foreach ($locations as $location => $properties) {
            if (!array_key_exists($location, $fields)) {
                throw new \UnexpectedValueException('Unsupported operation parameter location.');
            }
            foreach ($properties as $wireName => $property) {
                if (!array_key_exists($property, $values)) { continue; }
                $value = $values[$property];
                if ($value instanceof DTO || $value instanceof Map || $value instanceof TypedArray) {
                    if ($location === 'cookie') { throw new \UnexpectedValueException('Structured cookie parameters are unsupported.'); }
                } elseif (is_array($value)) {
                    if ($location === 'cookie' || !array_is_list($value)) {
                        throw new \UnexpectedValueException("Operation parameter must be a supported scalar list: $wireName");
                    }
                    $items = [];
                    foreach ($value as $item) {
                        if (!is_scalar($item)) { throw new \UnexpectedValueException("Parameter list members must be scalars: $wireName"); }
                        $items[] = $item;
                    }
                    $value = $items;
                } elseif ($value !== null && !is_scalar($value)) {
                    throw new \UnexpectedValueException("Non-scalar operation parameter: $wireName");
                }
                $fields[$location][$wireName] = $value;
            }
        }
        return $fields + ['body' => $body, 'bodyPresent' => $bodyPresent];
    }
}
