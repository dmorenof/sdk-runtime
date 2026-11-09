<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

use Dmorenof\SdkRuntime\Transport\TransportInterface;
use Dmorenof\SdkRuntime\Transport\Psr18Transport;
use Dmorenof\SdkRuntime\Transport\HttpMiddlewareInterface;
use Dmorenof\SdkRuntime\Security\SecurityApplier;
use Dmorenof\SdkRuntime\Security\SecurityCredentials;

/**
 * Shared endpoint-client infrastructure; derived API classes only emit typed operations.
 *
 * @phpstan-import-type OperationSpec from Operation
 */
abstract class ApiClient
{
    /**
     * Security scheme definitions, keyed by name; each SDK overrides this.
     * @var array<string, array{type: string, scheme: string|null, location: string|null, parameterName: string|null}>
     */
    protected const array SECURITY_SCHEMES = [];

    protected SecurityCredentials $securityCredentials;

    protected TransportInterface $transport;

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $serverOverrides Declared server URL => environment URL.
     */
    final public function __construct(
        protected string $baseUrl,
        ?\Psr\Http\Client\ClientInterface $httpClient = null,
        array $headers = [],
        ?SecurityCredentials $securityCredentials = null,
        ?TransportInterface $transport = null,
        protected array $serverOverrides = [],
    ) {
        $normalized = [];
        foreach ($serverOverrides as $declared => $replacement) {
            $normalized[ServerUrl::normalize($declared)] = ServerUrl::normalize($replacement);
        }
        $this->serverOverrides = $normalized;
        $this->securityCredentials = $securityCredentials ?? new SecurityCredentials();
        $this->transport = $transport ?? new Psr18Transport(
            $httpClient ?? new \GuzzleHttp\Client(['http_errors' => false]),
            $headers,
        );
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, string> $serverOverrides Declared server URL => environment URL.
     */
    public static function connect(
        string $baseUrl,
        ?\Psr\Http\Client\ClientInterface $httpClient = null,
        array $headers = [],
        ?SecurityCredentials $securityCredentials = null,
        ?TransportInterface $transport = null,
        array $serverOverrides = [],
    ): static {
        return new static($baseUrl, $httpClient, $headers, $securityCredentials, $transport, $serverOverrides);
    }

    /**
     * Share this client's transport, credentials and middleware with a typed resource.
     * @template T of ApiClient
     * @param class-string<T> $type
     * @return T
     */
    public function resource(string $type): ApiClient
    {
        if (!is_a($type, static::class, true)) {
            throw new \InvalidArgumentException('Resource must belong to this SDK: ' . $type);
        }
        return new $type($this->baseUrl, securityCredentials: $this->securityCredentials,
            transport: $this->transport, serverOverrides: $this->serverOverrides);
    }

    /** Attach an SDK-level interceptor when using the default PSR-18 adapter. */
    public function withMiddleware(HttpMiddlewareInterface $middleware): static
    {
        if (!$this->transport instanceof Psr18Transport) {
            throw new \LogicException('SDK middleware requires the PSR-18 adapter.');
        }
        $this->transport->addMiddleware($middleware);
        return $this;
    }

    /**
     * Transfer only initialized properties, preserving OpenAPI wire names.
     * Shared runtime DTO validation checks required and nested fields.
     *
     * @return array{
     *   path:array<string,bool|float|int|string|null>,
     *   query:array<string,bool|float|int|string|list<bool|float|int|string>|DTO|Map|null>,
     *   header:array<string,bool|float|int|string|null>,
     *   body:?DTO
     * }
     */
    private static function operationFields(DTO $dto): array
    {
        $dto->validateForRequest();
        $values = get_object_vars($dto);
        $bodyValue = $values['body'] ?? null;
        // A query or path parameter may also be named "body"; only an explicit operation body is a payload.
        $hasBody = defined($dto::class . '::OPERATION_BODY') && constant($dto::class . '::OPERATION_BODY') === true;
        $body = $hasBody && $bodyValue instanceof DTO ? $bodyValue : null;
        $path = [];
        $query = [];
        $headers = [];
        /** @var array{path:array<string,string>,query:array<string,string>,header:array<string,string>} $locations */
        $locations = constant($dto::class . '::OPERATION_FIELDS');
        foreach ($locations as $location => $fields) {
            foreach ($fields as $wireName => $property) {
                if (!array_key_exists($property, $values)) {
                    continue;
                }
                $value = $values[$property];
                if ($location === 'query' && ($value instanceof DTO || $value instanceof Map)) {
                    $query[$wireName] = $value;
                    continue;
                }
                if (is_array($value)) {
                    if ($location !== 'query' || !array_is_list($value)) {
                        throw new \UnexpectedValueException("Only query parameters can carry scalar lists: $wireName");
                    }
                    $items = [];
                    foreach ($value as $item) {
                        if (!is_scalar($item)) {
                            throw new \UnexpectedValueException("Query array members must be scalars: $wireName");
                        }
                        $items[] = $item;
                    }
                    $query[$wireName] = $items;
                    continue;
                }
                if ($value !== null && !is_scalar($value)) {
                    throw new \UnexpectedValueException("Non-scalar operation parameter: $wireName");
                }
                if ($location === 'path') {
                    $path[$wireName] = $value;
                } elseif ($location === 'header') {
                    $headers[$wireName] = $value;
                } else {
                    $query[$wireName] = $value;
                }
            }
        }
        return ['path' => $path, 'query' => $query, 'header' => $headers, 'body' => $body];
    }

    /**
     * Map identifier + payload to the shared sdk-runtime executor.
     * @param OperationSpec $operation
     */
    protected function executeArguments(
        array $operation,
        string|int|float|bool|DTO|null $identifier = null,
        ?string $identifierName = null,
        ?DTO $payload = null,
        ?DTO $body = null,
    ): mixed {
        /** @var array<string,bool|float|int|string|null> $path */
        $path = [];
        if ($identifier instanceof DTO) {
            $path = self::operationFields($identifier)['path'];
        } elseif ($identifierName !== null) {
            $path[$identifierName] = $identifier;
        }
        /** @var array<string,bool|float|int|string|list<bool|float|int|string>|DTO|Map|null> $query */
        $query = [];
        /** @var array<string,bool|float|int|string|null> $headers */
        $headers = [];
        if ($payload !== null) {
            $fields = self::operationFields($payload);
            $query = $fields['query'];
            $headers = $fields['header'];
            $body = $fields['body'];
        }
        return $this->executeOperation(
            $operation,
            pathParameters: $path,
            queryParameters: $query,
            headerParameters: $headers,
            body: $body,
        );
    }

    /**
     * Execute one generated operation: validate parameters, serialize the request,
     * send it and decode the typed response.
     *
     * @param OperationSpec $operation
     * @param array<string, scalar|null> $pathParameters
     * @param array<string, scalar|list<scalar>|DTO|Map|\stdClass|null> $queryParameters
     * @param array<string, scalar|null> $headerParameters
     */
    protected function executeOperation(
        array $operation,
        array $pathParameters = [],
        array $queryParameters = [],
        array $headerParameters = [],
        ?DTO $body = null,
    ): mixed {
        $spec = Operation::fromArray($operation);
        $parameters = $pathParameters + $queryParameters + $headerParameters;
        foreach ($spec->validation as $name => $rules) {
            $value = $parameters[$name] ?? null;
            if ($value !== null) {
                Constraints::validate($value, $rules, $spec->label . '.' . $name);
            }
        }

        $path = $spec->path;
        foreach ($pathParameters as $name => $value) {
            $path = str_replace('{' . $name . '}', rawurlencode(self::stringify($value)), $path);
        }
        $query = array_filter($queryParameters, static fn ($value): bool => $value !== null);
        $binary = ltrim($spec->responseType ?? '', '\\') === \Psr\Http\Message\ResponseInterface::class;
        $headers = ['Accept' => $binary ? 'application/octet-stream' : 'application/json'] + self::stringifyAll($headerParameters);
        if (!in_array($spec->requestMediaType, ['application/json', 'application/x-www-form-urlencoded', 'multipart/form-data'], true)
            || ($spec->requestMediaType !== 'application/x-www-form-urlencoded' && $spec->bodySerialization !== [])
            || ($spec->requestMediaType !== 'multipart/form-data' && $spec->bodyParts !== [])) {
            throw new \InvalidArgumentException('Unsupported request body media type or serialization metadata.');
        }
        $bodyBytes = null;
        if ($body !== null) {
            $headers['Content-Type'] = $spec->requestMediaType;
            if ($spec->requestMediaType === 'multipart/form-data') {
                $multipart = MultipartSerializer::encode($body, $spec->bodyParts);
                $headers['Content-Type'] .= '; boundary=' . $multipart->getBoundary();
                $bodyBytes = $multipart;
            } else {
                $bodyBytes = $spec->requestMediaType === 'application/x-www-form-urlencoded'
                    ? FormSerializer::encode($body, $spec->bodySerialization)
                    : json_encode($body->jsonSerializeForRequest(), JSON_THROW_ON_ERROR);
            }
        }

        $securityQuery = [];
        SecurityApplier::apply($this->securityCredentials, $spec->security, static::SECURITY_SCHEMES, $headers, $securityQuery);
        $formats = $spec->querySerialization;
        foreach ($securityQuery as $name => $credential) {
            $query[$name] = $credential;
            unset($formats[$name]); // Security query keys keep their existing scalar precedence.
        }
        $encodedQuery = QuerySerializer::encode($query, $formats);
        $baseUrl = $this->baseUrl;
        if ($spec->serverUrl !== null) {
            $declared = ServerUrl::normalize($spec->serverUrl);
            $baseUrl = $this->serverOverrides[$declared] ?? $declared;
        }
        $url = rtrim($baseUrl, '/') . $path;
        if ($encodedQuery !== '') {
            $url .= '?' . $encodedQuery;
        }
        if ($this->transport instanceof Psr18Transport) {
            $psrResponse = $this->transport->sendResponse($spec->method, $url, $headers, $bodyBytes);
            if ($binary && $spec->succeeded($psrResponse->getStatusCode())) {
                return $psrResponse;
            }
            $response = \Dmorenof\SdkRuntime\Transport\HttpResponse::fromPsr($psrResponse);
        } else {
            $response = $this->transport->send($spec->method, $url, $headers,
                $bodyBytes instanceof \Psr\Http\Message\StreamInterface ? (string) $bodyBytes : $bodyBytes);
            if ($binary && $spec->succeeded($response->status)) {
                return new \GuzzleHttp\Psr7\Response($response->status, $response->headers, $response->body);
            }
        }
        if (!$spec->succeeded($response->status)) {
            throw ApiException::fromResponse($response, $spec->label, $spec->errorTypeFor($response->status));
        }
        $declared = $spec->responseTypes[$response->status] ?? null;
        $responseType = $declared ?? ['type' => $spec->responseType, 'schemaNullable' => $spec->schemaNullable];
        if ($responseType['type'] === null || ($declared === null && $spec->noContentAllowed && $response->body === '')) {
            return null;
        }
        return JsonHydrator::decode($response->body, $responseType['type'], $responseType['schemaNullable']);
    }

    /**
     * @param array<string, scalar|null> $values
     * @return array<string, string> Parameters that carry a value, as they appear on the wire.
     */
    private static function stringifyAll(array $values): array
    {
        $result = [];
        foreach ($values as $name => $value) {
            if ($value !== null) {
                $result[$name] = self::stringify($value);
            }
        }
        return $result;
    }

    private static function stringify(string|int|float|bool|null $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }
}
