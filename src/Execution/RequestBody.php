<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Execution;

use Dmorenof\SdkRuntime\Constraints;
use Dmorenof\SdkRuntime\DTO;
use Dmorenof\SdkRuntime\FormSerializer;
use Dmorenof\SdkRuntime\Hydrator;
use Dmorenof\SdkRuntime\MediaType;
use Dmorenof\SdkRuntime\MultipartSerializer;
use Dmorenof\SdkRuntime\Operation;
use Dmorenof\SdkRuntime\Serializer;

/**
 * The request body: how the operation carries it, whether it matches its contract, and its wire bytes.
 *
 * @internal Implementation detail: not covered by the backward-compatibility promise (see docs/PUBLIC_API.md).
 */
final class RequestBody
{
    /**
     * Checks a body against its contract and serializes it.
     *
     * @return array{string|\Psr\Http\Message\StreamInterface|null, ?string} The bytes and the Content-Type header, or nulls when there is no body.
     */
    public static function prepare(Operation $spec, mixed $body, bool $present): array
    {
        $kind = OperationPlan::of($spec)->bodyKind ??= self::bodyKind($spec);
        $body = self::checkedBody($spec, $kind, $body, $present);
        return $present ? self::serializeBody($spec, $kind, $body) : [null, null];
    }

    /**
     * How the request body is carried, decided from the declared media type and body encoding.
     *
     * @return 'json'|'text'|'binary'|'form'|'multipart'
     */
    private static function bodyKind(Operation $spec): string
    {
        $media = $spec->requestMediaType;
        $json = MediaType::isJson($media);
        $baseMedia = strtolower(trim(explode(';', $media, 2)[0]));
        $rawMedia = !$json && !str_contains($baseMedia, '*') && !in_array($baseMedia, ['application/x-www-form-urlencoded', 'multipart/form-data'], true)
            && preg_match("@^[!#$%&'*+.^_`|~a-z0-9-]+/[!#$%&'*+.^_`|~a-z0-9-]+(?:\\s*;[^\\r\\n]*)?$@iD", $media) === 1;
        $encoding = $spec->requestBody['encoding'] ?? 'json';
        $kind = match (true) {
            $json => 'json',
            $encoding === 'text' && $rawMedia => 'text',
            $encoding === 'binary' && $rawMedia => 'binary',
            $media === 'application/x-www-form-urlencoded' => 'form',
            $media === 'multipart/form-data' => 'multipart',
            default => null,
        };
        if ($kind === null
            || ($media !== 'application/x-www-form-urlencoded' && $spec->bodySerialization !== [])
            || ($media !== 'multipart/form-data' && $spec->bodyParts !== [])) {
            throw new \InvalidArgumentException('Unsupported request body media type or serialization metadata.');
        }
        return $kind;
    }

    /** Validates the body against its declared contract and hydrates it into its declared type. */
    private static function checkedBody(Operation $spec, string $kind, mixed $body, bool $bodyPresent): mixed
    {
        if ($spec->requestBody === null) {
            if ($bodyPresent && !$body instanceof DTO) {
                throw new \UnexpectedValueException('Legacy operation body must be a DTO.');
            }
            return $body;
        }
        $encoding = $spec->requestBody['encoding'] ?? 'json';
        if (!in_array($kind, ['json', 'text', 'binary'], true) || ($kind === 'json' && $encoding !== 'json')) {
            throw new \InvalidArgumentException('Request body encoding does not match its media type.');
        }
        if ($kind === 'binary' && ($spec->requestBody['rules'] ?? []) !== []) {
            throw new \InvalidArgumentException('Binary body constraints are unsupported.');
        }
        if (!$bodyPresent) {
            if ($spec->requestBody['required']) {
                throw new \UnexpectedValueException($spec->label . ' requires a request body.');
            }
            return $body;
        }
        $bodyRules = $spec->requestBody['rules'] ?? [];
        if ($body !== null || array_key_exists('schema', $bodyRules) || array_key_exists('const', $bodyRules) || array_key_exists('enum', $bodyRules) || array_key_exists('jsonSchema', $bodyRules)) {
            Constraints::validate($body, $bodyRules, $spec->label . ' body', 'request');
        }
        return Hydrator::hydrate($body, $spec->requestBody['type'], $spec->requestBody['nullable'], $spec->label . ' body');
    }

    /**
     * Wire bytes of a present body and the Content-Type header that goes with them.
     *
     * @return array{string|\Psr\Http\Message\StreamInterface, string}
     */
    private static function serializeBody(Operation $spec, string $kind, mixed $body): array
    {
        $contentType = $spec->requestMediaType;
        if ($kind === 'text' || $kind === 'binary') {
            if (!is_string($body) && !$body instanceof \Psr\Http\Message\StreamInterface) { throw new \UnexpectedValueException('Raw body must contain bytes.'); }
            return [$body, $contentType];
        }
        if ($kind === 'json') {
            DTO::validateNestedValue($body, 'request');
            return [json_encode(Serializer::value($body, 'request'), JSON_THROW_ON_ERROR), $contentType];
        }
        if (!$body instanceof DTO) { throw new \UnexpectedValueException('Form body must be a DTO.'); }
        if ($kind === 'multipart') {
            $multipart = MultipartSerializer::encode($body, $spec->bodyParts);
            return [$multipart, $contentType . '; boundary=' . $multipart->getBoundary()];
        }
        return [FormSerializer::encode($body, $spec->bodySerialization), $contentType];
    }
}
