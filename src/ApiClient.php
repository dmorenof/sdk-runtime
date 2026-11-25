<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

use Dmorenof\SdkRuntime\Execution\InputBuilder;
use Dmorenof\SdkRuntime\Execution\OperationPlan;
use Dmorenof\SdkRuntime\Execution\PreparedRequest;
use Dmorenof\SdkRuntime\Execution\RequestBuilder;
use Dmorenof\SdkRuntime\Execution\ResponseDecoder;
use Dmorenof\SdkRuntime\Execution\ServerSelection;
use Dmorenof\SdkRuntime\Security\AppliedSecurity;
use Dmorenof\SdkRuntime\Security\DiscoveryOptions;
use Dmorenof\SdkRuntime\Security\OpenIdDiscovery;
use Dmorenof\SdkRuntime\Security\SecurityApplier;
use Dmorenof\SdkRuntime\Security\SecurityCredentials;
use Dmorenof\SdkRuntime\Streaming\StreamDecoder;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use Dmorenof\SdkRuntime\Transport\Psr18Options;
use Dmorenof\SdkRuntime\Transport\Psr18Transport;
use Dmorenof\SdkRuntime\Transport\ResponseHeaders;
use Dmorenof\SdkRuntime\Transport\ResponseInfo;
use Dmorenof\SdkRuntime\Transport\TransportInterface;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Shared endpoint-client infrastructure; derived API classes only emit typed operations.
 *
 * @phpstan-import-type OperationSpec from Operation
 */
abstract class ApiClient
{
    /**
     * Security scheme definitions, keyed by name; each SDK overrides this.
     * @var array<string, array{type: string, scheme: string|null, location: string|null, parameterName: string|null, openIdConnectUrl?: string|null}>
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

    /** @var array<class-string, array<string, Operation>> Operations built by operation(), per resource class and route. */
    private static array $operations = [];

    private Configuration $configuration;

    private ServerSelection $servers;

    private ?ResponseInfo $lastResponse = null;

    /** @var array{int, array<array-key, array<string>|string>, Operation}|null The last answer, before lastResponse() builds its view. */
    private ?array $received = null;

    /** Build a client from its configuration. Prefer connect(). */
    final public function __construct(Configuration $configuration)
    {
        $this->servers = new ServerSelection($configuration);
        $this->configuration = clone $configuration; // a snapshot: later changes to the caller's object change nothing
        $this->securityCredentials = $configuration->securityCredentials ?? new SecurityCredentials();
        $this->transport = $configuration->transport ?? self::defaultTransport($configuration);
        if ($configuration->transport !== null && ($configuration->middleware !== [] || $configuration->retryPolicy !== null || $configuration->redirectPolicy !== null)) {
            throw new \LogicException('Middleware, retry and redirect policies need the default PSR-18 transport.');
        }
    }

    /**
     * Connect a client. A string is a base URL with every other option at its default.
     *
     * @return static
     */
    public static function connect(Configuration|string $configuration): static
    {
        if (is_string($configuration)) {
            $configuration = self::configurationFor($configuration);
        }
        return new static($configuration);
    }

    /**
     * A client for one resource class (a group of routes), sharing this client's transport, credentials and server choice.
     *
     * @template T of ApiClient
     * @param class-string<T> $type
     * @return T
     */
    public function resource(string $type): ApiClient
    {
        if (!is_a($type, static::class, true)) {
            throw new \InvalidArgumentException('Resource must belong to this SDK: ' . $type);
        }
        $shared = clone $this->configuration;
        $shared->transport = $this->transport;
        $shared->middleware = [];
        $shared->retryPolicy = null;
        $shared->redirectPolicy = null;
        return new $type($shared);
    }

    /**
     * OpenID Connect discovery for a security scheme the API declares with an `openIdConnectUrl`. It needs no connected
     * client, since the endpoints it finds are what you build the credentials of the connection from:
     *
     *     $endpoints = Client::openIdDiscovery('OAuth')->configuration()->endpoints();
     */
    public static function openIdDiscovery(string $scheme, DiscoveryOptions $options = new DiscoveryOptions()): OpenIdDiscovery
    {
        $definition = static::SECURITY_SCHEMES[$scheme] ?? null;
        $url = $definition['openIdConnectUrl'] ?? null;
        if ($definition === null || $definition['type'] !== 'openIdConnect' || !is_string($url)) {
            throw new \InvalidArgumentException("The API declares no OpenID Connect discovery URL for the security scheme $scheme.");
        }
        return OpenIdDiscovery::forUrl($url, $options);
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
        return ServerSelection::rootUrl(static::SERVERS, $server, $variables, static::class);
    }

