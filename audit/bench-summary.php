<?php
declare(strict_types=1);

/** Median of each metric over the JSON lines in the files given, as a Markdown table (ours against OpenAPI Generator). */
$rows = [];
foreach (array_slice($argv, 1) as $file) {
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $r = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        foreach ($r as $metric => $value) {
            if (is_numeric($value)) {
                $rows[$r['scenario']][$r['tool']][$metric][] = $value;
            }
        }
    }
}
$median = static function (array $v): float {
    sort($v);
    $n = count($v);
    return $n % 2 === 1 ? $v[intdiv($n, 2)] : ($v[$n / 2 - 1] + $v[$n / 2]) / 2;
};
$labels = [
    'us_per_call' => 'µs per call (wall)', 'cpu_us_per_call' => 'CPU µs per call (user+sys)', 'init_ms' => 'initialization (ms)',
    'first_call_ms' => 'first call, cold (ms)', 'mem_after_init_kib' => 'PHP memory after init (KiB)',
    'php_peak_kib' => 'PHP peak memory (KiB)', 'rss_peak_kib' => 'process peak RSS (KiB)', 'files_loaded' => 'PHP files loaded',
    'mem_growth_kib' => 'memory growth over the run (KiB)',
];
foreach ($rows as $scenario => $tools) {
    echo "\n### Scenario `$scenario`\n\n| | ours | OpenAPI Generator | ours / OpenAPI Generator |\n|---|---:|---:|---:|\n";
    foreach ($labels as $metric => $label) {
        $ours = $median($tools['ours'][$metric] ?? [0]);
        $oag = $median($tools['oag'][$metric] ?? [0]);
        printf("| %s | %s | %s | %s |\n", $label, round($ours, 2), round($oag, 2), $oag > 0 ? round($ours / $oag, 2) . '×' : '-');
    }
}
