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