    /**
     * Status and headers of the last response this client instance received, including error
     * statuses; null before the first request. Headers are typed when the operation declares them.
     */
    public function lastResponse(): ?ResponseInfo
    {
        if ($this->lastResponse === null && $this->received !== null) {
            [$status, $headers, $spec] = $this->received;
            $this->lastResponse = new ResponseInfo($status, new ResponseHeaders($headers, $spec->responseHeadersFor($status)), $spec->label);
        }
        return $this->lastResponse;
    }

    private static function configurationFor(string $baseUrl): Configuration
    {
        $configuration = new Configuration();
        $configuration->baseUrl = $baseUrl;
        return $configuration;
    }

    private static function defaultTransport(Configuration $configuration): Psr18Transport
    {
        return new Psr18Transport(
            $configuration->httpClient ?? new \GuzzleHttp\Client(['http_errors' => false,
                'timeout' => $configuration->timeout, 'connect_timeout' => $configuration->connectTimeout,
                // `true` would decode but never send the header; the list sends it (and decodes what comes back).
                'decode_content' => $configuration->compression ? 'gzip, deflate' : true,
                'curl' => defined('CURLOPT_PATH_AS_IS') ? [CURLOPT_PATH_AS_IS => true] : []]),
            new Psr18Options($configuration->headers, $configuration->middleware, $configuration->retryPolicy, $configuration->redirectPolicy, compression: $configuration->compression),
        );
    }

    /**
     * Run an operation from what a generated method receives: path values (or a parameter object that
     * declares them), an optional parameter object for query, headers and cookies, and an optional body.
     *
     * @param OperationSpec|Operation $operation
     * @param array<string, scalar|\BackedEnum|list<scalar>|DTO|Map|TypedArray|\stdClass|null>|DTO $path
     */
    protected function executeArguments(array|Operation $operation, array|DTO $path = [], ?DTO $parameters = null, ?DTO $body = null): mixed
    {
        $spec = $operation instanceof Operation ? $operation : Operation::fromArray($operation);
        return $this->run($spec, InputBuilder::build($spec, $path, $parameters, $body, $body !== null));
    }

    /**
     * Same, when the body is the main argument. A body is always present here, even when it is JSON null;
     * to leave an optional body out, call executeArguments() instead.
     *
     * @param OperationSpec|Operation $operation
     * @param array<string, scalar|\BackedEnum|list<scalar>|DTO|Map|TypedArray|\stdClass|null>|DTO $path
     */
    protected function executeBody(array|Operation $operation, mixed $body, array|DTO $path = [], ?DTO $parameters = null): mixed
    {
        $spec = $operation instanceof Operation ? $operation : Operation::fromArray($operation);
        if ($spec->requestBody === null) {
            throw new \InvalidArgumentException('Native body execution requires its declared contract.');
        }
        return $this->run($spec, InputBuilder::build($spec, $path, $parameters, $body, true));
    }

    /**
     * The operation a generated method describes, built once per resource class and route and reused by every call.
     * Generated code passes its metadata constant through here: `$this->executeArguments(self::operation(self::GET_BY_ID))`.
     *
     * @param OperationSpec $spec
     */
    protected static function operation(array $spec): Operation
    {
        return self::$operations[static::class][$spec['method'] . ' ' . $spec['path']] ??= Operation::fromArray($spec);
    }

    /**
     * Run an operation from values already split by where they travel.
     *
     * @param OperationSpec|Operation $operation
     */
    protected function executeOperation(array|Operation $operation, RequestInput $input = new RequestInput()): mixed
    {
        return $this->run($operation instanceof Operation ? $operation : Operation::fromArray($operation), $input);
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
        $replayable = !($request->body instanceof StreamInterface) || $request->body->isSeekable();
        $baseUrl = null;
        for ($attempt = 0;; $attempt++) {
            $security = SecurityApplier::apply($this->securityCredentials, $spec->security, static::SECURITY_SCHEMES);
            $url = $this->requestUrl($spec, $request, $security->query, $baseUrl);
            $response = $this->exchange($spec, $url, $request, $security);
            if ($attempt === 0 && $security->providers !== [] && $replayable && $response->getStatusCode() === 401 && !$spec->succeeded(401)) {
                foreach ($security->providers as $provider) {
                    $provider->invalidate();
                }
                continue;
            }
            $this->recordResponse($spec, $response);
            return self::outcome($spec, $response);
        }
    }

