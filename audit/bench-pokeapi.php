<?php
declare(strict_types=1);

/**
 * One process, one SDK, one scenario against PokeAPI's published OpenAPI 3.1; prints one JSON line.
 * Usage: AUTOLOAD=/path/vendor/autoload.php ANSWERS=/dir php bench-pokeapi.php <ours|oag> <pokemon|list> <calls>
 * The answers are real ones, fetched once from pokeapi.co before the run (ANSWERS/pokemon.json, ANSWERS/list.json),
 * and the same for both tools: a Guzzle mock handler, no network during the measurement.
 */
require getenv('AUTOLOAD') ?: throw new RuntimeException('Set AUTOLOAD.');

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;

[, $tool, $scenario, $calls] = $argv + [null, 'ours', 'pokemon', '2000'];
$calls = (int) $calls;
$answers = rtrim(getenv('ANSWERS') ?: throw new RuntimeException('Set ANSWERS.'), '/');
$body = file_get_contents("$answers/$scenario.json") ?: throw new RuntimeException("Missing $scenario.json");

$handler = static fn ($request, $options) => Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], $body));
$http = new Client(['handler' => HandlerStack::create($handler)]);

$startFiles = count(get_included_files());
$t0 = hrtime(true);
if ($tool === 'oag') {
    $api = new OpenAPI\Client\Api\PokemonApi($http, new OpenAPI\Client\Configuration());
    $call = $scenario === 'pokemon' ? static fn () => $api->pokemonRetrieve('25') : static fn () => $api->pokemonList(20);
} else {
    $configuration = new PokeApiSDK\Configuration();
    $configuration->httpClient = $http;
    $client = PokeApiSDK\Client::connect($configuration);
    $api = $client->resource(PokeApiSDK\Resources\Api\V2\Pokemon::class);
    if ($scenario === 'pokemon') {
        $call = static fn () => $api->getById('25');
    } else {
        $params = new PokeApiSDK\GetParams\Api\V2\Pokemon();
        $params->limit = 20;
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
// The answer must come back with what was sent, or the speed means nothing.
$name = $tool === 'oag' ? ($scenario === 'pokemon' ? $first->getName() : count($first->getResults())) : ($scenario === 'pokemon' ? $first->name : count($first->results));
$expected = $scenario === 'pokemon' ? json_decode($body, true)['name'] : count(json_decode($body, true)['results']);
if ($name !== $expected) {
    throw new RuntimeException("The answer does not match what was sent: " . var_export($name, true));
}
for ($i = 0; $i < 50; $i++) {
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
