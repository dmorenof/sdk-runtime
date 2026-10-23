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
 */
abstract class ApiClient
{
    /** Security scheme definitions, keyed by name; each SDK overrides this. */
    protected const array SECURITY_SCHEMES = [];

    /**
     * Values an operation inherits when it does not declare them. Only `method`
     * and `path` are mandatory; `label` defaults to "METHOD path".
     */
    public const array OPERATION_DEFAULTS = [
        'security' => [],
        'success' => [200],
        'errors' => [],
        'responseType' => null,
        'schemaNullable' => false,
        'noContentAllowed' => false,
        'validation' => [],
    ];

    protected SecurityCredentials $securityCredentials;

    protected TransportInterface $transport;

    /** @param array<string, string> $headers */
    public function __construct(
        protected string $baseUrl,
        ?\Psr\Http\Client\ClientInterface $httpClient = null,
        array $headers = [],
        ?SecurityCredentials $securityCredentials = null,
        ?TransportInterface $transport = null,
    ) {
        $this->securityCredentials = $securityCredentials ?? new SecurityCredentials();
        $this->transport = $transport ?? new Psr18Transport(
            $httpClient ?? new \GuzzleHttp\Client(['http_errors' => false]),
            $headers,
        );
    }

    /** @param array<string, string> $headers */
    public static function connect(
        string $baseUrl,
        ?\Psr\Http\Client\ClientInterface $httpClient = null,
        array $headers = [],
        ?SecurityCredentials $securityCredentials = null,
        ?TransportInterface $transport = null,
    ): static {
        return new static($baseUrl, $httpClient, $headers, $securityCredentials, $transport);
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
        return new $type($this->baseUrl, securityCredentials: $this->securityCredentials, transport: $this->transport);
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
     * Execute one generated operation: validate parameters, serialize the request,
     * send it and decode the typed response.
     * @param array<string, mixed> $operation
     * @param array<string, mixed> $pathParameters
     * @param array<string, mixed> $queryParameters
     * @param array<string, mixed> $headerParameters
     */
    protected function executeOperation(
        array $operation,
        array $pathParameters = [],
        array $queryParameters = [],
        array $headerParameters = [],
        ?DTO $body = null,
    ): mixed {
        $operation += self::OPERATION_DEFAULTS;
        $operation['label'] ??= $operation['method'] . ' ' . $operation['path'];
        $path = $operation['path'];
        $parameters = $pathParameters + $queryParameters + $headerParameters;
        foreach ($operation['validation'] as $name => $validation) {
            $value = $parameters[$name] ?? null;
            if ($value !== null) {
                Constraints::validate($value, $validation, $operation['label'] . '.' . $name);
            }
        }

        foreach ($pathParameters as $name => $value) {
            $path = str_replace('{' . $name . '}', rawurlencode((string) $value), $path);
        }
        $query = self::stringify($queryParameters);
        $headers = ['Accept' => 'application/json'] + self::stringify($headerParameters);
        $bodyJson = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $bodyJson = json_encode($body->jsonSerializeForRequest(), JSON_THROW_ON_ERROR);
        }

        SecurityApplier::apply(
            $this->securityCredentials,
            $operation['security'],
            static::SECURITY_SCHEMES,
            $headers,
            $query,
        );
        $url = rtrim($this->baseUrl, '/') . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $response = $this->transport->send($operation['method'], $url, $headers, $bodyJson);
        if (!in_array($response->status, $operation['success'], true)) {
            throw ApiException::fromResponse(
                $response,
                $operation['label'],
                $operation['errors'][$response->status] ?? $operation['errors']['default'] ?? null,
            );
        }
        if ($operation['responseType'] === null
            || ($operation['noContentAllowed'] && $response->body === '')) {
            return null;
        }
        return JsonHydrator::decode($response->body, $operation['responseType'], $operation['schemaNullable']);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    private static function stringify(array $values): array
    {
        $result = [];
        foreach ($values as $name => $value) {
            if ($value !== null) {
                $result[$name] = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
            }
        }
        return $result;
    }
}
