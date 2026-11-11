<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

use Dmorenof\SdkRuntime\Streaming\StreamDecoder;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use Dmorenof\SdkRuntime\Transport\TransportInterface;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\ResponseInterface;
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

    /**
     * Root servers of the API, as declared by the SDK: `url` (absolute, or a template with variables), optional
     * `name` and `variables`. Use serverUrl() to turn one into a base URL for connect().
     * @var list<array{url:string,name?:string,variables?:array<string,array{default:string,enum?:list<string>}>}>
     */
    protected const array SERVERS = [];

    protected SecurityCredentials $securityCredentials;

    protected TransportInterface $transport;

    private ?\Dmorenof\SdkRuntime\Transport\ResponseInfo $lastResponse = null;

    private int|string|null $serverSelection = null;

    /** @var array<string,string> */
    private array $serverVariables = [];

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
        $resource = new $type($this->baseUrl, securityCredentials: $this->securityCredentials,
            transport: $this->transport, serverOverrides: $this->serverOverrides);
        $resource->serverSelection = $this->serverSelection;
        $resource->serverVariables = $this->serverVariables;
        return $resource;
    }

    /**
     * Base URL for one of the root SERVERS declared by the SDK, with its variables filled in
     * (declared defaults, or the given values checked against each variable's enum).
     *
     * @param int|string $server Index, or the declared `name`.
     * @param array<string,string> $variables
     */
    public static function serverUrl(int|string $server = 0, array $variables = []): string
    {
        $declared = self::selectServer(static::SERVERS, $server, static::class);
        $unknown = array_diff(array_keys($variables), array_keys($declared['variables'] ?? []));
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown server variable(s): ' . implode(', ', $unknown) . '.');
        }
        $url = ServerUrl::expand($declared, $variables);
        if (!ServerUrl::isAbsolute($url)) {
            throw new \InvalidArgumentException('A root server must be an absolute URL; relative servers resolve against a base URL.');
        }
        return ServerUrl::normalize($url);
    }

    /**
     * Choose which declared server operations use (by index or `name`); null restores the first one.
     * Applies to operations that declare `servers`; operations without them keep using the base URL.
     * Resources created afterwards inherit the choice.
     */
    public function useServer(int|string|null $server): static
    {
        $this->serverSelection = $server;
        return $this;
    }

    /**
     * Values for server template variables, merged with earlier ones. A server ignores variables it does not
     * declare, and each value is checked against the declared enum when the request is built.
     *
     * @param array<mixed> $values Checked at runtime: names must be nonempty strings and values strings.
     */
    public function withServerVariables(array $values): static
    {
        foreach ($values as $name => $value) {
            if (!is_string($name) || $name === '' || !is_string($value)) {
                throw new \InvalidArgumentException('Server variables map nonempty names to string values.');
            }
        }
        /** @var array<string,string> $values */
        $this->serverVariables = $values + $this->serverVariables;
        return $this;
    }

    /**
     * @param list<array{url:string,name?:string,variables?:array<string,array{default:string,enum?:list<string>}>}> $servers
     * @return array{url:string,name?:string,variables?:array<string,array{default:string,enum?:list<string>}>}
     */
    private static function selectServer(array $servers, int|string|null $selection, string $where): array
    {
        $selection ??= 0;
        if (is_int($selection)) {
            return $servers[$selection] ?? throw new \InvalidArgumentException("$where declares no server #$selection.");
        }
        foreach ($servers as $server) {
            if (($server['name'] ?? null) === $selection) {
                return $server;
            }
        }
        throw new \InvalidArgumentException("$where declares no server named '$selection'.");
    }

    private function baseUrlFor(Operation $spec): string
    {
        if ($spec->servers !== []) {
            $declared = ServerUrl::resolve(
                ServerUrl::expand(self::selectServer($spec->servers, $this->serverSelection, $spec->label), $this->serverVariables),
                $this->baseUrl,
            );
        } elseif ($spec->serverUrl !== null) {
            $declared = ServerUrl::resolve($spec->serverUrl, $this->baseUrl);
        } else {
            return $this->baseUrl;
        }
        return $this->serverOverrides[$declared] ?? $declared;
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
     * Status and headers of the last response this client instance received, including error
     * statuses; null before the first request. Headers are typed when the operation declares them.
     */
    public function lastResponse(): ?\Dmorenof\SdkRuntime\Transport\ResponseInfo
    {
        return $this->lastResponse;
    }

    /** Opt in to following 3xx responses, with safe cross-origin rules; by default they are returned as they arrive. */
    public function withRedirectPolicy(?\Dmorenof\SdkRuntime\Transport\RedirectPolicy $policy): static
    {
        if (!$this->transport instanceof Psr18Transport) {
            throw new \LogicException('Redirect policy requires the PSR-18 adapter.');
        }
        $this->transport->setRedirectPolicy($policy);
        return $this;
    }

    /** Opt in to retrying transient failures when using the default PSR-18 adapter. */
    public function withRetryPolicy(?\Dmorenof\SdkRuntime\Transport\RetryPolicy $policy): static
    {
        if (!$this->transport instanceof Psr18Transport) {
            throw new \LogicException('Retry policy requires the PSR-18 adapter.');
        }
        $this->transport->setRetryPolicy($policy);
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
                $headers = self::withCookies($headers, $fields['cookie'], $operation['parameterContent']['cookie'] ?? []);
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
        if (strtoupper($spec->method) === 'TRACE' && $bodyPresent) { throw new \InvalidArgumentException('HTTP TRACE must not contain a request body.'); }
        self::validateParameters($spec, $pathParameters + $queryParameters + $headerParameters);

        self::assertParameterContent($spec->parameterContent, $spec);
        $pathParameters = self::contentParameters($pathParameters, $spec->parameterContent['path'] ?? []);
        $queryParameters = self::contentParameters($queryParameters, $spec->parameterContent['query'] ?? []);
        $headerParameters = self::contentParameters($headerParameters, $spec->parameterContent['header'] ?? [], true);
        self::assertSimpleFormats($spec->pathSerialization, path: true);
        self::assertSimpleFormats($spec->headerSerialization);

        $path = self::expandPath($spec, $pathParameters);
        $query = array_filter($queryParameters, static fn ($value): bool => $value !== null);
        $headers = ['Accept' => self::acceptHeader($spec, $operation)] + self::stringifyAll($headerParameters, $spec->headerSerialization);

        $kind = self::bodyKind($spec);
        $body = self::checkedBody($spec, $kind, $body, $bodyPresent);
        $bodyBytes = null;
        if ($bodyPresent) {
            [$bodyBytes, $headers['Content-Type']] = self::serializeBody($spec, $kind, $body);
        }

        $outcome = $this->sendWithAuthRetry($spec, $operation, $path, $query, $headers, $bodyBytes);
        return $outcome instanceof HttpResponse ? self::decodeResponse($spec, $operation, $outcome) : $outcome;
    }

    /** @param array<string,mixed> $parameters Path, query and header values by name. */
    private static function validateParameters(Operation $spec, array $parameters): void
    {
        foreach ($spec->validation as $name => $rules) {
            $value = $parameters[$name] ?? null;
            if ($value !== null) {
                Constraints::validate($value, $rules, $spec->label . '.' . $name);
            }
        }
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
        return $path;
    }

    /**
     * The Accept header: every declared success and error representation, most preferred first.
     *
     * @param OperationSpec $operation
     */
    private static function acceptHeader(Operation $spec, array $operation): string
    {
        $accepted = [];
        if ($spec->responseType !== null || Operation::isStreamingEncoding($spec->responseEncoding)) {
            $legacyBinary = $spec->responseType !== null && ltrim($spec->responseType, '\\') === \Psr\Http\Message\ResponseInterface::class;
            $accepted[] = $legacyBinary && !array_key_exists('responseMediaType', $operation) ? 'application/octet-stream' : $spec->responseMediaType;
        }
        foreach (array_merge($spec->responseTypes, $spec->errorResponses) as $entry) {
            foreach (Operation::representations($entry) as $representation) {
                if ($representation['type'] === null && !Operation::isStreamingEncoding($representation['encoding'] ?? null)) {
                    continue;
                }
                $accepted[] = $representation['mediaType'] ?? MediaType::defaultFor($representation['type'], $representation['encoding'] ?? null);
            }
        }
        $accepted = array_values(array_unique($accepted));
        // usort is stable, so equally preferred types keep the order of previously emitted SDKs.
        usort($accepted, static fn (string $left, string $right): int => MediaType::preference($left) <=> MediaType::preference($right));
        return implode(', ', $accepted === [] ? ['application/json'] : $accepted);
    }

    /**
     * How the request body is carried, decided from the declared media type and body encoding.
     *
     * @return 'json'|'text'|'binary'|'form'|'multipart'
     */
    private static function bodyKind(Operation $spec): string
    {
        $media = $spec->requestMediaType;
        $json = MediaType::isJson($media);
        $baseMedia = strtolower(trim(explode(';', $media, 2)[0]));
        $rawMedia = !$json && !str_contains($baseMedia, '*') && !in_array($baseMedia, ['application/x-www-form-urlencoded', 'multipart/form-data'], true)
            && preg_match("@^[!#$%&'*+.^_`|~a-z0-9-]+/[!#$%&'*+.^_`|~a-z0-9-]+(?:\\s*;[^\\r\\n]*)?$@iD", $media) === 1;
        $encoding = $spec->requestBody['encoding'] ?? 'json';
        $kind = match (true) {
            $json => 'json',
            $encoding === 'text' && $rawMedia => 'text',
            $encoding === 'binary' && $rawMedia => 'binary',
            $media === 'application/x-www-form-urlencoded' => 'form',
            $media === 'multipart/form-data' => 'multipart',
            default => null,
        };
        if ($kind === null
            || ($media !== 'application/x-www-form-urlencoded' && $spec->bodySerialization !== [])
            || ($media !== 'multipart/form-data' && $spec->bodyParts !== [])) {
            throw new \InvalidArgumentException('Unsupported request body media type or serialization metadata.');
        }
        return $kind;
    }

    /** Validates the body against its declared contract and hydrates it into its declared type. */
    private static function checkedBody(Operation $spec, string $kind, mixed $body, bool $bodyPresent): mixed
    {
        if ($spec->requestBody === null) {
            if ($bodyPresent && !$body instanceof DTO) {
                throw new \UnexpectedValueException('Legacy operation body must be a DTO.');
            }
            return $body;
        }
        $encoding = $spec->requestBody['encoding'] ?? 'json';
        if (!in_array($kind, ['json', 'text', 'binary'], true) || ($kind === 'json' && $encoding !== 'json')) {
            throw new \InvalidArgumentException('Request body encoding does not match its media type.');
        }
        if ($kind === 'binary' && ($spec->requestBody['rules'] ?? []) !== []) {
            throw new \InvalidArgumentException('Binary body constraints are unsupported.');
        }
        if (!$bodyPresent) {
            if ($spec->requestBody['required']) {
                throw new \UnexpectedValueException($spec->label . ' requires a request body.');
            }
            return $body;
        }
        $bodyRules = $spec->requestBody['rules'] ?? [];
        if ($body !== null || array_key_exists('schema', $bodyRules) || array_key_exists('const', $bodyRules) || array_key_exists('enum', $bodyRules) || array_key_exists('jsonSchema', $bodyRules)) {
            Constraints::validate($body, $bodyRules, $spec->label . ' body', 'request');
        }
        return Hydrator::hydrate($body, $spec->requestBody['type'], $spec->requestBody['nullable'], $spec->label . ' body');
    }

    /**
     * Wire bytes of a present body and the Content-Type header that goes with them.
     *
     * @return array{string|\Psr\Http\Message\StreamInterface, string}
     */
    private static function serializeBody(Operation $spec, string $kind, mixed $body): array
    {
        $contentType = $spec->requestMediaType;
        if ($kind === 'text' || $kind === 'binary') {
            if (!is_string($body) && !$body instanceof \Psr\Http\Message\StreamInterface) { throw new \UnexpectedValueException('Raw body must contain bytes.'); }
            return [$body, $contentType];
        }
        if ($kind === 'json') {
            DTO::validateNestedValue($body, 'request');
            return [json_encode(Serializer::value($body, 'request'), JSON_THROW_ON_ERROR), $contentType];
        }
        if (!$body instanceof DTO) { throw new \UnexpectedValueException('Form body must be a DTO.'); }
        if ($kind === 'multipart') {
            $multipart = MultipartSerializer::encode($body, $spec->bodyParts);
            return [$multipart, $contentType . '; boundary=' . $multipart->getBoundary()];
        }
        return [FormSerializer::encode($body, $spec->bodySerialization), $contentType];
    }

    /**
     * Sends the request and classifies the answer. A token provider may hold a revoked token: after a 401
     * its token is renewed and the request replayed once, when the body can be sent again.
     *
     * @param OperationSpec $operation
     * @param array<string,scalar|list<scalar>|DTO|Map|TypedArray|\stdClass|null> $query
     * @param array<string,string> $headers
     * @return \Generator<int, mixed>|ResponseInterface|HttpResponse A lazy stream of items, a raw PSR-7 response
     *         the operation hands over as it is, or the buffered response that decodeResponse() turns into a value.
     */
    private function sendWithAuthRetry(Operation $spec, array $operation, string $path, array $query, array $headers, string|\Psr\Http\Message\StreamInterface|null $bodyBytes): \Generator|ResponseInterface|HttpResponse
    {
        $replayable = !($bodyBytes instanceof \Psr\Http\Message\StreamInterface) || $bodyBytes->isSeekable();
        $streaming = $spec->streamsResponse();
        $baseUrl = null;
        for ($attempt = 0;; $attempt++) {
            $attemptHeaders = $headers;
            $securityQuery = [];
            /** @var list<\Dmorenof\SdkRuntime\Security\TokenProviderInterface> $providers */
            $providers = [];
            SecurityApplier::apply($this->securityCredentials, $spec->security, static::SECURITY_SCHEMES, $attemptHeaders, $securityQuery, $providers);
            $url = $this->requestUrl($spec, $path, $query, $securityQuery, $baseUrl);

            // The PSR-18 adapter answers with PSR-7; legacy transports answer with a buffered HttpResponse.
            if ($this->transport instanceof Psr18Transport) {
                $raw = $this->transport->sendResponse($spec->method, $url, $attemptHeaders, $bodyBytes, $streaming);
                $status = $raw->getStatusCode();
                $wireHeaders = $raw->getHeaders();
                $contentType = $raw->getHeaderLine('Content-Type');
            } else {
                $raw = $this->transport->send($spec->method, $url, $attemptHeaders,
                    $bodyBytes instanceof \Psr\Http\Message\StreamInterface ? (string) $bodyBytes : $bodyBytes);
                $status = $raw->status;
                $wireHeaders = $raw->headers;
                $contentType = $raw->header('Content-Type');
            }
            if ($attempt === 0 && $providers !== [] && $replayable && $status === 401 && !$spec->succeeded(401)) {
                foreach ($providers ?? [] as $provider) {
                    $provider->invalidate();
                }
                continue;
            }
            $this->recordResponse($spec, $status, $wireHeaders);

            if (Operation::allowsResponseBody($spec->method, $status) && $spec->succeeded($status)) {
                $entry = $spec->responseTypeFor($status, $contentType) ?? self::legacyResponse($spec, $operation);
                if ($streaming && Operation::isStreamingEncoding($entry['encoding'] ?? 'json')) {
                    return StreamDecoder::iterate($raw instanceof ResponseInterface ? $raw->getBody() : Utils::streamFor($raw->body), $entry, $spec->label . ' response');
                }
                if (ltrim((string) $entry['type'], '\\') === ResponseInterface::class) {
                    return $raw instanceof ResponseInterface ? $raw : new PsrResponse($status, $raw->headers, $raw->body);
                }
            }
            return $raw instanceof ResponseInterface ? HttpResponse::fromPsr($raw) : $raw;
        }
    }

    /**
     * @param array<string,scalar|list<scalar>|DTO|Map|TypedArray|\stdClass|null> $query
     * @param array<string,string> $securityQuery Credentials sent as query parameters.
     * @param string|null $baseUrl Resolved on first use and then kept for the replay.
     * @param-out string $baseUrl
     */
    private function requestUrl(Operation $spec, string $path, array $query, array $securityQuery, ?string &$baseUrl): string
    {
        $formats = $spec->querySerialization;
        foreach ($securityQuery as $name => $credential) {
            $query[$name] = $credential;
            unset($formats[$name]); // Security query keys keep their existing scalar precedence.
        }
        $allowReserved = array_values(array_diff($spec->queryAllowReserved, array_keys($securityQuery)));
        $encodedQuery = QuerySerializer::encode($query, $formats, allowReserved: $allowReserved);
        $baseUrl ??= $this->baseUrlFor($spec);
        $url = rtrim($baseUrl, '/') . $path;
        return $encodedQuery === '' ? $url : $url . '?' . $encodedQuery;
    }

    /**
     * The response description of an operation declared with the single-response keys instead of per-status entries.
     *
     * @param OperationSpec $operation
     * @return array{type:?string,schemaNullable:bool,mediaType:string,encoding:string}
     */
    private static function legacyResponse(Operation $spec, array $operation): array
    {
        return [
            'type' => $spec->responseType,
            'schemaNullable' => $spec->schemaNullable,
            'mediaType' => $spec->responseMediaType,
            'encoding' => array_key_exists('responseEncoding', $operation) ? $spec->responseEncoding : ($spec->responseMediaType === 'text/plain' ? 'text' : 'json'),
        ];
    }

    /**
     * Turns a buffered response into the operation's value: null for bodyless answers, the decoded and
     * validated payload for a success, or an ApiException for any other status.
     *
     * @param OperationSpec $operation
     */
    private static function decodeResponse(Operation $spec, array $operation, HttpResponse $response): mixed
    {
        if (!Operation::allowsResponseBody($spec->method, $response->status)) {
            if (!$spec->succeeded($response->status)) { throw new ApiException($response, null, $spec->label); }
            return null;
        }
        $contentType = $response->header('Content-Type');
        if (!$spec->succeeded($response->status)) {
            throw ApiException::fromResponse($response, $spec->label, $spec->errorTypeFor($response->status), $spec->errorResponseFor($response->status, $contentType));
        }
        $declared = $spec->responseTypeFor($response->status, $contentType);
        $responseType = $declared ?? self::legacyResponse($spec, $operation);
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

    /** @param array<array-key, array<string>|string> $headers */
    private function recordResponse(Operation $spec, int $status, array $headers): void
    {
        $this->lastResponse = new \Dmorenof\SdkRuntime\Transport\ResponseInfo(
            $status,
            new \Dmorenof\SdkRuntime\Transport\ResponseHeaders($headers, $spec->responseHeadersFor($status)),
            $spec->label,
        );
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

    /**
     * Folds cookie parameters into the Cookie header, next to any Cookie header the caller already set.
     *
     * @param array<string,bool|float|int|string|list<bool|float|int|string>|DTO|Map|TypedArray|null> $headers
     * @param array<string,bool|float|int|string|list<bool|float|int|string>|DTO|Map|TypedArray|null> $cookies
     * @param array<array-key,mixed> $content Cookie names declared as raw text content.
     * @return array<string,bool|float|int|string|list<bool|float|int|string>|DTO|Map|TypedArray|null>
     */
    private static function withCookies(array $headers, array $cookies, array $content): array
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
                $rawCookies[] = CookieHeader::pair((string) $name, QuerySerializer::content($cookies[$name], $media));
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
