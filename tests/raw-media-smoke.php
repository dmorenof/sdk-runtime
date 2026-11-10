<?php
declare(strict_types=1);
require $argv[1] ?? throw new RuntimeException('Missing autoload.');
$process = proc_open([PHP_BINARY, __DIR__ . '/raw-media-http-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start server.'); }
try {
    stream_set_timeout($pipes[1], 20); $port = trim((string) fgets($pipes[1])); if (!ctype_digit($port)) { throw new RuntimeException('Missing port.'); }
    $client = new class('http://127.0.0.1:' . $port) extends \Dmorenof\SdkRuntime\ApiClient {
        public function text(string $value): mixed { return $this->executeBody(['method' => 'POST', 'path' => '/text', 'responseType' => 'string', 'responseMediaType' => 'text/plain', 'responseValidation' => ['minLength' => 2],
            'requestMediaType' => 'text/plain', 'requestBody' => ['type' => 'string', 'nullable' => false, 'required' => true, 'encoding' => 'text', 'rules' => ['minLength' => 2]]], $value); }
        public function optional(?string $value = null): mixed { return $this->executeBody(['method' => 'POST', 'path' => '/optional', 'success' => [204], 'requestMediaType' => 'text/plain',
            'requestBody' => ['type' => 'string', 'nullable' => false, 'required' => false, 'encoding' => 'text']], $value, func_num_args() > 0); }
        public function binary(\Dmorenof\SdkRuntime\DTO $params): mixed { return $this->executeBody(['method' => 'POST', 'path' => '/binary/{id}', 'success' => [204], 'requestMediaType' => 'application/octet-stream',
            'requestBody' => ['type' => 'stream', 'nullable' => false, 'required' => true, 'encoding' => 'binary']], null, identifier: 7, identifierName: 'id', payload: $params); }
        public function mixed(): mixed { return $this->executeOperation(['method' => 'GET', 'path' => '/mixed', 'success' => [200, 201, 202, 204], 'responseTypes' => [
            200 => ['type' => 'string', 'schemaNullable' => false, 'mediaType' => 'text/plain'], 201 => ['type' => 'int', 'schemaNullable' => false],
            202 => ['type' => \Psr\Http\Message\ResponseInterface::class, 'schemaNullable' => false], 204 => ['type' => null, 'schemaNullable' => false]]]); }
    };
    if ($client->text("ñ text\n") !== "sin JSON ñ\n") { throw new RuntimeException('Text was JSON encoded/decoded.'); }
    $client->optional(); $client->optional('');
    $file = tmpfile(); if ($file === false) { throw new RuntimeException('Missing temporary upload.'); }
    for ($n = 0; $n < 4096; ++$n) { fwrite($file, str_repeat('x', 8192)); }
    $stream = \GuzzleHttp\Psr7\Utils::streamFor($file); $stream->seek(100);
    $params = new class((object) ['body' => $stream, 'queryBody' => 'separate', 'header' => 'header']) extends \Dmorenof\SdkRuntime\DTO {
        public const array REQUIRED_FIELDS = ['body']; public const bool OPERATION_BODY = true;
        public const array OPERATION_FIELDS = ['query' => ['body' => 'queryBody'], 'header' => ['X-Test' => 'header']];
        public string|\Psr\Http\Message\StreamInterface $body; public ?string $queryBody; public ?string $header;
    };
    $client->binary($params); $stream->close();
    if ($client->mixed() !== 'plain response' || $client->mixed() !== 7) { throw new RuntimeException('Mixed text/JSON codecs changed.'); }
    $binary = $client->mixed(); if (!$binary instanceof \Psr\Http\Message\ResponseInterface || (string) $binary->getBody() !== "\0\xffbin" || $client->mixed() !== null) { throw new RuntimeException('Mixed binary/empty codecs changed.'); }
    try { $client->optional(null); throw new RuntimeException('Null was silently omitted.'); } catch (UnexpectedValueException) {}
} finally { foreach ($pipes as $pipe) { fclose($pipe); } proc_terminate($process); proc_close($process); }
echo 'RAW_MEDIA_RUNTIME_HTTP_OK', PHP_EOL;
