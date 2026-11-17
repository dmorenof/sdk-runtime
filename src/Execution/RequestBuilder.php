<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Execution;

use Dmorenof\SdkRuntime\Operation;
use Dmorenof\SdkRuntime\RequestInput;

/**
 * Builds the request of one call from its operation and its values: parameters first, then the body.
 *
 * @internal Implementation detail: not covered by the backward-compatibility promise (see docs/PUBLIC_API.md).
 */
final class RequestBuilder
{
    public static function build(Operation $spec, RequestInput $input): PreparedRequest
    {
        if (strtoupper($spec->method) === 'TRACE' && $input->bodyPresent) {
            throw new \InvalidArgumentException('HTTP TRACE must not contain a request body.');
        }
        $parameters = RequestParameters::prepare($spec, $input);
        [$bytes, $contentType] = RequestBody::prepare($spec, $input->body, $input->bodyPresent);

        $headers = ['Accept' => ResponseDecoder::accept($spec)] + $parameters['headers'];
        if ($contentType !== null) {
            $headers['Content-Type'] = $contentType;
        }
        return new PreparedRequest($parameters['path'], $parameters['query'], $headers, $bytes);
    }
}
