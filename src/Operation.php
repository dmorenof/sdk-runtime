<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/**
 * One API operation as declared by a generated SDK: where it goes, how it is secured,
 * which statuses succeed and which types describe its response and errors.
 *
 * Generated code declares operations as arrays holding only what differs from DEFAULTS;
 * fromArray() turns that into this typed object and rejects keys it does not know.
 *
 * @phpstan-type OperationSpec array{
 *     method: string,
 *     path: string,
 *     label?: string|null,
 *     security?: list<array<string, list<string>>>,
 *     success?: list<int|string>,
 *     errors?: array<int|string, ?string>,
 *     responseType?: string|null,
 *     responseTypes?: array<int|string,array{type:?string,schemaNullable:bool,rules?:array<string,mixed>,mediaType?:string}>,
 *     pathSerialization?: array<array-key,'csv'|'ssv'|'tsv'|'pipes'|'object'|'object-explode'|'label'|'label-list'|'label-list-explode'|'label-object'|'label-object-explode'|'matrix'|'matrix-list'|'matrix-list-explode'|'matrix-object'|'matrix-object-explode'>,
 *     headerSerialization?: array<array-key,'csv'|'ssv'|'tsv'|'pipes'|'object'|'object-explode'>,
 *     responseValidation?: array<string,mixed>,
 *     responseMediaType?: string,
 *     errorResponses?: array<int|string,array{type:?string,schemaNullable:bool,rules?:array<string,mixed>,mediaType?:string}>,
 *     requestBody?: array{type:string,nullable:bool,required:bool,rules?:array<string,mixed>,encoding?:'json'|'text'|'binary'}|null,
 *     schemaNullable?: bool,
 *     noContentAllowed?: bool,
 *     validation?: array<array-key, array<string, mixed>>,
 *     serverUrl?: string|null,
 *     requestMediaType?: string,
 *     bodySerialization?: array<string,'multi'|'csv'|'ssv'|'tsv'|'pipes'|'form-object'|'form-object-explode'|'deepObject'>,
 *     bodyParts?: array<string,array{contentType:?string,filename?:string,json?:bool}>,
 *     parameterContent?: array<string,array<array-key,'application/json'|'text/plain'>>,
 *     queryAllowReserved?: list<string>,
 *     querySerialization?: array<array-key, 'multi'|'csv'|'ssv'|'tsv'|'pipes'|'deepObject'|'form-object'|'form-object-explode'>,
 * }
 */