    /**
     * One round trip, answered as PSR-7. A custom transport answers with a buffered HttpResponse, which is wrapped.
     */
    private function exchange(Operation $spec, string $url, PreparedRequest $request, AppliedSecurity $security): ResponseInterface
    {
        $headers = CookieHeader::merge($request->headers, $security->headers);
        $body = $request->body;
        if ($this->transport instanceof Psr18Transport) {
            return $this->transport->sendResponse(new PsrRequest($spec->method, $url, $headers, $body ?? ''), $spec->streamsResponse(), $security->certificate);
        }
        if ($security->certificate !== null && !$security->certificate->isConfiguredOnTheClient()) {
            throw new \LogicException('A client certificate is sent by the default transport. A custom transport must present it itself: use ClientCertificate::configuredOnTheClient().');
        }
        $buffered = $this->transport->send($spec->method, $url, $headers, $body instanceof StreamInterface ? (string) $body : $body);
        return new PsrResponse($buffered->status, $buffered->headers, $buffered->body);
    }

    /**
     * What the caller receives: a lazy stream of items, the raw PSR-7 response the operation hands over as it
     * is, or the buffered response that ResponseDecoder turns into a value.
     *
     * @return \Generator<int, mixed>|ResponseInterface|HttpResponse
     */
    private static function outcome(Operation $spec, ResponseInterface $response): \Generator|ResponseInterface|HttpResponse
    {
        $status = $response->getStatusCode();
        $plan = OperationPlan::of($spec);
        [$allowsBody, $succeeded] = $plan->statuses[$status] ??= [Operation::allowsResponseBody($spec->method, $status), $spec->succeeded($status)];
        if ($allowsBody && $succeeded) {
            $contentType = $response->getHeaderLine('Content-Type');
            $entry = $plan->entries[$status . '|' . $contentType][1] ?? ResponseDecoder::entry($spec, $status, $contentType);
            if ($spec->streamsResponse() && Operation::isStreamingEncoding($entry['encoding'] ?? 'json')) {
                return StreamDecoder::iterate($response->getBody(), $entry, $spec->label . ' response');
            }
            if (ltrim((string) $entry['type'], '\\') === ResponseInterface::class) {
                return $response;
            }
        }
        return HttpResponse::fromPsr($response);
    }

    /**
     * @param array<string,string> $securityQuery Credentials sent as query parameters.
     * @param string|null $baseUrl Resolved on first use and then kept for the replay.
     * @param-out string $baseUrl
     */
    private function requestUrl(Operation $spec, PreparedRequest $request, array $securityQuery, ?string &$baseUrl): string
    {
        if ($securityQuery === [] && $request->query === [] && $spec->queryAllowReserved === []) {
            $baseUrl ??= $this->servers->urlFor($spec); // No query string to build: the common case of a call with only path parameters.
            return rtrim($baseUrl, '/') . $request->path;
        }
        $query = $request->query;
        $path = $request->path;
        $formats = $spec->querySerialization;
        foreach ($securityQuery as $name => $credential) {
            $query[$name] = $credential;
            unset($formats[$name]); // Security query keys keep their existing scalar precedence.
        }
        $allowReserved = array_values(array_diff($spec->queryAllowReserved, array_keys($securityQuery)));
        $encodedQuery = QuerySerializer::encode($query, $formats, allowReserved: $allowReserved);
        $baseUrl ??= $this->servers->urlFor($spec);
        $url = rtrim($baseUrl, '/') . $path;
        return $encodedQuery === '' ? $url : $url . '?' . $encodedQuery;
    }

    /** Keeps what lastResponse() needs; the headers are only turned into a ResponseHeaders view if it is asked for. */
    private function recordResponse(Operation $spec, ResponseInterface $response): void
    {
        $this->lastResponse = null;
        $this->received = [$response->getStatusCode(), $response->getHeaders(), $spec];
    }
}
