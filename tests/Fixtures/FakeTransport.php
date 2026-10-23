<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests\Fixtures;

use Dmorenof\SdkRuntime\Transport\HttpResponse;
use Dmorenof\SdkRuntime\Transport\TransportInterface;

final class FakeTransport implements TransportInterface
{
    /** @var list<array{method:string,url:string,headers:array<string,string>,body:?string}> */
    public array $sent = [];

    public function __construct(private readonly HttpResponse $response) {}

    public function send(string $method, string $url, array $headers, ?string $body): HttpResponse
    {
        $this->sent[] = compact('method', 'url', 'headers', 'body');
        return $this->response;
    }
}
