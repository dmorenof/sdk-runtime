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

    /** @param array<string, string> $headers */
    final public function __construct(
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
     *
     * @param OperationSpec $operation
     * @param array<string, scalar|null> $pathParameters
     * @param array<string, scalar|null> $queryParameters
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
        $query = self::stringifyAll($queryParameters);
        $headers = ['Accept' => 'application/json'] + self::stringifyAll($headerParameters);
        $bodyJson = null;
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
            $bodyJson = json_encode($body->jsonSerializeForRequest(), JSON_THROW_ON_ERROR);
        }

        SecurityApplier::apply($this->securityCredentials, $spec->security, static::SECURITY_SCHEMES, $headers, $query);
        $url = rtrim($this->baseUrl, '/') . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }
        $response = $this->transport->send($spec->method, $url, $headers, $bodyJson);
        if (!$spec->succeeded($response->status)) {
            throw ApiException::fromResponse($response, $spec->label, $spec->errorTypeFor($response->status));
        }
        if ($spec->responseType === null || ($spec->noContentAllowed && $response->body === '')) {
            return null;
        }
        return JsonHydrator::decode($response->body, $spec->responseType, $spec->schemaNullable);
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
