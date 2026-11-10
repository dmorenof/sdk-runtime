<?php
declare(strict_types=1);
require $argv[1] ?? throw new RuntimeException('Missing autoload.');
$process = proc_open([PHP_BINARY, __DIR__ . '/native-schemas-http-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start HTTP server.'); }
try {
    stream_set_timeout($pipes[1], 15); $port = trim((string) fgets($pipes[1]));
    if (!ctype_digit($port)) { throw new RuntimeException('Missing port.'); }
    $prototype = new class((object) ['id' => 1, 'value' => 'valid', 'open' => (object) ['nested' => [true, null]], 'nullOnly' => null, 'items' => [1, 'text', null], 'empty' => []]) extends \Dmorenof\SdkRuntime\DTO {
        public const array REQUIRED_FIELDS = ['id', 'value', 'open', 'nullOnly', 'items', 'empty_'];
        protected const array PROPERTY_MAP = ['empty' => 'empty_']; protected const array WIRE_PROPERTY_MAP = ['empty_' => 'empty'];
        protected const array VALIDATION = ['id' => ['minimum' => 0], 'value' => ['schema' => ['type' => ['string', 'integer', 'boolean', 'null'], 'minLength' => 2, 'minimum' => 0]], 'open' => ['schema' => true], 'rejected' => ['schema' => false], 'items' => ['schema' => ['type' => 'array', 'items' => ['type' => ['string', 'integer', 'null']]]], 'empty_' => ['schema' => ['type' => 'array', 'items' => false]]];
        public int $id; public string|int|bool|null $value; public mixed $open; public mixed $rejected; public null $nullOnly; public array $items; public array $empty_;
    };
    $client = new class('http://127.0.0.1:' . $port) extends \Dmorenof\SdkRuntime\ApiClient {
        public string $recordType;
        public function record(?\Dmorenof\SdkRuntime\DTO $body = null): mixed {
            return $this->executeOperation(['method' => $body === null ? 'GET' : 'POST', 'path' => '/native', 'responseType' => $this->recordType], body: $body);
        }
        public function value(string $path): mixed {
            return $this->executeOperation(['method' => 'GET', 'path' => $path, 'responseType' => match ($path) { '/value' => 'string|int|bool|null', '/items' => 'array', default => 'mixed' },
                'responseValidation' => ['schema' => match ($path) { '/never' => false, '/any' => true, '/items' => ['type' => 'array', 'items' => ['type' => ['string', 'integer', 'null']]], default => ['type' => ['string', 'integer', 'boolean', 'null']] }]]);
        }
        public function parameter(\Dmorenof\SdkRuntime\DTO $params): mixed {
            return $this->executeArguments(['method' => 'GET', 'path' => '/param/{id}', 'responseType' => 'string|int|bool|null',
                'parameterContent' => ['query' => ['json' => 'application/json', 'plain' => 'text/plain']]], identifier: 1, identifierName: 'id', payload: $params);
        }
    };
    $client->recordType = $prototype::class;
    $record = $client->record(); if ($record->id !== 1) { throw new RuntimeException('Integral JSON number lost its integer value.'); }
    $client->record($record);
    if ($client->value('/value') !== false || $client->value('/any')->nested !== [1, null]) { throw new RuntimeException('Native JSON kinds changed.'); }
    try { $client->value('/never'); throw new RuntimeException('False response schema accepted.'); } catch (UnexpectedValueException) {}
    if ($client->value('/items') !== [1, 'x', null]) { throw new RuntimeException('Native array items changed.'); }
    $params = new class((object) ['value' => false, 'json' => 1.0, 'plain' => 'hello', 'header' => 'ab', 'cookie' => 'ab']) extends \Dmorenof\SdkRuntime\DTO {
        public const array OPERATION_FIELDS = ['query' => ['value' => 'value', 'json' => 'json', 'plain' => 'plain'], 'header' => ['X-Alias' => 'header'], 'cookie' => ['session' => 'cookie']];
        protected const array VALIDATION = ['value' => ['schema' => ['type' => ['string', 'integer', 'boolean', 'null'], 'minLength' => 2, 'minimum' => 0]], 'json' => ['minimum' => 0], 'plain' => ['minLength' => 2], 'header' => ['minLength' => 2], 'cookie' => ['minLength' => 2]];
        public string|int|bool|null $value; public ?int $json; public ?string $plain; public ?string $header; public ?string $cookie;
    };
    if ($client->parameter($params) !== 'result') { throw new RuntimeException('Wrong parameter response.'); }
    $params->value = null; $client->parameter($params);
    $record->rejected = null;
    try { $client->record($record); throw new RuntimeException('False property mutation reached HTTP.'); } catch (UnexpectedValueException) {}
    unset($record->rejected); $record->items[] = false;
    try { $client->record($record); throw new RuntimeException('Invalid native array mutation reached HTTP.'); } catch (UnexpectedValueException) {}
} finally { foreach ($pipes as $pipe) { fclose($pipe); } proc_terminate($process); proc_close($process); }
echo 'NATIVE_SCHEMAS_RUNTIME_HTTP_OK', PHP_EOL;
