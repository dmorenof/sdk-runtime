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
            $httpClient ?? new \GuzzleHttp\Client(['http_errors' => false,
                'curl' => defined('CURLOPT_PATH_AS_IS') ? [CURLOPT_PATH_AS_IS => true] : []]),
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
        return $this->executeInput($operation, $identifier, $identifierName, $payload, $body, $body !== null);
    }

    /**
     * Native body entry point. Explicit presence distinguishes omission from JSON null.
     * Existing protected DTO executor signatures stay compatible with consumer overrides.
     * @param OperationSpec $operation
     */
    protected function executeBody(
        array $operation,
        mixed $body,
        bool $bodyPresent = true,
        string|int|float|bool|DTO|null $identifier = null,
        ?string $identifierName = null,
        ?DTO $payload = null,
    ): mixed {
        if (!isset($operation['requestBody'])) {
            throw new \InvalidArgumentException('Native body execution requires its declared contract.');
        }
        return $this->executeInput($operation, $identifier, $identifierName, $payload, $body, $bodyPresent);
    }

    /** @param OperationSpec $operation */
    private function executeInput(
        array $operation,
        string|int|float|bool|DTO|null $identifier,
        ?string $identifierName,
        ?DTO $payload,
        mixed $body,
        bool $bodyPresent,
    ): mixed {
        /** @var array<string,bool|float|int|string|list<bool|float|int|string>|DTO|Map|TypedArray|null> $path */
        $path = [];
        if ($identifier instanceof DTO) {
            $path = self::operationFields($identifier)['path'];
        } elseif ($identifierName !== null) {
            $path[$identifierName] = $identifier;
        }
        /** @var array<string,bool|float|int|string|list<bool|float|int|string>|DTO|Map|TypedArray|null> $query */
        $query = [];
        /** @var array<string,bool|float|int|string|list<bool|float|int|string>|DTO|Map|TypedArray|null> $headers */
        $headers = [];
        if ($payload !== null) {
            $fields = self::operationFields($payload);
            $query = $fields['query'];
            $headers = $fields['header'];
            if ($fields['cookie'] !== []) {
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
                $cookies = $fields['cookie'];
                $rawCookies = [];
                foreach ($operation['parameterContent']['cookie'] ?? [] as $name => $media) {
                    if ($media !== 'text/plain') { throw new \InvalidArgumentException('Cookie content supports only text/plain.'); }
                    if (array_key_exists($name, $cookies) && $cookies[$name] !== null) {
                        $rawCookies[] = CookieHeader::pair((string) $name, QuerySerializer::content($cookies[$name], $media));
                    }
                    unset($cookies[$name]);
                }
                $headers += CookieHeader::merge($cookieHeaders, ['Cookie' => implode('; ', $rawCookies)],
                    ['Cookie' => str_replace('&', '; ', QuerySerializer::encode($cookies))]);
            }
            $body = $fields['body'];
            $bodyPresent = $fields['bodyPresent'];
        }
        if (isset($operation['requestBody'])) {
            return $this->executeRequest($operation, $path, $query, $headers, $body, $bodyPresent);
        }
        if ($body !== null && !$body instanceof DTO) {
            throw new \UnexpectedValueException('Legacy operation body must be a DTO.');
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
     * @param array<string, scalar|list<scalar>|DTO|Map|TypedArray|\stdClass|null> $pathParameters
     * @param array<string, scalar|list<scalar>|DTO|Map|TypedArray|\stdClass|null> $queryParameters
     * @param array<string, scalar|list<scalar>|DTO|Map|TypedArray|\stdClass|null> $headerParameters
     */
    protected function executeOperation(
        array $operation,
        array $pathParameters = [],
        array $queryParameters = [],
        array $headerParameters = [],
        ?DTO $body = null,
    ): mixed {
        return $this->executeRequest($operation, $pathParameters, $queryParameters, $headerParameters, $body, $body !== null);
    }

    /**
     * @param OperationSpec $operation
     * @param array<string,scalar|list<scalar>|DTO|Map|TypedArray|\stdClass|null> $pathParameters
     * @param array<string,scalar|list<scalar>|DTO|Map|TypedArray|\stdClass|null> $queryParameters
     * @param array<string,scalar|list<scalar>|DTO|Map|TypedArray|\stdClass|null> $headerParameters
     */
    private function executeRequest(
        array $operation,
        array $pathParameters,
        array $queryParameters,
        array $headerParameters,
        mixed $body,
        bool $bodyPresent,
    ): mixed {
        $spec = Operation::fromArray($operation);
        $parameters = $pathParameters + $queryParameters + $headerParameters;
        foreach ($spec->validation as $name => $rules) {
            $value = $parameters[$name] ?? null;
            if ($value !== null) {
                Constraints::validate($value, $rules, $spec->label . '.' . $name);
            }
        }

        self::assertParameterContent($spec->parameterContent, $spec);
        $pathParameters = self::contentParameters($pathParameters, $spec->parameterContent['path'] ?? []);
        $queryParameters = self::contentParameters($queryParameters, $spec->parameterContent['query'] ?? []);
        $headerParameters = self::contentParameters($headerParameters, $spec->parameterContent['header'] ?? [], true);

        self::assertSimpleFormats($spec->pathSerialization, path: true);
        self::assertSimpleFormats($spec->headerSerialization);
        $path = $spec->path;
        foreach ($pathParameters as $name => $value) {
            if ($value === null) {
                throw new \UnexpectedValueException("Path parameter $name cannot be null.");
            }
            if (isset($spec->pathSerialization[$name])) {
                $encoded = QuerySerializer::path($value, (string) $name, $spec->pathSerialization[$name]);
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
        $query = array_filter($queryParameters, static fn ($value): bool => $value !== null);
        $accepted = [];
        if ($spec->responseType !== null) {
            $legacyBinary = ltrim($spec->responseType, '\\') === \Psr\Http\Message\ResponseInterface::class;
            $accepted[] = $legacyBinary && !array_key_exists('responseMediaType', $operation) ? 'application/octet-stream' : $spec->responseMediaType;
        }
        foreach (array_merge($spec->responseTypes, $spec->errorResponses) as $entry) {
            if ($entry['type'] === null) { continue; }
            $accepted[] = $entry['mediaType'] ?? (ltrim($entry['type'], '\\') === \Psr\Http\Message\ResponseInterface::class ? 'application/octet-stream' : 'application/json');
        }
        $accepted = array_values(array_unique($accepted));
        // Preserve the established order for previously emitted SDKs.
        $rank = static fn (string $media): int => preg_match('~^application/(?:[a-z0-9!#$&^_.+\-]+\+)?json(?:\s*;|$)~i', $media) === 1 ? 0 : match ($media) { 'text/plain' => 1, 'application/octet-stream' => 2, default => 3 };
        usort($accepted, static fn (string $left, string $right): int => $rank($left) <=> $rank($right));
        $accept = implode(', ', $accepted === [] ? ['application/json'] : $accepted);
        $headers = ['Accept' => $accept]
            + self::stringifyAll($headerParameters, $spec->headerSerialization);
        $jsonBody = preg_match('~^application/(?:[a-z0-9!#$&^_.+-]+\+)?json(?:\s*;.*)?$~iD', $spec->requestMediaType) === 1;
        $encoding = $spec->requestBody['encoding'] ?? 'json';
        $baseMedia = strtolower(trim(explode(';', $spec->requestMediaType, 2)[0]));
        $rawMedia = !$jsonBody && !str_contains($baseMedia, '*') && !in_array($baseMedia, ['application/x-www-form-urlencoded', 'multipart/form-data'], true)
            && preg_match("@^[!#$%&'*+.^_`|~a-z0-9-]+/[!#$%&'*+.^_`|~a-z0-9-]+(?:\\s*;[^\\r\\n]*)?$@iD", $spec->requestMediaType) === 1;
        $rawText = $encoding === 'text' && $rawMedia;
        $rawBinary = $encoding === 'binary' && $rawMedia;
        if ((!$jsonBody && !$rawText && !$rawBinary && !in_array($spec->requestMediaType, ['application/x-www-form-urlencoded', 'multipart/form-data'], true))
            || ($spec->requestMediaType !== 'application/x-www-form-urlencoded' && $spec->bodySerialization !== [])
            || ($spec->requestMediaType !== 'multipart/form-data' && $spec->bodyParts !== [])) {
            throw new \InvalidArgumentException('Unsupported request body media type or serialization metadata.');
        }
        $bodyBytes = null;
        if ($spec->requestBody !== null) {
            if ((!$jsonBody && !$rawText && !$rawBinary) || ($jsonBody && $encoding !== 'json')) { throw new \InvalidArgumentException('Request body encoding does not match its media type.'); }
            if ($rawBinary && ($spec->requestBody['rules'] ?? []) !== []) { throw new \InvalidArgumentException('Binary body constraints are unsupported.'); }
            if (!$bodyPresent && $spec->requestBody['required']) { throw new \UnexpectedValueException($spec->label . ' requires a request body.'); }
            if ($bodyPresent) {
                $bodyRules = $spec->requestBody['rules'] ?? [];
                if ($body !== null || array_key_exists('schema', $bodyRules) || array_key_exists('const', $bodyRules)) {
                    Constraints::validate($body, $bodyRules, $spec->label . ' body', 'request');
                }
                $body = Hydrator::hydrate($body, $spec->requestBody['type'], $spec->requestBody['nullable'], $spec->label . ' body');
            }
        } elseif ($bodyPresent && !$body instanceof DTO) {
            throw new \UnexpectedValueException('Legacy operation body must be a DTO.');
        }
        if ($bodyPresent) {
            $headers['Content-Type'] = $spec->requestMediaType;
            if ($rawText || $rawBinary) {
                if (!is_string($body) && !$body instanceof \Psr\Http\Message\StreamInterface) { throw new \UnexpectedValueException('Raw body must contain bytes.'); }
                $bodyBytes = $body;
            } elseif ($jsonBody) {
                DTO::validateNestedValue($body, 'request');
                $bodyBytes = json_encode(Serializer::value($body, 'request'), JSON_THROW_ON_ERROR);
            } else {
                if (!$body instanceof DTO) { throw new \UnexpectedValueException('Form body must be a DTO.'); }
                if ($spec->requestMediaType === 'multipart/form-data') {
                    $multipart = MultipartSerializer::encode($body, $spec->bodyParts);
                    $headers['Content-Type'] .= '; boundary=' . $multipart->getBoundary();
                    $bodyBytes = $multipart;
                } else {
                    $bodyBytes = FormSerializer::encode($body, $spec->bodySerialization);
                }
            }
        }

        $baseHeaders = $headers;
        $baseQuery = $query;
        // A token provider may hold a revoked token; renew it and replay once when the server answers 401.
        $replayable = !($bodyBytes instanceof \Psr\Http\Message\StreamInterface) || $bodyBytes->isSeekable();
        for ($attempt = 0;; $attempt++) {
            $headers = $baseHeaders;
            $query = $baseQuery;
            $securityQuery = [];
            $providers = [];
            SecurityApplier::apply($this->securityCredentials, $spec->security, static::SECURITY_SCHEMES, $headers, $securityQuery, $providers);
            $formats = $spec->querySerialization;
            foreach ($securityQuery as $name => $credential) {
                $query[$name] = $credential;
                unset($formats[$name]); // Security query keys keep their existing scalar precedence.
            }
            $allowReserved = array_values(array_diff($spec->queryAllowReserved, array_keys($securityQuery)));
            $encodedQuery = QuerySerializer::encode($query, $formats, allowReserved: $allowReserved);
            $baseUrl = $this->baseUrl;
            if ($spec->serverUrl !== null) {
                $declared = ServerUrl::normalize($spec->serverUrl);
                $baseUrl = $this->serverOverrides[$declared] ?? $declared;
            }
            $url = rtrim($baseUrl, '/') . $path;
            if ($encodedQuery !== '') {
                $url .= '?' . $encodedQuery;
            }
            $retry = $attempt === 0 && $providers !== [] && $replayable && !$spec->succeeded(401);
            if ($this->transport instanceof Psr18Transport) {
                $psrResponse = $this->transport->sendResponse($spec->method, $url, $headers, $bodyBytes);
                if ($retry && $psrResponse->getStatusCode() === 401) {
                    foreach ($providers as $provider) {
                        $provider->invalidate();
                    }
                    continue;
                }
                if ($spec->succeeded($psrResponse->getStatusCode()) && ltrim(($spec->responseTypeFor($psrResponse->getStatusCode()) ?? ['type' => $spec->responseType])['type'] ?? '', '\\') === \Psr\Http\Message\ResponseInterface::class) {
                    return $psrResponse;
                }
                $response = \Dmorenof\SdkRuntime\Transport\HttpResponse::fromPsr($psrResponse);
            } else {
                $response = $this->transport->send($spec->method, $url, $headers,
                    $bodyBytes instanceof \Psr\Http\Message\StreamInterface ? (string) $bodyBytes : $bodyBytes);
                if ($retry && $response->status === 401) {
                    foreach ($providers as $provider) {
                        $provider->invalidate();
                    }
                    continue;
                }
                if ($spec->succeeded($response->status) && ltrim(($spec->responseTypeFor($response->status) ?? ['type' => $spec->responseType])['type'] ?? '', '\\') === \Psr\Http\Message\ResponseInterface::class) {
                    return new \GuzzleHttp\Psr7\Response($response->status, $response->headers, $response->body);
                }
            }
            break;
        }
        if (!$spec->succeeded($response->status)) {
            throw ApiException::fromResponse($response, $spec->label, $spec->errorTypeFor($response->status), $spec->errorResponseFor($response->status));
        }
        $declared = $spec->responseTypeFor($response->status);
        $responseType = $declared ?? ['type' => $spec->responseType, 'schemaNullable' => $spec->schemaNullable, 'mediaType' => $spec->responseMediaType, 'encoding' => array_key_exists('responseEncoding', $operation) ? $spec->responseEncoding : ($spec->responseMediaType === 'text/plain' ? 'text' : 'json')];
        if ($responseType['type'] === null || ($declared === null && $spec->noContentAllowed && $response->body === '')) {
            return null;
        }
        $responseEncoding = $responseType['encoding'] ?? (($responseType['mediaType'] ?? '') === 'text/plain' ? 'text' : 'json');
        if (!in_array($responseEncoding, ['json', 'text', 'binary'], true)) { throw new \InvalidArgumentException('Unsupported response encoding.'); }
        $result = $responseEncoding !== 'json'
            ? Hydrator::hydrate($response->body, $responseType['type'], false, $spec->label . ' response')
            : JsonHydrator::decode($response->body, $responseType['type'], $responseType['schemaNullable']);
        Constraints::validate($result, $responseType['rules'] ?? $spec->responseValidation, $spec->label . ' response', 'response');
        return $result;
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
                $parameters[$name] = QuerySerializer::content($parameters[$name], $media, $header);
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
                    $result[$name] = QuerySerializer::simple($value, (string) $name, $formats[$name], header: true);
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

    private static function stringify(string|int|float|bool|null $value): string
    {
        return is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    }
}
