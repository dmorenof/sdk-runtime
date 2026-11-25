<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests\Support;

/**
 * A real HTTP server on 127.0.0.1, in a child process, that answers with a script and records what it received
 * (see scripted-server.php). Tests that need the bytes on a real socket (retries, redirects, timeouts, compression)
 * use it with the default Guzzle client; nothing leaves the machine.
 */
final class ScriptedServer
{
    /** @var resource */
    private $process;

    /** @var array<int, resource> */
    private array $pipes;

    private function __construct(public readonly int $port, private readonly string $scriptFile, private readonly string $logFile)
    {
    }

    /** @param list<array<string, mixed>> $script */
    public static function start(array $script = []): self
    {
        $scriptFile = tempnam(sys_get_temp_dir(), 'script');
        $logFile = tempnam(sys_get_temp_dir(), 'log');
        if ($scriptFile === false || $logFile === false) {
            throw new \RuntimeException('Cannot create temporary files.');
        }
        file_put_contents($logFile, '');
        $pipes = [];
        $process = proc_open([PHP_BINARY, __DIR__ . '/scripted-server.php', $scriptFile, $logFile], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start the scripted server.');
        }
        stream_set_timeout($pipes[1], 15);
        $port = trim((string) fgets($pipes[1]));
        if (!ctype_digit($port)) {
            throw new \RuntimeException('The scripted server printed no port.');
        }
        $instance = new self((int) $port, $scriptFile, $logFile);
        $instance->process = $process;
        $instance->pipes = $pipes;
        $instance->script($script);
        return $instance;
    }

    /** @param list<array<string, mixed>> $script */
    public function script(array $script): void
    {
        file_put_contents($this->scriptFile, json_encode($script, JSON_THROW_ON_ERROR));
    }

    public function url(string $path = ''): string
    {
        return 'http://127.0.0.1:' . $this->port . $path;
    }

    /** @return list<array{method: string, target: string, headers: array<string, list<string>>, body: string}> */
    public function requests(): array
    {
        $lines = file($this->logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        return array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), $lines);
    }

    public function stop(): void
    {
        foreach ($this->pipes as $pipe) {
            fclose($pipe);
        }
        proc_terminate($this->process);
        proc_close($this->process);
        @unlink($this->scriptFile);
        @unlink($this->logFile);
    }
}
