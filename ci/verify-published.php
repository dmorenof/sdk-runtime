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
        if ($url !== 'https://example.test/search?ids=1&ids=2&codes=a%2Cb,x%20y&spaces=3%204&pipes=a%7Cb&tabs=5%096') {
            throw new RuntimeException('Installed runtime query serialization failed.');
        }
        return new \Dmorenof\SdkRuntime\Transport\HttpResponse(204, '');
    }
};
$client = new class('https://example.test', transport: $transport) extends \Dmorenof\SdkRuntime\ApiClient {
    public function probe(): void
    {
        $this->executeOperation(['method' => 'GET', 'path' => '/search', 'success' => [204],
            'querySerialization' => ['ids' => 'multi', 'codes' => 'csv', 'spaces' => 'ssv', 'pipes' => 'pipes', 'tabs' => 'tsv']],
            queryParameters: ['ids' => [1, 2], 'codes' => ['a,b', 'x y'], 'spaces' => [3, 4], 'pipes' => ['a', 'b'], 'tabs' => [5, 6]]);
    }
};
$client->probe();
echo "PUBLISHED_QUERY_SERIALIZATION_OK", PHP_EOL;
