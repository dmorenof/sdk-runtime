<?php
declare(strict_types=1);
require $argv[1] ?? throw new RuntimeException('Missing autoload.');
$process = proc_open([PHP_BINARY, __DIR__ . '/form-block-http-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start HTTP server.'); }
try {
    stream_set_timeout($pipes[1], 15); $port = trim((string) fgets($pipes[1]));
    if (!ctype_digit($port)) { throw new RuntimeException('Missing port.'); }
    $client = new class('http://127.0.0.1:' . $port) extends \Dmorenof\SdkRuntime\ApiClient {
        public function submit(string $path, \Dmorenof\SdkRuntime\DTO $body, array $formats = [], array $parts = []): void {
            $this->executeOperation(['method' => 'POST', 'path' => $path, 'success' => [204],
                'requestMediaType' => $parts === [] ? 'application/x-www-form-urlencoded' : 'multipart/form-data', 'bodySerialization' => $formats, 'bodyParts' => $parts],
                queryParameters: ['body' => 'query body'], headerParameters: ['X-Trace' => 'trace'], body: $body);
        }
    };
    $body = new class extends \Dmorenof\SdkRuntime\DTO {
        public string $body = 'form body'; public array $space = ['one', 'two']; public array $pipe = ['a/b', '%2F'];
        public \Dmorenof\SdkRuntime\DTO $filter; public \Dmorenof\SdkRuntime\DTO $expanded; public \Dmorenof\SdkRuntime\DTO $deep;
    };
    $body->filter = new class extends \Dmorenof\SdkRuntime\DTO { public string $code = 'a/b'; public bool $flag = false; };
    $body->expanded = new class extends \Dmorenof\SdkRuntime\DTO { public string $city = 'Madrid'; public int $zip = 0; };
    $body->deep = new class extends \Dmorenof\SdkRuntime\DTO { public string $name = 'niño'; };
    $client->submit('/form', $body, ['space' => 'ssv', 'pipe' => 'pipes', 'filter' => 'form-object', 'expanded' => 'form-object-explode', 'deep' => 'deepObject']);
    $profile = new class extends \Dmorenof\SdkRuntime\DTO {
        protected const array READ_ONLY_FIELDS = ['id']; public int $id = 7; public string $code = 'a/b'; public \Dmorenof\SdkRuntime\DTO $nested;
    };
    $profile->nested = new class extends \Dmorenof\SdkRuntime\DTO { public bool $flag = false; };
    $multipart = new class extends \Dmorenof\SdkRuntime\DTO { public \Dmorenof\SdkRuntime\DTO $profile; public array $batch; public string $asJson = 'quoted'; public string $document = "\x00\xfffile"; public int $count = 0; };
    $multipart->profile = $profile; $multipart->batch = [$profile, $profile];
    $client->submit('/multipart', $multipart, parts: ['profile' => ['contentType' => 'application/json', 'json' => true], 'batch' => ['contentType' => 'application/vnd.test+json', 'json' => true], 'asJson' => ['contentType' => 'application/json', 'json' => true], 'document' => ['contentType' => null, 'filename' => 'document.bin'], 'count' => ['contentType' => 'text/plain']]);
    $swagger = new class extends \Dmorenof\SdkRuntime\DTO { public array $csv = [1, 2]; public array $multi = ['x', 'y']; public array $ssv = ['one', 'two']; public array $tsv = ['one', 'two']; public array $pipes = ['a/b', '%2F']; };
    $client->submit('/swagger', $swagger, ['csv' => 'csv', 'multi' => 'multi', 'ssv' => 'ssv', 'tsv' => 'tsv', 'pipes' => 'pipes']);
} finally { foreach ($pipes as $pipe) { fclose($pipe); } proc_terminate($process); proc_close($process); }
echo 'FORM_BLOCK_RUNTIME_HTTP_OK', PHP_EOL;
