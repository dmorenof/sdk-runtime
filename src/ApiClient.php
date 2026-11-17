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
use Dmorenof\SdkRuntime\Execution\InputBuilder;
use Dmorenof\SdkRuntime\Execution\PreparedRequest;
use Dmorenof\SdkRuntime\Execution\RequestBuilder;
use Dmorenof\SdkRuntime\Execution\ResponseDecoder;
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
     * Run an operation from what a generated method receives: path values (or a parameter object that
     * declares them), an optional parameter object for query, headers and cookies, and an optional body.
     *
     * @param OperationSpec $operation
     * @param array<string, scalar|list<scalar>|DTO|Map|TypedArray|\stdClass|null>|DTO $path
     */
    protected function executeArguments(array $operation, array|DTO $path = [], ?DTO $parameters = null, ?DTO $body = null): mixed
    {
        $spec = Operation::fromArray($operation);
        return $this->run($spec, InputBuilder::build($spec, $path, $parameters, $body, $body !== null));
    }

    /**
     * Same, when the body is the main argument. A body is always present here, even when it is JSON null;
     * to leave an optional body out, call executeArguments() instead.
     *
     * @param OperationSpec $operation
     * @param array<string, scalar|list<scalar>|DTO|Map|TypedArray|\stdClass|null>|DTO $path
     */
    protected function executeBody(array $operation, mixed $body, array|DTO $path = [], ?DTO $parameters = null): mixed
    {
        $spec = Operation::fromArray($operation);
        if ($spec->requestBody === null) {
            throw new \InvalidArgumentException('Native body execution requires its declared contract.');
        }
        return $this->run($spec, InputBuilder::build($spec, $path, $parameters, $body, true));
    }

    /**
     * Run an operation from values already split by where they travel.
     *
     * @param OperationSpec $operation
     */
    protected function executeOperation(array $operation, RequestInput $input = new RequestInput()): mixed
    {
        return $this->run(Operation::fromArray($operation), $input);
    }

    private function run(Operation $spec, RequestInput $input): mixed
    {
        $outcome = $this->sendWithAuthRetry($spec, RequestBuilder::build($spec, $input));
        return $outcome instanceof HttpResponse ? ResponseDecoder::decode($spec, $outcome) : $outcome;
    }

    /**
     * Sends the request and classifies the answer. A token provider may hold a revoked token: after a 401
     * its token is renewed and the request replayed once, when the body can be sent again.
     *
     * @return \Generator<int, mixed>|ResponseInterface|HttpResponse A lazy stream of items, a raw PSR-7 response
     *         the operation hands over as it is, or the buffered response that ResponseDecoder turns into a value.
     */
    private function sendWithAuthRetry(Operation $spec, PreparedRequest $request): \Generator|ResponseInterface|HttpResponse
    {
        $bodyBytes = $request->body;
        $replayable = !($bodyBytes instanceof \Psr\Http\Message\StreamInterface) || $bodyBytes->isSeekable();
        $streaming = $spec->streamsResponse();
        $baseUrl = null;
        for ($attempt = 0;; $attempt++) {
            $attemptHeaders = $request->headers;
            $securityQuery = [];
            /** @var list<\Dmorenof\SdkRuntime\Security\TokenProviderInterface> $providers */
            $providers = [];
            SecurityApplier::apply($this->securityCredentials, $spec->security, static::SECURITY_SCHEMES, $attemptHeaders, $securityQuery, $providers);
            $url = $this->requestUrl($spec, $request, $securityQuery, $baseUrl);

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
                $entry = ResponseDecoder::entry($spec, $status, $contentType);
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
     * @param array<string,string> $securityQuery Credentials sent as query parameters.
     * @param string|null $baseUrl Resolved on first use and then kept for the replay.
     * @param-out string $baseUrl
     */
    private function requestUrl(Operation $spec, PreparedRequest $request, array $securityQuery, ?string &$baseUrl): string
    {
        $query = $request->query;
        $path = $request->path;
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

    /** @param array<array-key, array<string>|string> $headers */
    private function recordResponse(Operation $spec, int $status, array $headers): void
    {
        $this->lastResponse = new \Dmorenof\SdkRuntime\Transport\ResponseInfo(
            $status,
            new \Dmorenof\SdkRuntime\Transport\ResponseHeaders($headers, $spec->responseHeadersFor($status)),
            $spec->label,
        );
    }

}
