<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Execution;

use Dmorenof\SdkRuntime\ApiException;
use Dmorenof\SdkRuntime\DTO;
use Dmorenof\SdkRuntime\Hydrator;
use Dmorenof\SdkRuntime\InvalidResponseException;
use Dmorenof\SdkRuntime\JsonHydrator;
use Dmorenof\SdkRuntime\MediaType;
use Dmorenof\SdkRuntime\Operation;
use Dmorenof\SdkRuntime\Transport\HttpResponse;

/**
 * Everything the client knows about answers: which media types it asks for, how a buffered response becomes
 * a value, and how a failed one becomes an ApiException.
 *
 * @internal Implementation detail: not covered by the backward-compatibility promise (see docs/PUBLIC_API.md).
 *
 * @phpstan-import-type Representation from Operation
 */
final class ResponseDecoder
{
    /** The Accept header: every declared success and error representation, most preferred first. */
    public static function accept(Operation $spec): string
    {
        return OperationPlan::of($spec)->accept ??= self::acceptFor($spec);
    }

    private static function acceptFor(Operation $spec): string
    {
        $accepted = [];
        if ($spec->responseType !== null || Operation::isStreamingEncoding($spec->responseEncoding)) {
            $legacyBinary = $spec->responseType !== null && ltrim($spec->responseType, '\\') === \Psr\Http\Message\ResponseInterface::class;
            $accepted[] = $legacyBinary && !$spec->declares('responseMediaType') ? 'application/octet-stream' : $spec->responseMediaType;
        }
        foreach (array_merge($spec->responseTypes, $spec->errorResponses) as $entry) {
            foreach (Operation::representations($entry) as $representation) {
                if ($representation['type'] === null && !Operation::isStreamingEncoding($representation['encoding'] ?? null)) {
                    continue;
                }
                $accepted[] = $representation['mediaType'] ?? MediaType::defaultFor($representation['type'], $representation['encoding'] ?? null);
            }
        }
        $accepted = array_values(array_unique($accepted));
        // usort is stable, so equally preferred types keep the order of previously emitted SDKs.
        usort($accepted, static fn (string $left, string $right): int => MediaType::preference($left) <=> MediaType::preference($right));
        return implode(', ', $accepted === [] ? ['application/json'] : $accepted);
    }

    /**
     * The success description for a status: its own entry, or the operation's single-response keys.
     *
     * @return array{type:?string,schemaNullable:bool,rules?:array<string,mixed>,mediaType?:string,encoding?:string}
     */
    public static function entry(Operation $spec, int $status, ?string $contentType): array
    {
        return $spec->responseTypeFor($status, $contentType) ?? [
            'type' => $spec->responseType,
            'schemaNullable' => $spec->schemaNullable,
            'mediaType' => $spec->responseMediaType,
            'encoding' => $spec->declares('responseEncoding') ? $spec->responseEncoding : ($spec->responseMediaType === 'text/plain' ? 'text' : 'json'),
        ];
    }

    /**
     * Turns a buffered response into the operation's value: null for bodyless answers, the decoded and
     * validated payload for a success, or an ApiException for any other status.
     */
    public static function decode(Operation $spec, HttpResponse $response): mixed
    {
        $plan = OperationPlan::of($spec);
        [$allowsBody, $succeeded] = $plan->statuses[$response->status]
            ??= [Operation::allowsResponseBody($spec->method, $response->status), $spec->succeeded($response->status)];
        if (!$allowsBody) {
            if (!$succeeded) { throw new ApiException($response, $spec->label); }
            return null;
        }
        $contentType = $response->header('Content-Type');
        if (!$succeeded) {
            throw self::failure($spec, $response, $contentType);
        }
        [$declared, $entry] = $plan->entries[$response->status . '|' . $contentType]
            ??= [$spec->responseTypeFor($response->status, $contentType), self::entry($spec, $response->status, $contentType)];
        if ($entry['type'] === null || ($declared === null && $spec->noContentAllowed && $response->body === '')) {
            return null;
        }
        $encoding = $entry['encoding'] ?? (($entry['mediaType'] ?? '') === 'text/plain' ? 'text' : 'json');
        if (!in_array($encoding, ['json', 'text', 'binary'], true)) { throw new \InvalidArgumentException('Unsupported response encoding.'); }
        try {
            $result = $encoding !== 'json'
                ? Hydrator::hydrate($response->body, $entry['type'], false, $spec->label . ' response')
                : JsonHydrator::decode($response->body, $entry['type'], $entry['schemaNullable']);
        } catch (\JsonException|\UnexpectedValueException|\InvalidArgumentException|\ValueError|\TypeError $cause) {
            throw new InvalidResponseException($response, $spec->label, $cause);
        }
        return $result;
    }

    /** An ApiException for a non-success response, carrying the decoded error payload when the API documents one. */
    public static function failure(Operation $spec, HttpResponse $response, ?string $contentType = null): ApiException
    {
        $declared = $spec->errorResponseFor($response->status, $contentType);
        if ($declared !== null) {
            return self::declaredFailure($spec->label, $response, $declared);
        }
        $errorType = $spec->errorTypeFor($response->status);
        return $errorType === null ? new ApiException($response, $spec->label) : self::modelFailure($spec->label, $response, $errorType);
    }

    /**
     * @param Representation $declared
     */
    private static function declaredFailure(string $label, HttpResponse $response, array $declared): ApiException
    {
        $payload = null;
        $previous = null;
        if ($declared['type'] !== null) {
            try {
                $media = $declared['mediaType'] ?? 'application/json';
                $encoding = $declared['encoding'] ?? (in_array($media, ['text/plain', 'application/octet-stream'], true) ? 'text' : 'json');
                $payload = match ($encoding) {
                    'text', 'binary' => Hydrator::hydrate($response->body, $declared['type'], false, $label . ' error'),
                    'json' => JsonHydrator::decode($response->body, $declared['type'], $declared['schemaNullable']),
                };
            } catch (\Throwable $exception) {
                $previous = $exception;
                $payload = null;
            }
        }
        return new ApiException($response, $label, $payload, $previous);
    }

    /** The error model of a status, decoded from a JSON object; a body that does not fit still yields the exception. */
    private static function modelFailure(string $label, HttpResponse $response, string $errorType): ApiException
    {
        $error = null;
        $previous = null;
        try {
            if (!is_a($errorType, DTO::class, true)) {
                throw new \LogicException("Error type $errorType must extend Core\\DTO.");
            }
            $json = json_decode($response->body, false, 512, JSON_THROW_ON_ERROR);
            if (!is_object($json)) {
                throw new \UnexpectedValueException("Expected JSON object for error type $errorType.");
            }
            $error = new $errorType($json);
        } catch (\Throwable $exception) {
            // Always expose HTTP response information to the caller.
            $previous = $exception;
        }
        return new ApiException($response, $label, $error, $previous);
    }
}
