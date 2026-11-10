<?php
declare(strict_types=1);
require $argv[1] ?? throw new RuntimeException('Missing autoload.');
require __DIR__ . '/Fixtures/ErrorPayloadHttpCode.php';
require __DIR__ . '/Fixtures/ErrorPayloadHttpChoice.php';
use Dmorenof\SdkRuntime\Tests\Fixtures\ErrorPayloadHttpCode;
use Dmorenof\SdkRuntime\Tests\Fixtures\ErrorPayloadHttpChoice;
$process = proc_open([PHP_BINARY, __DIR__ . '/error-payloads-http-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start server.'); }
try {
    stream_set_timeout($pipes[1], 15); $port = trim((string) fgets($pipes[1])); if (!ctype_digit($port)) { throw new RuntimeException('Missing port.'); }
    $detail = new class((object) ['code' => 0, 'message' => 'initial']) extends \Dmorenof\SdkRuntime\DTO {
        public const array REQUIRED_FIELDS = ['code', 'message', 'requestToken']; protected const array WRITE_ONLY_FIELDS = ['requestToken'];
        protected const array VALIDATION = ['message' => ['minLength' => 2]];
        public int $code; public string $message; public string $requestToken;
    };
    $map = new class((object) ['one' => 1]) extends \Dmorenof\SdkRuntime\Map {
        protected const string VALUE_TYPE = 'int'; protected const array VALUE_VALIDATION = ['minimum' => 0];
    };
    $list = new class([1]) extends \Dmorenof\SdkRuntime\TypedArray {
        protected string $expected_type = 'integer'; protected const array ITEM_VALIDATION = ['minimum' => 0];
    };
    $resource = new class('http://127.0.0.1:' . $port) extends \Dmorenof\SdkRuntime\ApiClient {
        public array $descriptors;
        public function getById(int $id, \Dmorenof\SdkRuntime\DTO $params): mixed {
            return $this->executeArguments(['method' => 'GET', 'path' => '/errors/{id}', 'responseType' => 'mixed',
                'errors' => ['default' => $this->descriptors['default']['type']], 'errorResponses' => $this->descriptors], $id, 'id', $params);
        }
    };
    $resource->descriptors = [
        400 => ['type' => 'string', 'schemaNullable' => false, 'rules' => ['minLength' => 2]],
        401 => ['type' => $list::class, 'schemaNullable' => false, 'rules' => ['minItems' => 1]],
        402 => ['type' => $map::class, 'schemaNullable' => false], 403 => ['type' => 'bool', 'schemaNullable' => true],
        404 => ['type' => null, 'schemaNullable' => false], 405 => ['type' => ErrorPayloadHttpCode::class, 'schemaNullable' => false],
        406 => ['type' => 'string', 'schemaNullable' => false, 'mediaType' => 'text/plain', 'rules' => ['minLength' => 2]],
        407 => ['type' => 'string', 'schemaNullable' => false, 'mediaType' => 'application/octet-stream'],
        409 => ['type' => ErrorPayloadHttpChoice::class, 'schemaNullable' => false],
        '4XX' => ['type' => 'int', 'schemaNullable' => false, 'rules' => ['minimum' => 0]],
        'default' => ['type' => $detail::class, 'schemaNullable' => false],
    ];
    $params = new class((object) ['trace' => 'all', 'header' => 'header']) extends \Dmorenof\SdkRuntime\DTO {
        public const array OPERATION_FIELDS = ['query' => ['trace' => 'trace'], 'header' => ['X-Test' => 'header']];
        public ?string $trace; public ?string $header;
    }; $dto = null;
    foreach ([400 => 'issue', 401 => [1, 2], 402 => ['one' => 1], 403 => null, 404 => null, 405 => 'denied', 406 => 'plain error', 407 => "\0\xffbin", 409 => 'many', 422 => 7, 500 => ['code' => 500, 'message' => 'failed']] as $status => $expected) {
        try { $resource->getById($status, $params); throw new RuntimeException('HTTP failure became success.'); }
        catch (\Dmorenof\SdkRuntime\ApiException $error) {
            if ($error->getCode() !== $status || $error->getPrevious() !== null) { throw new RuntimeException('HTTP status or valid error decoding changed.'); }
            $value = $error->payload;
            if ($value instanceof \Dmorenof\SdkRuntime\TypedArray || $value instanceof \Dmorenof\SdkRuntime\Map) { $value = iterator_to_array($value); }
            elseif ($value instanceof \BackedEnum) { $value = $value->value; }
            elseif ($value instanceof \Dmorenof\SdkRuntime\AnyOf) { $value = $value->matchedVariants()[0]; }
            elseif ($value instanceof \Dmorenof\SdkRuntime\DTO) { $dto = $value; $value = (array) $value->jsonSerializeForResponse(); }
            if ($value !== $expected || ($status === 500 && $error->error !== $error->payload) || ($status !== 500 && $error->error !== null)) { throw new RuntimeException('Error payload or legacy DTO property changed.'); }
        }
    }
    foreach ([[400, 'short', UnexpectedValueException::class], [401, 'json', JsonException::class]] as [$status, $trace, $cause]) {
        $params->trace = $trace;
        try { $resource->getById($status, $params); throw new RuntimeException('Invalid error payload became success.'); }
        catch (\Dmorenof\SdkRuntime\ApiException $error) {
            if ($error->getCode() !== $status || $error->payload !== null || !$error->getPrevious() instanceof $cause || $error->response->body === '') { throw new RuntimeException('Invalid decoding lost its HTTP failure/cause.'); }
        }
    }
    if ($dto === null) { throw new RuntimeException('Missing DTO error.'); } $dto->message = 'x';
    try { $dto->jsonSerializeForResponse(); throw new RuntimeException('Invalid error DTO mutation accepted.'); } catch (UnexpectedValueException) {}
} finally { foreach ($pipes as $pipe) { fclose($pipe); } proc_terminate($process); proc_close($process); }
echo 'ERROR_PAYLOADS_RUNTIME_HTTP_OK', PHP_EOL;
