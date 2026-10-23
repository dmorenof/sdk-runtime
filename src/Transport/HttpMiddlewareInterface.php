<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Transport;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/** An optional, SDK-level interception seam; Guzzle HandlerStack works too. */
interface HttpMiddlewareInterface
{
    public function beforeRequest(RequestInterface $request): RequestInterface;
    public function afterResponse(RequestInterface $request, ResponseInterface $response): ResponseInterface;
}