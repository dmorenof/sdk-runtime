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

enum DeepState: string { case Active = 'active'; }

class DeepFilterDto extends DTO
{
    protected const array PROPERTY_MAP = ['city-name' => 'city'];
    protected const array WIRE_PROPERTY_MAP = ['city' => 'city-name'];
    protected const array READ_ONLY_FIELDS = ['readonlyValue'];
    protected const array WRITE_ONLY_FIELDS = ['secret'];
    protected const array VALIDATION = ['city' => ['minLength' => 1]];
    public string $city;
    public string $readonlyValue;
    public string $secret;
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

    public function testDelimitedListsEncodeSeparatorsAndItemsForLegalUris(): void
    {
        foreach (['ssv' => '%20', 'tsv' => '%09', 'pipes' => '%7C'] as $format => $separator) {
            self::assertSame('ids=a%2Cb' . $separator . '%C3%B1%2B%26' . $separator . '%2520%257C',
                QuerySerializer::encode(['ids' => ['a,b', 'ñ+&', '%20%7C']], ['ids' => $format]));
            self::assertSame('', QuerySerializer::encode(['ids' => []], ['ids' => $format]));
            self::assertSame('ids=' . $separator . 'x', QuerySerializer::encode(['ids' => ['', 'x']], ['ids' => $format]));
        }
    }

    public function testDelimitedRuntimeOperationsKeepScalarSecurityAndValidateMutations(): void
    {
        $transport = new FakeTransport(new HttpResponse(204, ''));
        $client = QueryListClient::connect('https://api.example.test', transport: $transport,
            securityCredentials: new SecurityCredentials(['Key' => 'secret |']));
        $client->search(['ids' => [1, 2], 'tags' => ['ñ', '+']], ['ids' => 'ssv', 'tags' => 'pipes'], true);
        self::assertSame('https://api.example.test/search?ids=1%202&tags=%C3%B1%7C%2B&key=secret%20%7C', $transport->sent[0]['url']);
        $values = ['a', 'b'];
        $values[1] = 'b|c';
        try {
            $client->search(['ids' => $values], ['ids' => 'pipes']);
            self::fail('Ambiguous mutation accepted.');
        } catch (\UnexpectedValueException $exception) {
            self::assertStringContainsString('ids', $exception->getMessage());
            self::assertCount(1, $transport->sent);
        }
    }

    public function testDelimitedListsRejectTheirOwnDelimiterBeforeTransport(): void
    {
        foreach (['ssv' => 'a b', 'tsv' => "a\tb", 'pipes' => 'a|b'] as $format => $value) {
            $transport = new FakeTransport(new HttpResponse(204, ''));
            try {
                QueryListClient::connect('https://api.example.test', transport: $transport)->search(['ids' => [$value]], ['ids' => $format]);
                self::fail('Ambiguous delimiter accepted.');
            } catch (\UnexpectedValueException $exception) {
                self::assertStringContainsString('list delimiter', $exception->getMessage());
                self::assertSame([], $transport->sent);
            }
        }
    }

    public function testDeepObjectsEncodeWireNamesAndScalarValues(): void
    {
        self::assertSame('filter%5Bcity%5D=Madrid&filter%5Bactive%5D=false&filter%5B0%5D=0&filter%5Bname%20x%5D=%C3%B1%2B%26',
            QuerySerializer::encode(['filter' => (object) ['city' => 'Madrid', 'active' => false, '0' => 0, 'name x' => 'ñ+&']], ['filter' => 'deepObject']));
        self::assertSame('', QuerySerializer::encode(['filter' => (object) []], ['filter' => 'deepObject']));
        self::assertSame('', QuerySerializer::encode(['filter' => null], ['filter' => 'deepObject']));
        self::assertSame('filter%5Bname%5D=', QuerySerializer::encode(['filter' => (object) ['name' => '']], ['filter' => 'deepObject']));
    }

    public function testDeepDtoSerializationValidatesMutationsAndDirectionalFields(): void
    {
        $dto = new DeepFilterDto((object) ['city-name' => 'Madrid', 'readonlyValue' => 'hidden', 'secret' => 'sent']);
        self::assertSame('filter%5Bcity-name%5D=Madrid&filter%5Bsecret%5D=sent', QuerySerializer::encode(['filter' => $dto], ['filter' => 'deepObject']));
        $dto->city = '';
        $this->expectException(\UnexpectedValueException::class);
        QuerySerializer::encode(['filter' => $dto], ['filter' => 'deepObject']);
    }

    public function testDeepMapsAndBackedEnumsUseTheirValidatedScalarValues(): void
    {
        $map = new class((object) ['city' => 'Madrid']) extends \Dmorenof\SdkRuntime\Map {
            protected const string VALUE_TYPE = 'string';
            protected const array VALUE_VALIDATION = ['minLength' => 1];
        };
        self::assertSame('filter%5Bcity%5D=Madrid', QuerySerializer::encode(['filter' => $map], ['filter' => 'deepObject']));
        self::assertSame('filter%5Bstate%5D=active', QuerySerializer::encode(['filter' => (object) ['state' => DeepState::Active]], ['filter' => 'deepObject']));
        $this->expectException(\UnexpectedValueException::class);
        $map['city'] = '';
        QuerySerializer::encode(['filter' => $map], ['filter' => 'deepObject']);
    }

    public function testDeepObjectsRejectUndefinedShapesAndAmbiguousKeysBeforeTransport(): void
    {
        foreach ([(object) ['a' => null], (object) ['a' => [1]], (object) ['a' => (object) ['b' => 1]],
            (object) ['a' => INF], (object) ['a[b]' => 1], (object) ['' => 1], [], ['a' => 1], 'a'] as $value) {
            $transport = new FakeTransport(new HttpResponse(204, ''));
            try {
                QueryListClient::connect('https://api.example.test', transport: $transport)->search(['filter' => $value], ['filter' => 'deepObject']);
                self::fail('Undefined deep value accepted.');
            } catch (\UnexpectedValueException) {
                self::assertSame([], $transport->sent);
            }
        }
    }

    public function testDeepObjectsRejectCollisionsWithExplicitAndSecurityQueryNames(): void
    {
        foreach ([['filter' => (object) ['name' => 'x'], 'filter[name]' => 'y'], ['filter[x]' => (object) ['name' => 'x']]] as $query) {
            try {
                QuerySerializer::encode($query, [array_key_first($query) => 'deepObject']);
                self::fail('Ambiguous query name accepted.');
            } catch (\UnexpectedValueException) {
                self::assertTrue(true);
            }
        }
        $transport = new FakeTransport(new HttpResponse(204, ''));
        $client = QueryListClient::connect('https://api.example.test', transport: $transport,
            securityCredentials: new SecurityCredentials(['Key' => 'secret']));
        $client->search(['key' => (object) ['name' => 'ignored'], 'filter' => (object) ['city' => 'Madrid']], ['key' => 'deepObject', 'filter' => 'deepObject'], true);
        self::assertSame('https://api.example.test/search?key=secret&filter%5Bcity%5D=Madrid', $transport->sent[0]['url']);
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
