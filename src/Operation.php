<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/**
 * One API operation as declared by a generated SDK: where it goes, how it is secured,
 * which statuses succeed and which types describe its response and errors.
 *
 * Generated code declares operations as arrays holding only what differs from DEFAULTS;
 * fromArray() turns that into this typed object and rejects keys it does not know.
 *
 * @phpstan-type OperationSpec array{
 *     method: string,
 *     path: string,
 *     label?: string|null,
 *     security?: list<array<string, list<string>>>,
 *     success?: list<int>,
 *     errors?: array<int|string, string>,
 *     responseType?: string|null,
 *     schemaNullable?: bool,
 *     noContentAllowed?: bool,
 *     validation?: array<string, array<string, mixed>>,
 * }
 */
final readonly class Operation
{
    /** Values an operation inherits when it does not declare them. */
    public const array DEFAULTS = [
        'security' => [],
        'success' => [200],
        'errors' => [],
        'responseType' => null,
        'schemaNullable' => false,
        'noContentAllowed' => false,
        'validation' => [],
    ];

    /** "METHOD path", unless the operation names itself. */
    public string $label;

    /**
     * @param list<array<string, list<string>>> $security Alternatives are OR; schemes within one entry are AND.
     * @param list<int> $success Statuses that are not errors.
     * @param array<int|string, string> $errors Status (or 'default') => error model class.
     * @param array<string, array<string, mixed>> $validation Parameter name => rules.
     */
    public function __construct(
        public string $method,
        public string $path,
        ?string $label = null,
        public array $security = self::DEFAULTS['security'],
        public array $success = self::DEFAULTS['success'],
        public array $errors = self::DEFAULTS['errors'],
        public ?string $responseType = self::DEFAULTS['responseType'],
        public bool $schemaNullable = self::DEFAULTS['schemaNullable'],
        public bool $noContentAllowed = self::DEFAULTS['noContentAllowed'],
        public array $validation = self::DEFAULTS['validation'],
    ) {
        $this->label = $label ?? $method . ' ' . $path;
    }

    /** @param OperationSpec $spec */
    public static function fromArray(array $spec): self
    {
        return new self(...$spec);
    }

    /** The error model for a failed status: its own entry, then the 'default' one. */
    public function errorTypeFor(int $status): ?string
    {
        return $this->errors[$status] ?? $this->errors['default'] ?? null;
    }

    public function succeeded(int $status): bool
    {
        return in_array($status, $this->success, true);
    }
}
