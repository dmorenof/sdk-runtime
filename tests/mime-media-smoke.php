<?php
declare(strict_types=1);
require $argv[1] ?? throw new RuntimeException('Missing autoload.');
$process = proc_open([PHP_BINARY, __DIR__ . '/mime-media-http-server.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
if (!is_resource($process)) { throw new RuntimeException('Cannot start server.'); }
try {
    stream_set_timeout($pipes[1], 20); $port = trim((string) fgets($pipes[1])); if (!ctype_digit($port)) { throw new RuntimeException('Missing port.'); }
    $input = new class((object) ['value' => 7]) extends \Dmorenof\SdkRuntime\DTO { public const array REQUIRED_FIELDS = ['value']; public int $value; };
    $ok = new class((object) ['ok' => true]) extends \Dmorenof\SdkRuntime\DTO { public const array REQUIRED_FIELDS = ['ok']; public bool $ok; };
    $detail = new class((object) ['code' => 400]) extends \Dmorenof\SdkRuntime\DTO { public const array REQUIRED_FIELDS = ['code']; public int $code; };
    $client = new class('http://127.0.0.1:' . $port) extends \Dmorenof\SdkRuntime\ApiClient {
        public string $inputType; public string $okType; public string $detailType;
        public function csv(string $body): mixed {
            return $this->executeBody(['method' => 'POST', 'path' => '/csv', 'responseType' => 'string', 'responseMediaType' => 'text/csv; charset=utf-8', 'responseEncoding' => 'text', 'responseValidation' => ['minLength' => 2],
                'requestMediaType' => 'text/csv; charset=utf-8', 'requestBody' => ['type' => 'string', 'nullable' => false, 'required' => true, 'encoding' => 'text', 'rules' => ['minLength' => 2]]], $body);
        }
        public function xml(string $body): mixed {
            return $this->executeBody(['method' => 'POST', 'path' => '/xml', 'responseType' => 'string', 'responseMediaType' => 'application/xml', 'responseEncoding' => 'text', 'responseValidation' => ['minLength' => 4],
                'requestMediaType' => 'application/xml', 'requestBody' => ['type' => 'string', 'nullable' => false, 'required' => true, 'encoding' => 'text', 'rules' => ['minLength' => 4, 'pattern' => '^<']]], $body);
        }
        public function image(\Dmorenof\SdkRuntime\DTO $params): mixed {
            return $this->executeBody(['method' => 'POST', 'path' => '/image/{id}', 'success' => [201], 'responseType' => \Psr\Http\Message\ResponseInterface::class, 'responseMediaType' => 'image/png', 'responseEncoding' => 'binary',
                'errorResponses' => [400 => ['type' => $this->detailType, 'schemaNullable' => false, 'mediaType' => 'application/problem+json']],
                'requestMediaType' => 'image/png', 'requestBody' => ['type' => 'stream', 'nullable' => false, 'required' => true, 'encoding' => 'binary']], null, identifier: 7, identifierName: 'id', payload: $params);
        }
        public function pdf(): mixed {
            return $this->executeOperation(['method' => 'GET', 'path' => '/pdf', 'success' => [200, 201], 'responseTypes' => [
                200 => ['type' => \Psr\Http\Message\ResponseInterface::class, 'schemaNullable' => false, 'mediaType' => 'application/pdf', 'encoding' => 'binary'],
                201 => ['type' => \Psr\Http\Message\ResponseInterface::class, 'schemaNullable' => false, 'mediaType' => 'image/svg+xml', 'encoding' => 'binary']],
                'errorResponses' => [404 => ['type' => 'string', 'schemaNullable' => false, 'mediaType' => 'application/xml', 'encoding' => 'text', 'rules' => ['minLength' => 4]]]]);
        }
        public function problem(\Dmorenof\SdkRuntime\DTO $body): mixed {
            return $this->executeBody(['method' => 'POST', 'path' => '/problem', 'responseType' => $this->okType, 'responseMediaType' => 'application/problem+json',
                'errorResponses' => [400 => ['type' => 'string', 'schemaNullable' => false, 'mediaType' => 'application/problem+json', 'rules' => ['minLength' => 2]]],
                'requestMediaType' => 'application/vnd.example+json', 'requestBody' => ['type' => $this->inputType, 'nullable' => false, 'required' => true]], $body);
        }
        public function mixed(): mixed {
            return $this->executeOperation(['method' => 'GET', 'path' => '/mixed', 'success' => [200, 201, 202, 204], 'responseTypes' => [
                200 => ['type' => 'string', 'schemaNullable' => false, 'mediaType' => 'application/xml', 'encoding' => 'text', 'rules' => ['minLength' => 4]],
                201 => ['type' => 'int', 'schemaNullable' => false, 'mediaType' => 'application/problem+json', 'rules' => ['minimum' => 0]],
                202 => ['type' => \Psr\Http\Message\ResponseInterface::class, 'schemaNullable' => false, 'mediaType' => 'application/pdf', 'encoding' => 'binary'],
                204 => ['type' => null, 'schemaNullable' => false]], 'errorResponses' => [
                406 => ['type' => 'string', 'schemaNullable' => false, 'mediaType' => 'text/csv', 'encoding' => 'text', 'rules' => ['minLength' => 2]],
                409 => ['type' => 'string', 'schemaNullable' => false, 'mediaType' => 'image/png', 'encoding' => 'binary']]]);
        }
    };
    $client->inputType = $input::class; $client->okType = $ok::class; $client->detailType = $detail::class;
    if ($client->csv("a,b\n1,2\n") !== "a,b\n3,4\n" || $client->xml('<input>ñ</input>') !== '<reply/>') { throw new RuntimeException('Raw CSV/XML codecs changed.'); }
    $file = tmpfile(); if ($file === false) { throw new RuntimeException('Missing upload.'); } for ($n = 0; $n < 4096; ++$n) { fwrite($file, str_repeat('x', 8192)); }
    $stream = \GuzzleHttp\Psr7\Utils::streamFor($file); $stream->seek(100);
    $params = new class((object) ['body' => $stream, 'queryBody' => 'separate', 'header' => 'header']) extends \Dmorenof\SdkRuntime\DTO {
        public const array REQUIRED_FIELDS = ['body']; public const bool OPERATION_BODY = true;
        public const array OPERATION_FIELDS = ['query' => ['body' => 'queryBody'], 'header' => ['X-Test' => 'header']];
        public string|\Psr\Http\Message\StreamInterface $body; public ?string $queryBody; public ?string $header;
    };
    if ((string) $client->image($params)->getBody() !== "\0\xffpng") { throw new RuntimeException('Image binary response changed.'); } $stream->close();
    $response = $client->pdf();
    if ($response->getHeaderLine('Content-Type') !== 'application/pdf') { throw new RuntimeException('PDF media lost.'); }
    $actual = hash_init('sha256'); $bytes = 0;
    while (!$response->getBody()->eof()) { $chunk = $response->getBody()->read(8192); hash_update($actual, $chunk); $bytes += strlen($chunk); }
    $response->getBody()->close(); $expected = hash_init('sha256'); for ($n = 0; $n < 4096; ++$n) { hash_update($expected, str_repeat('z', 8192)); }
    if ($bytes !== 33554432 || hash_final($actual) !== hash_final($expected) || (string) $client->pdf()->getBody() !== '<svg/>') { throw new RuntimeException('PDF/SVG binary codecs changed.'); }
    $payload = $input;
    if (!$client->problem($payload)->ok) { throw new RuntimeException('Vendor JSON changed.'); }
    if ($client->mixed() !== '<xml/>' || $client->mixed() !== 7 || (string) $client->mixed()->getBody() !== '%PDF bytes' || $client->mixed() !== null) { throw new RuntimeException('Mixed MIME status codecs changed.'); }
    foreach (["error,csv\n", "\0\xfferror"] as $expectedError) {
        try { $client->mixed(); throw new RuntimeException('Raw MIME error became success.'); }
        catch (\Dmorenof\SdkRuntime\ApiException $error) { if ($error->payload !== $expectedError || $error->getPrevious() !== null) { throw new RuntimeException('Raw MIME error changed.'); } }
    }
    try { $client->pdf(); throw new RuntimeException('XML error became success.'); } catch (\Dmorenof\SdkRuntime\ApiException $error) { if ($error->payload !== '<error/>') { throw new RuntimeException('XML error decoding changed.'); } }
    $params->body = 'small';
    try { $client->image($params); throw new RuntimeException('Problem DTO became success.'); } catch (\Dmorenof\SdkRuntime\ApiException $error) { if ($error->error?->code !== 400 || $error->payload !== $error->error) { throw new RuntimeException('Problem DTO decoding changed.'); } }
    try { $client->problem($payload); throw new RuntimeException('Problem JSON error became success.'); } catch (\Dmorenof\SdkRuntime\ApiException $error) { if ($error->payload !== 'problem') { throw new RuntimeException('Problem JSON decoded as raw text.'); } }
} finally { foreach ($pipes as $pipe) { fclose($pipe); } proc_terminate($process); proc_close($process); }
echo 'MIME_MEDIA_RUNTIME_HTTP_OK size=', $bytes, ' peak=', memory_get_peak_usage(true), PHP_EOL;
