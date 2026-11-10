<?php
declare(strict_types=1);

// Prints the public API snapshot. Usage: php ci/api-snapshot.php > tests/Fixtures/public-api.txt
// An alternative autoloader path may be passed as the first argument.
require $argv[1] ?? __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../tests/Support/ApiSnapshot.php';

echo \Dmorenof\SdkRuntime\Tests\Support\ApiSnapshot::render(__DIR__ . '/../src');
