<?php
declare(strict_types=1);

/**
 * One process, one SDK, one scenario against a real public API's published contract; prints one JSON line.
 * Usage: AUTOLOAD=/path/vendor/autoload.php ANSWERS=/dir php bench-api.php <api> <ours|oag> <scenario> <calls>
 * The answers are real ones, fetched once before the run (ANSWERS/<scenario>.json), and the same for both tools: a Guzzle
 * mock handler, no network during the measurement. The model that comes back must carry what was sent, or the speed means nothing.
 */
require getenv('AUTOLOAD') ?: throw new RuntimeException('Set AUTOLOAD.');

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;

[, $api, $tool, $scenario, $calls] = $argv + [null, 'pokeapi', 'ours', 'pokemon', '2000'];
$calls = (int) $calls;
$answers = rtrim(getenv('ANSWERS') ?: throw new RuntimeException('Set ANSWERS.'), '/');
$body = file_get_contents("$answers/$scenario.json") ?: throw new RuntimeException("Missing $scenario.json");
$sent = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

/** Sets the properties of a generated parameter model, turning a string into the enum the property declares. */
$fill = static function (object $dto, array $values): object {
    foreach ($values as $name => $value) {
        $type = (new ReflectionProperty($dto, $name))->getType();
        $class = $type instanceof ReflectionNamedType && !$type->isBuiltin() ? $type->getName() : null;
        $dto->{$name} = $class !== null && enum_exists($class) ? $class::from($value) : $value;
    }
    return $dto;
};

$handler = static fn ($request, $options) => Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], $body));
$http = new Client(['handler' => HandlerStack::create($handler)]);

$ours = static function (string $namespace) use ($http): object {
    $configuration = new ("$namespace\\Configuration")();
    $configuration->httpClient = $http;
    return ("$namespace\\Client")::connect($configuration);
};
$oag = static fn (string $class, ?object $config = null): object => new $class($http, $config ?? new OpenAPI\Client\Configuration());

$startFiles = count(get_included_files());
$t0 = hrtime(true);
switch ("$api/$scenario") {
    case 'pokeapi/pokemon':
    case 'pokeapi/list':
        $pokemon = $scenario === 'pokemon';
        if ($tool === 'oag') {
            $client = $oag(OpenAPI\Client\Api\PokemonApi::class);
            $call = $pokemon ? static fn () => $client->pokemonRetrieve('25') : static fn () => $client->pokemonList(20);
            $read = $pokemon ? static fn ($m) => $m->getName() : static fn ($m) => count($m->getResults());
        } else {
            $client = $ours('PokeApiSDK')->resource(PokeApiSDK\Resources\Api\V2\Pokemon::class);
            $params = new PokeApiSDK\GetParams\Api\V2\Pokemon();
            $params->limit = 20;
            $call = $pokemon ? static fn () => $client->getById('25') : static fn () => $client->get($params);
            $read = $pokemon ? static fn ($m) => $m->name : static fn ($m) => count($m->results);
        }
        $expected = $pokemon ? $sent['name'] : count($sent['results']);
        break;
    case 'metno/compact':
    case 'metno/complete':
        $compact = $scenario === 'compact';
        if ($tool === 'oag') {
            $client = $oag(OpenAPI\Client\Api\DataApi::class);
            $call = $compact ? static fn () => $client->compactGet(41.39, 2.17) : static fn () => $client->completeGet(41.39, 2.17);
            $read = static fn ($m) => count($m->getProperties()->getTimeseries());
        } else {
            $connection = $ours('MetNoSDK');
            $resource = $connection->resource($compact ? MetNoSDK\Resources\Compact::class : MetNoSDK\Resources\Complete::class);
            $params = $fill($compact ? new MetNoSDK\GetParams\Compact() : new MetNoSDK\GetParams\Complete(), ['lat' => 41.39, 'lon' => 2.17]);
            $call = static fn () => $resource->get($params);
            $read = static fn ($m) => count($m->properties->timeseries);
        }
        $expected = count($sent['properties']['timeseries']);
        break;
    case 'wikimedia/top':
    case 'wikimedia/article':
        $top = $scenario === 'top';
        if ($tool === 'oag') {
            $client = $oag(OpenAPI\Client\Api\PageviewsDataApi::class, (new OpenAPI\Client\Configuration())->setHost('https://wikimedia.org/api/rest_v1'));
            $call = $top
                ? static fn () => $client->metricsPageviewsTopProjectAccessYearMonthDayGet('en.wikipedia', 'all-access', '2024', '01', 'all-days')
                : static fn () => $client->metricsPageviewsPerArticleProjectAccessAgentArticleGranularityStartEndGet('en.wikipedia', 'all-access', 'user', 'Albert_Einstein', 'daily', '20240101', '20240131');
            $read = static fn ($m) => count($m->getItems());
        } else {
            $connection = $ours('WikimediaSDK');
            if ($top) {
                $resource = $connection->resource(WikimediaSDK\Resources\Metrics\Pageviews\Top\ByProject\ByAccess\ByYear\ByMonth::class);
                $ids = $fill(new WikimediaSDK\PathParams\Metrics\Pageviews\Top\ByProject\ByAccess\ByYear\ByMonth\ByDay(), ['project' => 'en.wikipedia', 'access' => 'all-access', 'year' => '2024', 'month' => '01', 'day' => 'all-days']);
                $call = static fn () => $resource->getByDay($ids);
            } else {
                $resource = $connection->resource(WikimediaSDK\Resources\Metrics\Pageviews\PerArticle\ByProject\ByAccess\ByAgent\ByArticle\ByGranularity\ByStart::class);
                $ids = $fill(new WikimediaSDK\PathParams\Metrics\Pageviews\PerArticle\ByProject\ByAccess\ByAgent\ByArticle\ByGranularity\ByStart\ByEnd(), ['project' => 'en.wikipedia', 'access' => 'all-access', 'agent' => 'user', 'article' => 'Albert_Einstein', 'granularity' => 'daily', 'start' => '20240101', 'end' => '20240131']);
                $call = static fn () => $resource->getByEnd($ids);
            }
            $read = static fn ($m) => count($m->items);
        }
        $expected = count($sent['items']);
        break;
    default:
        throw new RuntimeException("Unknown scenario $api/$scenario.");
}
$initMs = (hrtime(true) - $t0) / 1e6;
$memoryAfterInit = memory_get_usage();

$t1 = hrtime(true);
$first = $call();
$firstMs = (hrtime(true) - $t1) / 1e6;
if ($first === null) {
    throw new RuntimeException('The call returned nothing.');
}
$got = $read($first);
if ($got !== $expected) {
    throw new RuntimeException('The answer does not match what was sent: ' . var_export($got, true) . ' instead of ' . var_export($expected, true));
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
