<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Execution;

use Dmorenof\SdkRuntime\Operation;

/**
 * What the client works out about an operation once and reuses on every later call: the Accept header, whether
 * its parameter metadata was already checked, and the answers for each status and content type. An Operation is
 * built once per class and route (ApiClient::operation()), so these results stay valid for its lifetime and go
 * away with it.
 *
 * @internal Implementation detail: not covered by the backward-compatibility promise (see docs/PUBLIC_API.md).
 *
 * @phpstan-import-type Representation from Operation
 */
final class OperationPlan
{
    /** @var \WeakMap<Operation, self>|null */
    private static ?\WeakMap $plans = null;

    public ?string $accept = null;

    /** How the request body is carried, decided from the declared media type; worked out on the first call. */
    public ?string $bodyKind = null;

    /** The metadata of the parameters (content, serialization) is checked on the first call only. */
    public bool $parametersChecked = false;

    /** @var array<int, array{bool, bool}> Status => [answer may carry a body, is a success]. */
    public array $statuses = [];

    /** @var array<string, array{0: Representation|null, 1: array{type:?string,schemaNullable:bool,rules?:array<string,mixed>,mediaType?:string,encoding?:string}}> "status|content type" => [declared entry, entry used]. */
    public array $entries = [];

    private function __construct() {}

    public static function of(Operation $spec): self
    {
        $plans = self::$plans;
        if ($plans === null) {
            /** @var \WeakMap<Operation, self> $plans */
            $plans = new \WeakMap();
            self::$plans = $plans;
        }
        $plan = $plans[$spec] ?? null;
        if ($plan === null) {
            $plan = new self();
            $plans[$spec] = $plan;
        }
        return $plan;
    }
}
