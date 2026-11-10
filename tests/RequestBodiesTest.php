<?php
declare(strict_types=1);
namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\DTO;
use Dmorenof\SdkRuntime\Tests\Fixtures\DirectionalUser;
use Dmorenof\SdkRuntime\Tests\Fixtures\FakeTransport;
use Dmorenof\SdkRuntime\Tests\Fixtures\Scores;
use Dmorenof\SdkRuntime\Tests\Fixtures\Tags;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use PHPUnit\Framework\TestCase;

final class RequestBodiesTest extends TestCase {
    private function client(FakeTransport $transport): ApiClient {
        return new class('https://example.test', transport: $transport) extends ApiClient {
            public function post(mixed $body, array $contract, bool $present = true, ?DTO $params = null): mixed {
                return $this->executeBody(['method' => 'POST', 'path' => '/items/{id}', 'requestMediaType' => 'application/problem+json', 'requestBody' => $contract],
                    $body, $present, 7, 'id', $params);
            }
        };
    }
    public function testNativeJsonValuesAndAbsenceRemainDistinct(): void {
        $transport = new FakeTransport(new HttpResponse(200, '', []));
        $client = $this->client($transport);
        $contract = ['type' => 'mixed', 'nullable' => true, 'required' => false];
        foreach ([null, false, 0, '', [1, 'x', null], (object) ['nested' => [true]]] as $value) {
            $client->post($value, $contract);
            $sent = $transport->sent[array_key_last($transport->sent)];
            self::assertSame(json_encode($value, JSON_THROW_ON_ERROR), $sent['body']);
            self::assertSame('application/problem+json', $sent['headers']['Content-Type']);
            self::assertSame('https://example.test/items/7', $sent['url']);
        }
        $client->post(null, $contract, false);
        self::assertNull($transport->sent[6]['body']);
        self::assertArrayNotHasKey('Content-Type', $transport->sent[6]['headers']);
        foreach ([['type' => 'mixed', 'nullable' => true, 'required' => true], ['type' => 'string', 'nullable' => false, 'required' => false]] as $invalid) {
            try { $client->post(null, $invalid, $invalid['required'] === false); self::fail('Invalid request body reached HTTP.'); }
            catch (\UnexpectedValueException) { self::assertCount(7, $transport->sent); }
        }
    }
    public function testCollectionsMapsAndDirectionalModelsReuseSerialization(): void {
        $transport = new FakeTransport(new HttpResponse(200, '', []));
        $client = $this->client($transport);
        foreach ([[new Tags(['a', 'b']), Tags::class, '["a","b"]'], [new Scores((object) ['one' => 1]), Scores::class, '{"one":1}'],
            [new DirectionalUser((object) ['id' => 9, 'name' => 'Alice', 'password' => 'secret']), DirectionalUser::class, '{"name":"Alice","password":"secret"}']] as [$body, $type, $expected]) {
            $client->post($body, ['type' => $type, 'nullable' => false, 'required' => true]);
            self::assertSame($expected, $transport->sent[array_key_last($transport->sent)]['body']);
        }
        $tags = new Tags(['valid']); $tags->exchangeArray([false]);
        $this->expectException(\TypeError::class);
        $client->post($tags, ['type' => Tags::class, 'nullable' => false, 'required' => true]);
    }
    public function testParameterDtoPresenceAndMutationsAreCheckedBeforeTransport(): void {
        $transport = new FakeTransport(new HttpResponse(200, '', []));
        $client = $this->client($transport);
        $params = new class((object) ['queryBody' => 'separate']) extends DTO {
            public const array OPERATION_FIELDS = ['query' => ['body' => 'queryBody']];
            public const bool OPERATION_BODY = true;
            public ?string $queryBody;
            public ?array $body;
        };
        $contract = ['type' => 'array', 'nullable' => true, 'required' => false, 'rules' => ['schema' => ['type' => ['array', 'null'], 'items' => ['type' => 'integer'], 'minItems' => 1]]];
        $client->post(null, $contract, false, $params);
        self::assertNull($transport->sent[0]['body']); self::assertStringEndsWith('?body=separate', $transport->sent[0]['url']);
        $params->body = null; $client->post(null, $contract, false, $params);
        self::assertSame('null', $transport->sent[1]['body']);
        $params->body = [1]; $client->post(null, $contract, false, $params);
        self::assertSame('[1]', $transport->sent[2]['body']);
        $params->body[] = 'wrong';
        try { $client->post(null, $contract, false, $params); self::fail('Invalid mutation reached transport.'); }
        catch (\UnexpectedValueException) { self::assertCount(3, $transport->sent); }
        unset($params->body); $contract['required'] = true;
        $this->expectException(\UnexpectedValueException::class); $client->post(null, $contract, false, $params);
    }
}
