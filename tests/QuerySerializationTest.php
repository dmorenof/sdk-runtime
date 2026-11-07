<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\ApiClient;
use Dmorenof\SdkRuntime\DTO;
use Dmorenof\SdkRuntime\QuerySerializer;
use Dmorenof\SdkRuntime\Security\SecurityCredentials;
use Dmorenof\SdkRuntime\Tests\Fixtures\FakeTransport;
use Dmorenof\SdkRuntime\Transport\HttpResponse;
use PHPUnit\Framework\TestCase;

class QueryListDto extends DTO
{
    protected const array VALIDATION = ['ids' => ['minItems' => 1, 'items' => ['type' => 'int', 'rules' => ['minimum' => 1]]]];
    public array $ids;
}

class QueryListClient extends ApiClient
{
    protected const array SECURITY_SCHEMES = ['Key' => ['type' => 'apiKey', 'scheme' => null, 'location' => 'query', 'parameterName' => 'key']];

    public function search(array $query, array $formats, bool $secure = false): void
    {
        $this->executeOperation(['method' => 'GET', 'path' => '/search', 'querySerialization' => $formats,
            'success' => [204], 'security' => $secure ? [['Key' => []]] : []], queryParameters: $query);
    }
}

final class QuerySerializationTest extends TestCase
{
    public function testRepeatedAndCsvListsEncodeEachItemBeforeJoining(): void
    {
        $values = ['a,b', 'x y', 'c/d', '+&=', 'ñ'];
        self::assertSame('ids=a%2Cb&ids=x%20y&ids=c%2Fd&ids=%2B%26%3D&ids=%C3%B1', QuerySerializer::encode(['ids' => $values], ['ids' => 'multi']));
        self::assertSame('ids=a%2Cb,x%20y,c%2Fd,%2B%26%3D,%C3%B1', QuerySerializer::encode(['ids' => $values], ['ids' => 'csv']));
        self::assertSame('a%5Bb%5D=1&a%5Bb%5D=false', QuerySerializer::encode(['a[b]' => [1, false]], ['a[b]' => 'multi']));
        self::assertSame('0=a&0=b', QuerySerializer::encode(['0' => ['a', 'b']], ['0' => 'multi']));
    }

    public function testAbsentNullEmptyListsAndEmptyStringMembersAreDistinctExpansions(): void
    {
        self::assertSame('', QuerySerializer::encode([], ['ids' => 'multi']));
        self::assertSame('', QuerySerializer::encode(['ids' => null], ['ids' => 'csv']));
        self::assertSame('', QuerySerializer::encode(['ids' => []], ['ids' => 'multi']));
        self::assertSame('ids=&ids=x', QuerySerializer::encode(['ids' => ['', 'x']], ['ids' => 'multi']));
        self::assertSame('ids=,x', QuerySerializer::encode(['ids' => ['', 'x']], ['ids' => 'csv']));
    }

    public function testLegacyScalarEncodingRemainsRfc3986AndStableInOrder(): void
    {
        self::assertSame('q=a%20b%2B%2F&flag=false&zero=0&blank=', QuerySerializer::encode(['q' => 'a b+/', 'flag' => false, 'zero' => 0, 'blank' => '', 'absent' => null]));
    }

    public function testRuntimeBuildsExactUrlAndKeepsSecurityQueryValuesScalar(): void
    {
        $transport = new FakeTransport(new HttpResponse(204, ''));
        $client = QueryListClient::connect('https://api.example.test/v1', transport: $transport,
            securityCredentials: new SecurityCredentials(['Key' => 'secret &+']));
        $client->search(['ids' => [1, 2], 'q' => 'a b'], ['ids' => 'multi'], true);
        $client->search(['key' => [1, 2], 'ids' => [3, 4]], ['key' => 'csv', 'ids' => 'csv'], true);
        self::assertSame('https://api.example.test/v1/search?ids=1&ids=2&q=a%20b&key=secret%20%26%2B', $transport->sent[0]['url']);
        self::assertSame('https://api.example.test/v1/search?key=secret%20%26%2B&ids=3,4', $transport->sent[1]['url']);
    }

    public function testUnsupportedShapesAndFormatsFailBeforeTransport(): void
    {
        foreach ([[['ids' => [1]], []], [['ids' => ['x' => 1]], ['ids' => 'csv']],
            [['ids' => [[1]]], ['ids' => 'multi']], [['ids' => [null]], ['ids' => 'multi']],
            [['ids' => [INF]], ['ids' => 'csv']], [['ids' => [1]], ['ids' => 'pipe']],
            [['ids' => 1], ['ids' => 'csv']]] as [$query, $formats]) {
            $transport = new FakeTransport(new HttpResponse(204, ''));
            try {
                QueryListClient::connect('https://api.example.test', transport: $transport)->search($query, $formats);
                self::fail('Unsupported query data accepted.');
            } catch (\UnexpectedValueException|\InvalidArgumentException) {
                self::assertSame([], $transport->sent);
            }
        }
    }

    public function testNativeListHydrationValidationAndMutationUseDeclarativeItemRules(): void
    {
        $dto = new QueryListDto((object) ['ids' => [1, 2]]);
        self::assertSame([1, 2], $dto->ids);
        self::assertSame('{"ids":[1,2]}', json_encode($dto, JSON_THROW_ON_ERROR));
        $dto->ids[] = 0;
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('ids[2]');
        $dto->validateForRequest();
    }

    public function testNativeListsRejectAssociativeObjectsAndInvalidItemTypes(): void
    {
        foreach ([(object) ['a' => 1], ['a' => 1], ['1'], [null], []] as $value) {
            try {
                new QueryListDto((object) ['ids' => $value]);
                self::fail('Invalid typed list accepted.');
            } catch (\UnexpectedValueException) {
                self::assertTrue(true);
            }
        }
    }

    public function testItemEnumsAndStringRulesAreValidatedWithoutHydratingAwayValues(): void
    {
        $rules = ['items' => ['type' => 'string', 'rules' => ['enum' => ['a', 'bb'], 'minLength' => 1, 'pattern' => '^[ab]+$']]];
        \Dmorenof\SdkRuntime\Constraints::validate(['a', 'bb'], $rules, 'names');
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('names[1]');
        \Dmorenof\SdkRuntime\Constraints::validate(['a', 'cc'], $rules, 'names');
    }
}
