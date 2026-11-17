<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime;

/**
 * The values of one call, already split by where they travel: path, query string, headers and body.
 * Generated code rarely builds one: it passes parameter objects to the execute methods, which build it.
 *
 * Without `$bodyPresent` a body counts as present when it is not null; pass it to tell an omitted body from
 * an explicit JSON `null`.
 */
final readonly class RequestInput
{
    public bool $bodyPresent;

    /**
     * @param array<string, scalar|list<scalar>|DTO|Map|TypedArray|\stdClass|null> $path
     * @param array<string, scalar|list<scalar>|DTO|Map|TypedArray|\stdClass|null> $query
     * @param array<string, scalar|list<scalar>|DTO|Map|TypedArray|\stdClass|null> $headers
     */
    public function __construct(
        public array $path = [],
        public array $query = [],
        public array $headers = [],
        public mixed $body = null,
        ?bool $bodyPresent = null,
    ) {
        $this->bodyPresent = $bodyPresent ?? $body !== null;
    }
}
