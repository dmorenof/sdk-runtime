<?php
declare(strict_types=1);

require $argv[1] ?? throw new RuntimeException('Missing autoload path.');
$bytes = 32 * 1024 * 1024;
$file = tmpfile();
if ($file === false || !ftruncate($file, $bytes)) {
    throw new RuntimeException('Cannot prepare streaming fixture.');
}
$http = isset($argv[2]) ? null : new class($bytes) implements \Psr\Http\Client\ClientInterface {
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
$client = new class($argv[2] ?? 'https://example.test', httpClient: $http) extends \Dmorenof\SdkRuntime\ApiClient {
    public function submit(\Psr\Http\Message\UploadedFileInterface $file): void
    {
        $body = new class((object) ['file' => $file, 'name' => 'bounded']) extends \Dmorenof\SdkRuntime\DTO {
            public string|\Psr\Http\Message\UploadedFileInterface $file;
            public string $name;
        };
        $this->executeOperation(['method' => 'POST', 'path' => '/uploads', 'success' => [204],
            'requestMediaType' => 'multipart/form-data', 'bodyParts' => [
                'file' => ['contentType' => null, 'filename' => 'fallback.bin'], 'name' => ['contentType' => 'text/plain'],
            ]], queryParameters: ['body' => 'query body'], headerParameters: ['X-Trace' => 'trace'], body: $body);
    }
};
try {
    $upload = new \GuzzleHttp\Psr7\UploadedFile($file, $bytes, UPLOAD_ERR_OK, 'large.bin', 'application/octet-stream');
    $client->submit($upload);
    $client->submit($upload);
    if (memory_get_peak_usage(true) >= 16 * 1024 * 1024) {
        throw new RuntimeException('Streaming fixture exceeded its PHP memory bound.');
    }
    echo 'STREAMING_32M_UNDER_16M_OK peak=', memory_get_peak_usage(true), PHP_EOL;
} finally {
    fclose($file);
}
