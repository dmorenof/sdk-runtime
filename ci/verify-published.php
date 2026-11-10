<?php
declare(strict_types=1);

$tag = $argv[1] ?? throw new RuntimeException('Missing release tag.');
$expected = $argv[2] ?? throw new RuntimeException('Missing expected commit.');
$directory = sys_get_temp_dir() . '/runtime-published-' . bin2hex(random_bytes(8));
mkdir($directory, 0700, true);
file_put_contents($directory . '/composer.json', json_encode([
    'name' => 'example/published-runtime-smoke',
    'repositories' => [['type' => 'vcs', 'url' => 'https://github.com/dmorenof/sdk-runtime', 'no-api' => true]],
    'require' => ['dmorenof/sdk-runtime' => ltrim($tag, 'v')],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
passthru('composer install --no-interaction --prefer-dist --no-progress --working-dir=' . escapeshellarg($directory), $status);
if ($status !== 0) {
    throw new RuntimeException('Published runtime Composer installation failed.');
}
require $directory . '/vendor/autoload.php';
if (\Composer\InstalledVersions::getReference('dmorenof/sdk-runtime') !== $expected
    || !class_exists(\Dmorenof\SdkRuntime\ApiClient::class)) {
    throw new RuntimeException('Installed runtime does not match the immutable release commit.');
}
echo "PUBLISHED_RUNTIME_INSTALL_OK ", $tag, " ", $expected, PHP_EOL;
$transport = new class implements \Dmorenof\SdkRuntime\Transport\TransportInterface {
    public function send(string $method, string $url, array $headers, ?string $body): \Dmorenof\SdkRuntime\Transport\HttpResponse
    {
        if ($method === 'POST') {
            if ($url !== 'https://example.test/forms?q=a%20b'
                || $body !== 'name=%C3%B1+%2B%7E&ids=1&ids=2'
                || ($headers['Content-Type'] ?? null) !== 'application/x-www-form-urlencoded') {
                throw new RuntimeException('Installed runtime form encoding failed.');
            }
            return new \Dmorenof\SdkRuntime\Transport\HttpResponse(204, '');
        }
        if ($url !== 'https://example.test/search?ids=1&ids=2&codes=a%2Cb,x%20y&spaces=3%204&pipes=a%7Cb&tabs=5%096&filter%5Bcity%5D=Madrid&filter%5Bactive%5D=false') {
            throw new RuntimeException('Installed runtime query serialization failed.');
        }
        return new \Dmorenof\SdkRuntime\Transport\HttpResponse(204, '');
    }
};
$client = new class('https://example.test', transport: $transport) extends \Dmorenof\SdkRuntime\ApiClient {
    public function formProbe(): void
    {
        $body = new class((object) ['name' => 'ñ +~', 'ids' => [1, 2]]) extends \Dmorenof\SdkRuntime\DTO {
            public string $name;
            public array $ids;
        };
        $params = new class extends \Dmorenof\SdkRuntime\DTO {
            public const bool OPERATION_BODY = true;
            public const array OPERATION_FIELDS = ['path' => [], 'query' => ['q' => 'q'], 'header' => []];
            public string $q;
            public \Dmorenof\SdkRuntime\DTO $body;
        };
        $params->q = 'a b';
        $params->body = $body;
        $this->executeArguments(['method' => 'POST', 'path' => '/forms', 'success' => [204],
            'requestMediaType' => 'application/x-www-form-urlencoded', 'bodySerialization' => ['ids' => 'multi']],
            payload: $params);
    }
    public function probe(): void
    {
        $this->executeOperation(['method' => 'GET', 'path' => '/search', 'success' => [204],
            'querySerialization' => ['ids' => 'multi', 'codes' => 'csv', 'spaces' => 'ssv', 'pipes' => 'pipes', 'tabs' => 'tsv', 'filter' => 'deepObject']],
            queryParameters: ['ids' => [1, 2], 'codes' => ['a,b', 'x y'], 'spaces' => [3, 4], 'pipes' => ['a', 'b'], 'tabs' => [5, 6], 'filter' => (object) ['city' => 'Madrid', 'active' => false]]);
    }
};
$client->probe();
echo "PUBLISHED_QUERY_SERIALIZATION_OK", PHP_EOL;

$client->formProbe();
echo "PUBLISHED_FORM_SERIALIZATION_OK", PHP_EOL;

$configuration = new \Dmorenof\SdkRuntime\Configuration();
$configuration->headers['X-Trace'] = 'one';
if ((new \Dmorenof\SdkRuntime\Configuration())->headers !== []) {
    throw new RuntimeException('Shared configuration leaked state.');
}
echo "PUBLISHED_SHARED_EXECUTION_OK", PHP_EOL;

$multipart = new class extends \Dmorenof\SdkRuntime\DTO { public string $file; public bool $active; };
$multipart->file = "\x00\xff\r\n";
$multipart->active = false;
$encoded = \Dmorenof\SdkRuntime\MultipartSerializer::encode($multipart, [
    'file' => ['contentType' => 'application/octet-stream', 'filename' => 'upload.bin'],
    'active' => ['contentType' => 'text/plain'],
]);
if (!str_contains((string) $encoded, "\r\n\r\n\x00\xff\r\n\r\n")
    || !str_contains((string) $encoded, 'filename="upload.bin"')
    || !str_contains((string) $encoded, "\r\n\r\nfalse\r\n")
    || !str_ends_with((string) $encoded, '--' . $encoded->getBoundary() . "--\r\n")) {
    throw new RuntimeException('Published Guzzle multipart adaptation failed.');
}
echo "PUBLISHED_MULTIPART_SERIALIZATION_OK", PHP_EOL;

$upload = new \GuzzleHttp\Psr7\UploadedFile(\GuzzleHttp\Psr7\Utils::streamFor("\x00\xff\r\n"), 4, UPLOAD_ERR_OK, 'photo.png', 'image/png');
$payload = new class((object) ['file' => $upload]) extends \Dmorenof\SdkRuntime\DTO {
    public string|\Psr\Http\Message\UploadedFileInterface $file;
};
$encodedUpload = \Dmorenof\SdkRuntime\MultipartSerializer::encode($payload, ['file' => ['contentType' => null, 'filename' => 'fallback.bin']]);
if ($payload->file !== $upload || !str_contains((string) $encodedUpload, 'filename="photo.png"')
    || !str_contains((string) $encodedUpload, 'Content-Type: image/png')
    || !str_contains((string) $encodedUpload, "\r\n\r\n\x00\xff\r\n\r\n")) {
    throw new RuntimeException('Published PSR uploaded file support failed.');
}
try {
    json_encode($payload, JSON_THROW_ON_ERROR);
    throw new RuntimeException('PSR upload silently encoded as JSON.');
} catch (UnexpectedValueException) {
    echo "PUBLISHED_PSR_UPLOAD_OK", PHP_EOL;
}

$streamCommand = implode(' ', array_map('escapeshellarg', [PHP_BINARY, '-d', 'memory_limit=16M',
    $directory . '/vendor/dmorenof/sdk-runtime/tests/streaming-smoke.php', $directory . '/vendor/autoload.php']));
passthru($streamCommand, $streamStatus);
if ($streamStatus !== 0) {
    throw new RuntimeException('Installed runtime multipart streaming smoke failed.');
}
echo "PUBLISHED_STREAMING_RUNTIME_OK", PHP_EOL;
$listCommand = $streamCommand . " '' 'list'";
passthru($listCommand, $listStatus);
if ($listStatus !== 0) {
    throw new RuntimeException('Installed runtime file list streaming smoke failed.');
}
echo "PUBLISHED_FILE_LIST_RUNTIME_OK", PHP_EOL;

$downloadCommand = implode(' ', array_map('escapeshellarg', [PHP_BINARY, '-d', 'memory_limit=16M',
    $directory . '/vendor/dmorenof/sdk-runtime/tests/download-smoke.php', $directory . '/vendor/autoload.php']));
passthru($downloadCommand, $downloadStatus);
if ($downloadStatus !== 0) {
    throw new RuntimeException('Installed runtime binary download smoke failed.');
}
echo "PUBLISHED_DOWNLOAD_RUNTIME_OK", PHP_EOL;

$statusCommand = implode(' ', array_map('escapeshellarg', [PHP_BINARY,
    $directory . '/vendor/dmorenof/sdk-runtime/tests/status-response-smoke.php', $directory . '/vendor/autoload.php']));
passthru($statusCommand, $statusResult);
if ($statusResult !== 0) {
    throw new RuntimeException('Installed runtime status response smoke failed.');
}
echo "PUBLISHED_STATUS_RESPONSE_RUNTIME_OK", PHP_EOL;

$simpleCommand = implode(' ', array_map('escapeshellarg', [PHP_BINARY,
    $directory . '/vendor/dmorenof/sdk-runtime/tests/simple-parameters-smoke.php', $directory . '/vendor/autoload.php']));
passthru($simpleCommand, $simpleStatus);
if ($simpleStatus !== 0) {
    throw new RuntimeException('Installed runtime simple path/header smoke failed.');
}
echo "PUBLISHED_SIMPLE_PARAMETER_RUNTIME_OK", PHP_EOL;

$objectCommand = implode(' ', array_map('escapeshellarg', [PHP_BINARY,
    $directory . '/vendor/dmorenof/sdk-runtime/tests/object-parameters-smoke.php', $directory . '/vendor/autoload.php']));
passthru($objectCommand, $objectStatus);
if ($objectStatus !== 0) {
    throw new RuntimeException('Installed runtime object parameter smoke failed.');
}
echo "PUBLISHED_OBJECT_PARAMETER_RUNTIME_OK", PHP_EOL;

$pathCommand = implode(' ', array_map('escapeshellarg', [PHP_BINARY,
    $directory . '/vendor/dmorenof/sdk-runtime/tests/path-styles-smoke.php', $directory . '/vendor/autoload.php']));
passthru($pathCommand, $pathStatus);
if ($pathStatus !== 0) { throw new RuntimeException('Installed runtime label/matrix HTTP smoke failed.'); }
echo "PUBLISHED_PATH_STYLE_RUNTIME_OK", PHP_EOL;

$cookieCommand = implode(' ', array_map('escapeshellarg', [PHP_BINARY,
    $directory . '/vendor/dmorenof/sdk-runtime/tests/cookies-smoke.php', $directory . '/vendor/autoload.php']));
passthru($cookieCommand, $cookieStatus);
if ($cookieStatus !== 0) { throw new RuntimeException('Installed runtime cookie HTTP smoke failed.'); }
echo "PUBLISHED_COOKIE_RUNTIME_OK", PHP_EOL;

$reservedCommand = implode(' ', array_map('escapeshellarg', [PHP_BINARY,
    $directory . '/vendor/dmorenof/sdk-runtime/tests/reserved-query-smoke.php', $directory . '/vendor/autoload.php']));
passthru($reservedCommand, $reservedStatus);
if ($reservedStatus !== 0) { throw new RuntimeException('Installed runtime reserved query HTTP smoke failed.'); }
echo "PUBLISHED_RESERVED_QUERY_RUNTIME_OK", PHP_EOL;

$contentCommand = implode(' ', array_map('escapeshellarg', [PHP_BINARY,
    $directory . '/vendor/dmorenof/sdk-runtime/tests/parameter-content-smoke.php', $directory . '/vendor/autoload.php']));
passthru($contentCommand, $contentStatus);
if ($contentStatus !== 0) { throw new RuntimeException('Installed runtime parameter content HTTP smoke failed.'); }
echo "PUBLISHED_PARAMETER_CONTENT_RUNTIME_OK", PHP_EOL;

$delimitedCommand = implode(' ', array_map('escapeshellarg', [PHP_BINARY,
    $directory . '/vendor/dmorenof/sdk-runtime/tests/delimited-parameters-smoke.php', $directory . '/vendor/autoload.php']));
passthru($delimitedCommand, $delimitedStatus);
if ($delimitedStatus !== 0) { throw new RuntimeException('Installed runtime delimited parameter HTTP smoke failed.'); }
echo "PUBLISHED_DELIMITED_PARAMETER_RUNTIME_OK", PHP_EOL;

$selectionCommand = implode(' ', array_map('escapeshellarg', [PHP_BINARY,
    $directory . '/vendor/dmorenof/sdk-runtime/tests/response-selection-smoke.php', $directory . '/vendor/autoload.php']));
passthru($selectionCommand, $selectionStatus);
if ($selectionStatus !== 0) { throw new RuntimeException('Installed runtime response selection HTTP smoke failed.'); }
echo "PUBLISHED_RESPONSE_SELECTION_RUNTIME_OK", PHP_EOL;

$formBlockCommand = implode(' ', array_map('escapeshellarg', [PHP_BINARY,
    $directory . '/vendor/dmorenof/sdk-runtime/tests/form-block-smoke.php', $directory . '/vendor/autoload.php']));
passthru($formBlockCommand, $formBlockStatus);
if ($formBlockStatus !== 0) { throw new RuntimeException('Installed runtime form block HTTP smoke failed.'); }
echo "PUBLISHED_FORM_BLOCK_RUNTIME_OK", PHP_EOL;

$schemaCommand = implode(' ', array_map('escapeshellarg', [PHP_BINARY,
    $directory . '/vendor/dmorenof/sdk-runtime/tests/schema-rules-smoke.php', $directory . '/vendor/autoload.php']));
passthru($schemaCommand, $schemaStatus);
if ($schemaStatus !== 0) { throw new RuntimeException('Installed runtime schema rules HTTP smoke failed.'); }
echo "PUBLISHED_SCHEMA_RULES_RUNTIME_OK", PHP_EOL;

$nativeCommand = implode(' ', array_map('escapeshellarg', [PHP_BINARY,
    $directory . '/vendor/dmorenof/sdk-runtime/tests/native-schemas-smoke.php', $directory . '/vendor/autoload.php']));
passthru($nativeCommand, $nativeStatus);
if ($nativeStatus !== 0) { throw new RuntimeException('Installed runtime native schemas HTTP smoke failed.'); }
echo "PUBLISHED_NATIVE_SCHEMAS_RUNTIME_OK", PHP_EOL;

$bodyCommand = implode(' ', array_map('escapeshellarg', [PHP_BINARY,
    $directory . '/vendor/dmorenof/sdk-runtime/tests/request-bodies-smoke.php', $directory . '/vendor/autoload.php']));
passthru($bodyCommand, $bodyStatus);
if ($bodyStatus !== 0) { throw new RuntimeException('Installed runtime request bodies HTTP smoke failed.'); }
echo "PUBLISHED_REQUEST_BODIES_RUNTIME_OK", PHP_EOL;

$rawCommand = implode(' ', array_map('escapeshellarg', [PHP_BINARY, '-d', 'memory_limit=16M',
    $directory . '/vendor/dmorenof/sdk-runtime/tests/raw-media-smoke.php', $directory . '/vendor/autoload.php']));
passthru($rawCommand, $rawStatus);
if ($rawStatus !== 0) { throw new RuntimeException('Installed runtime raw media HTTP smoke failed.'); }
echo "PUBLISHED_RAW_MEDIA_RUNTIME_OK", PHP_EOL;

$errorCommand = implode(' ', array_map('escapeshellarg', [PHP_BINARY,
    $directory . '/vendor/dmorenof/sdk-runtime/tests/error-payloads-smoke.php', $directory . '/vendor/autoload.php']));
passthru($errorCommand, $errorStatus);
if ($errorStatus !== 0) { throw new RuntimeException('Installed runtime error payloads HTTP smoke failed.'); }
echo "PUBLISHED_ERROR_PAYLOADS_RUNTIME_OK", PHP_EOL;
