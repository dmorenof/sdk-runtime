<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Execution;

use Dmorenof\SdkRuntime\Configuration;
use Dmorenof\SdkRuntime\Operation;
use Dmorenof\SdkRuntime\ServerUrl;

/**
 * Where each operation goes: the base URL, the server an operation declares (fixed, or chosen among several and
 * filled in with variables), and the environment overrides.
 *
 * @internal Implementation detail: not covered by the backward-compatibility promise (see docs/PUBLIC_API.md).
 */
final readonly class ServerSelection
{
    private string $baseUrl;

    /** @var array<string, string> */
    private array $overrides;

    private int|string|null $selection;

    /** @var array<string, string> */
    private array $variables;

    public function __construct(Configuration $configuration)
    {
        if ($configuration->baseUrl === '') {
            throw new \InvalidArgumentException('Configuration::baseUrl must not be empty.');
        }
        $overrides = [];
        foreach ($configuration->serverOverrides as $declared => $replacement) {
            $overrides[ServerUrl::normalize($declared)] = ServerUrl::normalize($replacement);
        }
        foreach ($configuration->serverVariables as $name => $value) {
            if (!is_string($name) || $name === '' || !is_string($value)) {
                throw new \InvalidArgumentException('Server variables map nonempty names to string values.');
            }
        }
        $this->baseUrl = $configuration->baseUrl;
        $this->overrides = $overrides;
        $this->selection = $configuration->server;
        $this->variables = $configuration->serverVariables;
    }

    /** The base URL an operation sends to. */
    public function urlFor(Operation $spec): string
    {
        if ($spec->servers !== []) {
            $declared = ServerUrl::resolve(
                ServerUrl::expand(self::select($spec->servers, $this->selection, $spec->label), $this->variables),
                $this->baseUrl,
            );
        } elseif ($spec->serverUrl !== null) {
            $declared = ServerUrl::resolve($spec->serverUrl, $this->baseUrl);
        } else {
            return $this->baseUrl;
        }
        return $this->overrides[$declared] ?? $declared;
    }

    /**
     * The base URL of one of an SDK's root servers, with its variables filled in (declared defaults, or the
     * given values checked against each variable's enum).
     *
     * @param list<array{url:string,name?:string,variables?:array<string,array{default:string,enum?:list<string>}>}> $servers
     * @param array<string, string> $variables
     */
    public static function rootUrl(array $servers, int|string $server, array $variables, string $where): string
    {
        $declared = self::select($servers, $server, $where);
        $unknown = array_diff(array_keys($variables), array_keys($declared['variables'] ?? []));
        if ($unknown !== []) {
            throw new \InvalidArgumentException('Unknown server variable(s): ' . implode(', ', $unknown) . '.');
        }
        $url = ServerUrl::expand($declared, $variables);
        if (!ServerUrl::isAbsolute($url)) {
            throw new \InvalidArgumentException('A root server must be an absolute URL; relative servers resolve against a base URL.');
        }
        return ServerUrl::normalize($url);
    }

    /**
     * @param list<array{url:string,name?:string,variables?:array<string,array{default:string,enum?:list<string>}>}> $servers
     * @return array{url:string,name?:string,variables?:array<string,array{default:string,enum?:list<string>}>}
     */
    private static function select(array $servers, int|string|null $selection, string $where): array
    {
        $selection ??= 0;
        if (is_int($selection)) {
            return $servers[$selection] ?? throw new \InvalidArgumentException("$where declares no server #$selection.");
        }
        foreach ($servers as $server) {
            if (($server['name'] ?? null) === $selection) {
                return $server;
            }
        }
        throw new \InvalidArgumentException("$where declares no server named '$selection'.");
    }
}
