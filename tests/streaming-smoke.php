<?php
declare(strict_types=1);

require $argv[1] ?? throw new RuntimeException('Missing autoload path.');
$bytes = 32 * 1024 * 1024;
$list = ($argv[3] ?? '') === 'list';
$realHttp = isset($argv[2]) && $argv[2] !== '';
$http = $realHttp ? null : new class($bytes) implements \Psr\Http\Client\ClientInterface {
    public function __construct(private int $fileBytes) {}
    public function sendRequest(\Psr\Http\Message\RequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        $body = $request->getBody();
        if (!$body instanceof \GuzzleHttp\Psr7\MultipartStream || $body->tell() !== 0) {
            throw new RuntimeException('Multipart body was buffered or not rewound.');
        }
        $size = $body->getSize();
        if ($size === null || $size <= $this->fileBytes || $size > $this->fileBytes + 4096) {
            throw new RuntimeException('Incorrect multipart size.');
        }
        $read = 0;
        while (!$body->eof()) {
            $read += strlen($body->read(65536));
        }
        if ($read !== $size) {
            throw new RuntimeException('Multipart upload truncated.');
        }
        return new \GuzzleHttp\Psr7\Response(204);
    }
};
$client = new class($realHttp ? $argv[2] : 'https://example.test', httpClient: $http) extends \Dmorenof\SdkRuntime\ApiClient {
    public function submit(\Psr\Http\Message\UploadedFileInterface|array $file): void
    {
        $values = (object) ['file' => $file, 'name' => 'bounded'];
        $body = is_array($file)
            ? new class($values) extends \Dmorenof\SdkRuntime\DTO {
                protected const array VALIDATION = ['file' => ['minItems' => 2, 'maxItems' => 2, 'items' => ['type' => 'binary', 'rules' => []]]];
                public array $file;
                public string $name;
            }
            : new class($values) extends \Dmorenof\SdkRuntime\DTO {
                public string|\Psr\Http\Message\UploadedFileInterface $file;
                public string $name;
            };
        $this->executeOperation(['method' => 'POST', 'path' => '/uploads', 'success' => [204],
            'requestMediaType' => 'multipart/form-data', 'bodyParts' => [
                'file' => ['contentType' => null, 'filename' => 'fallback.bin'], 'name' => ['contentType' => 'text/plain'],
            ]], queryParameters: ['body' => 'query body'], headerParameters: ['X-Trace' => 'trace'], body: $body);
    }
};
$files = [];
try {
    $uploads = [];
    $count = $list ? 2 : 1;
    for ($index = 0; $index < $count; ++$index) {
        $file = tmpfile();
        if ($file === false) {
            throw new RuntimeException('Cannot prepare streaming fixture.');
        }
        $files[] = $file;
        if (!ftruncate($file, intdiv($bytes, $count))) {
            throw new RuntimeException('Cannot size streaming fixture.');
        }
        $uploads[] = new \GuzzleHttp\Psr7\UploadedFile($file, intdiv($bytes, $count), UPLOAD_ERR_OK,
            $list ? 'large-' . ($index + 1) . '.bin' : 'large.bin', 'application/octet-stream');
    }
    $payload = $list ? $uploads : $uploads[0];
    $client->submit($payload);
    $client->submit($payload);
    if (memory_get_peak_usage(true) >= 16 * 1024 * 1024) {
        throw new RuntimeException('Streaming fixture exceeded its PHP memory bound.');
    }
    echo $list ? 'STREAMING_FILE_LIST_32M_UNDER_16M_OK' : 'STREAMING_32M_UNDER_16M_OK';
    echo ' peak=', memory_get_peak_usage(true), PHP_EOL;
} finally {
    foreach ($files as $file) {
        fclose($file);
    }
}
