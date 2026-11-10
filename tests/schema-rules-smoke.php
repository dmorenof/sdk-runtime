<?php
declare(strict_types=1);
require $argv[1] ?? throw new RuntimeException('Missing autoload.');
$process = proc_open([PHP_BINARY, __DIR__ . '/schema-rules-http-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start HTTP server.'); }
try {
    stream_set_timeout($pipes[1], 15); $port = trim((string) fgets($pipes[1]));
    if (!ctype_digit($port)) { throw new RuntimeException('Missing port.'); }
    $client = new class('http://127.0.0.1:' . $port) extends \Dmorenof\SdkRuntime\ApiClient {
        public function submit(\Dmorenof\SdkRuntime\DTO $body): mixed {
            return $this->executeOperation(['method' => 'POST', 'path' => '/rules', 'responseType' => $body::class,
                'validation' => ['amount' => ['multipleOf' => 0.1]]], queryParameters: ['amount' => 0.3], body: $body);
        }
        public function scalar(bool $bad = false): mixed {
            return $this->executeOperation(['method' => 'GET', 'path' => $bad ? '/scalar-bad' : '/scalar', 'responseType' => 'float', 'responseValidation' => ['exclusiveMinimum' => 0, 'exclusiveMaximum' => 1, 'multipleOf' => 0.1]]);
        }
        public function collection(string $type): mixed { return $this->executeOperation(['method' => 'GET', 'path' => '/collection', 'responseType' => $type]); }
    };
    $map = new class((object) ['only' => 1]) extends \Dmorenof\SdkRuntime\Map {
        protected const string VALUE_TYPE = 'int'; protected const array MAP_VALIDATION = ['minProperties' => 1, 'maxProperties' => 1];
    };
    $body = new class((object) ['id' => 8, 'amount' => 0.3, 'rows' => [1, 2], 'tag' => 7, 'profile' => $map->jsonSerialize(), 'label' => 'ñ']) extends \Dmorenof\SdkRuntime\DTO {
        protected const array READ_ONLY_FIELDS = ['id'];
        protected const array MODEL_VALIDATION = ['minProperties' => 5, 'maxProperties' => 6];
        protected const array VALIDATION = ['amount' => ['exclusiveMinimum' => 0, 'exclusiveMaximum' => 1, 'multipleOf' => 0.1], 'rows' => ['uniqueItems' => true, 'minItems' => 2], 'tag' => ['const' => 7], 'profile' => ['minProperties' => 1, 'maxProperties' => 1], 'label' => ['minLength' => 1, 'maxLength' => 1]];
        public int $id; public float $amount; public array $rows; public int $tag; public mixed $profile; public string $label;
    };
    if ($client->submit($body)->tag !== 7 || $client->scalar() !== 0.3) { throw new RuntimeException('Wrong hydrated response.'); }
    try { $client->scalar(true); throw new RuntimeException('Invalid scalar response accepted.'); } catch (UnexpectedValueException) {}
    $prototype = new class([1, 2]) extends \Dmorenof\SdkRuntime\TypedArray { protected string $expected_type = 'integer'; protected const array COLLECTION_VALIDATION = ['uniqueItems' => true]; };
    $collection = $client->collection($prototype::class); $collection[] = 1;
    try { (string) $collection; throw new RuntimeException('Invalid collection mutation accepted.'); } catch (UnexpectedValueException) {}
    $items = new class([2, 4]) extends \Dmorenof\SdkRuntime\TypedArray { protected string $expected_type = 'integer'; protected const array ITEM_VALIDATION = ['multipleOf' => 2]; };
    $items->exchangeArray([3]);
    try { \Dmorenof\SdkRuntime\DTO::validateNestedValue($items, 'request'); throw new RuntimeException('Inherited collection mutation bypassed item rules.'); } catch (UnexpectedValueException) {}
    $body->tag = 8;
    try { $client->submit($body); throw new RuntimeException('Invalid request mutation reached HTTP.'); } catch (UnexpectedValueException) {}
} finally { foreach ($pipes as $pipe) { fclose($pipe); } proc_terminate($process); proc_close($process); }
echo 'SCHEMA_RULES_RUNTIME_HTTP_OK', PHP_EOL;