final readonly class Operation
{
    /** Values an operation inherits when it does not declare them. */
    public const array DEFAULTS = [
        'security' => [],
        'success' => [200],
        'errors' => [],
        'responseType' => null,
        'schemaNullable' => false,
        'noContentAllowed' => false,
        'validation' => [],
        'serverUrl' => null,
        'querySerialization' => [],
        'requestMediaType' => 'application/json',
        'bodySerialization' => [],
        'bodyParts' => [],
        'responseTypes' => [],
        'pathSerialization' => [],
        'headerSerialization' => [],
        'queryAllowReserved' => [],
        'parameterContent' => [],
        'responseValidation' => [],
        'requestBody' => null,
        'responseMediaType' => 'application/json',
        'errorResponses' => [],
    ];

    /** "METHOD path", unless the operation names itself. */
    public string $label;

    /**
     * @param list<array<string, list<string>>> $security Alternatives are OR; schemes within one entry are AND.
     * @param array<string,array<array-key,'application/json'|'text/plain'>> $parameterContent Parameter media types grouped by location.
     * @param array<string,mixed> $responseValidation Rules for a legacy/single JSON response.
     * @param array{type:string,nullable:bool,required:bool,rules?:array<string,mixed>,encoding?:'json'|'text'|'binary'}|null $requestBody Native JSON body contract.
     * @param list<string> $queryAllowReserved Query names whose data uses reserved expansion.
     * @param array<int|string,array{type:?string,schemaNullable:bool,rules?:array<string,mixed>,mediaType?:string}> $errorResponses Declared general error payloads.
     * @param list<int|string> $success Statuses that are not errors.
     * @param array<int|string, ?string> $errors Status (or 'default') => error model class.
     * @param array<array-key, array<string, mixed>> $validation Parameter name => rules.
     * @param array<string,'multi'|'csv'|'ssv'|'tsv'|'pipes'|'form-object'|'form-object-explode'|'deepObject'> $bodySerialization Form array field name => wire format.
     * @param array<string,array{contentType:?string,filename?:string,json?:bool}> $bodyParts Multipart metadata; null MIME accepts the uploaded file's type or octet-stream.
     * @param array<array-key,'csv'|'ssv'|'tsv'|'pipes'|'object'|'object-explode'|'label'|'label-list'|'label-list-explode'|'label-object'|'label-object-explode'|'matrix'|'matrix-list'|'matrix-list-explode'|'matrix-object'|'matrix-object-explode'> $pathSerialization Path list name => simple CSV expansion.
     * @param array<array-key,'csv'|'ssv'|'tsv'|'pipes'|'object'|'object-explode'> $headerSerialization Header list name => raw simple CSV expansion.
     * @param array<int|string,array{type:?string,schemaNullable:bool,rules?:array<string,mixed>,mediaType?:string}> $responseTypes Explicit status-specific JSON types; null type means no body.
     * @param array<array-key, 'multi'|'csv'|'ssv'|'tsv'|'pipes'|'deepObject'|'form-object'|'form-object-explode'> $querySerialization Query list name => normalized wire format.
     */
    public function __construct(
        public string $method,
        public string $path,
        ?string $label = null,
        public array $security = self::DEFAULTS['security'],
        public array $success = self::DEFAULTS['success'],
        public array $errors = self::DEFAULTS['errors'],
        public ?string $responseType = self::DEFAULTS['responseType'],
        public bool $schemaNullable = self::DEFAULTS['schemaNullable'],
        public bool $noContentAllowed = self::DEFAULTS['noContentAllowed'],
        public array $validation = self::DEFAULTS['validation'],
        public ?string $serverUrl = self::DEFAULTS['serverUrl'],
        public array $querySerialization = self::DEFAULTS['querySerialization'],
        public string $requestMediaType = self::DEFAULTS['requestMediaType'],
        public array $bodySerialization = self::DEFAULTS['bodySerialization'],
        public array $bodyParts = self::DEFAULTS['bodyParts'],
        public array $responseTypes = self::DEFAULTS['responseTypes'],
        public array $pathSerialization = self::DEFAULTS['pathSerialization'],
        public array $headerSerialization = self::DEFAULTS['headerSerialization'],
        public array $queryAllowReserved = self::DEFAULTS['queryAllowReserved'],
        public array $parameterContent = self::DEFAULTS['parameterContent'],
        public array $responseValidation = self::DEFAULTS['responseValidation'],
        public ?array $requestBody = self::DEFAULTS['requestBody'],
        public string $responseMediaType = self::DEFAULTS['responseMediaType'],
        public array $errorResponses = self::DEFAULTS['errorResponses'],
    ) {
        $this->label = $label ?? $method . ' ' . $path;
    }

    /** @param OperationSpec $spec */
    public static function fromArray(array $spec): self
    {
        return new self(...$spec);
    }

    /** The error model for a failed status: its own entry, then the 'default' one. */
    public function errorTypeFor(int $status): ?string
    {
        return self::select($this->errors, $status);
    }

    /** @return array{type:?string,schemaNullable:bool,rules?:array<string,mixed>,mediaType?:string}|null */
    public function errorResponseFor(int $status): ?array
    {
        return self::select($this->errorResponses, $status);
    }

    /** @return array{type:?string,schemaNullable:bool,rules?:array<string,mixed>,mediaType?:string}|null */
    public function responseTypeFor(int $status): ?array
    {
        return self::select($this->responseTypes, $status);
    }

    /**
     * @template T
     * @param array<int|string,T> $entries
     * @return T|null
     */
    private static function select(array $entries, int $status): mixed
    {
        foreach ([$status, intdiv($status, 100) . 'XX', 'default'] as $key) {
            if (array_key_exists($key, $entries)) { return $entries[$key]; }
        }
        return null;
    }

    public function succeeded(int $status): bool
    {
        return in_array($status, $this->success, true)
            || in_array(intdiv($status, 100) . 'XX', $this->success, true)
            || ($status >= 200 && $status < 300 && in_array('default', $this->success, true));
    }
}
