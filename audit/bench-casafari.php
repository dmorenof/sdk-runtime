<?php
declare(strict_types=1);

/**
 * One process, one SDK, one scenario against the real Casafari contract; prints one JSON line.
 * Usage: AUTOLOAD=/path/vendor/autoload.php php bench-casafari.php <ours|oag> <types|location> <calls>
 * The HTTP answer is the same in both: a Guzzle mock handler, no network.
 */
require getenv('AUTOLOAD') ?: throw new RuntimeException('Set AUTOLOAD.');

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;

[, $tool, $scenario, $calls] = $argv + [null, 'ours', 'types', '5000'];
$calls = (int) $calls;

$types = [['types' => [], 'types_with_countries' => []]];
for ($i = 0; $i < 20; $i++) {
    $types[0]['types'][] = ['group' => "group-$i", 'names' => ["a$i", "b$i", "c$i", "d$i", "e$i"]];
}
for ($i = 0; $i < 40; $i++) {
    $types[0]['types_with_countries'][] = ['name' => "type-$i", 'countries' => ['ES', 'PT', 'FR']];
}
$location = [
    'administrative_level' => 'city', 'location_id' => 7, 'name' => 'Barcelona', 'parent_id' => 3,
    'locations_structure' => array_map(static fn (int $level): array => [
        'administrative_level' => "level-$level", 'level' => $level, 'location_id' => 100 + $level, 'name' => "Place $level",
        'zip_codes' => ['08001', '08002', '08003'],
    ], [1, 2, 3, 4]),
];
$body = json_encode($scenario === 'types' ? $types : $location, JSON_THROW_ON_ERROR);

$handler = static fn ($request, $options) => Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], $body));
$http = new Client(['handler' => HandlerStack::create($handler)]);

$startFiles = count(get_included_files());
$t0 = hrtime(true);
if ($tool === 'oag') {
    $config = (new OpenAPI\Client\Configuration())->setAccessToken('token')->setHost('https://api.casafari.com');
    $api = new OpenAPI\Client\Api\ApiReferencesApi($http, $config);
    $call = $scenario === 'types'
        ? static fn () => $api->apiGetV1ReferencesTypes()
        : static fn () => $api->apiGetV1ReferencesLocationsByCoordinates(41.3874, 2.1686);
} else {
    $configuration = new CasafariSDK\Configuration();
    $configuration->httpClient = $http;
    $configuration->securityCredentials = new Dmorenof\SdkRuntime\Security\SecurityCredentials(['HTTPBearer' => 'token']);
    $client = CasafariSDK\Client::connect($configuration);
    if ($scenario === 'types') {
        $api = $client->resource(CasafariSDK\Resources\Api\V1\References\Types::class);
        $call = static fn () => $api->get();
    } else {
        $api = $client->resource(CasafariSDK\Resources\Api\V1\References\Locations\ByCoordinates::class);
        $params = new CasafariSDK\GetParams\Api\V1\References\Locations\ByCoordinates();
        $params->latitude = 41.3874;
        $params->longitude = 2.1686;
        $call = static fn () => $api->get($params);
    }
}
$initMs = (hrtime(true) - $t0) / 1e6;
$memoryAfterInit = memory_get_usage();

$t1 = hrtime(true);
$first = $call();
$firstMs = (hrtime(true) - $t1) / 1e6;
if ($first === null) {
    throw new RuntimeException('The call returned nothing.');
}
for ($i = 0; $i < 200; $i++) {
    $call();
}
gc_collect_cycles();
$memoryBefore = memory_get_usage();
$cpuBefore = getrusage();
$t2 = hrtime(true);
for ($i = 0; $i < $calls; $i++) {
    $call();
}
$wallUs = (hrtime(true) - $t2) / 1e3 / $calls;
$cpuAfter = getrusage();
$cpuUs = (($cpuAfter['ru_utime.tv_sec'] - $cpuBefore['ru_utime.tv_sec']) * 1e6 + ($cpuAfter['ru_utime.tv_usec'] - $cpuBefore['ru_utime.tv_usec'])
    + ($cpuAfter['ru_stime.tv_sec'] - $cpuBefore['ru_stime.tv_sec']) * 1e6 + ($cpuAfter['ru_stime.tv_usec'] - $cpuBefore['ru_stime.tv_usec'])) / $calls;
preg_match('/VmHWM:\s+(\d+) kB/', (string) file_get_contents('/proc/self/status'), $rss);

echo json_encode([
    'tool' => $tool, 'scenario' => $scenario,
    'init_ms' => round($initMs, 2), 'first_call_ms' => round($firstMs, 2),
    'us_per_call' => round($wallUs, 1), 'cpu_us_per_call' => round($cpuUs, 1),
    'mem_after_init_kib' => (int) ($memoryAfterInit / 1024), 'mem_growth_kib' => (int) ((memory_get_usage() - $memoryBefore) / 1024),
    'php_peak_kib' => (int) (memory_get_peak_usage(false) / 1024), 'php_peak_alloc_kib' => (int) (memory_get_peak_usage(true) / 1024),
    'rss_peak_kib' => (int) ($rss[1] ?? 0), 'files_loaded' => count(get_included_files()) - $startFiles,
], JSON_THROW_ON_ERROR), "\n";
