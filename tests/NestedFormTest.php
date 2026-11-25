<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\FormSerializer;
use Dmorenof\SdkRuntime\QuerySerializer;
use PHPUnit\Framework\TestCase;

final class NestedFormTest extends TestCase
{
    public function testQueryNestsObjectsAndIndexesLists(): void
    {
        $value = (object) ['gt' => 5, 'fields' => ['a', 'b'], 'opts' => (object) ['on' => true, 'skip' => null], 'rows' => [(object) ['key' => 'k', 'n' => 1]]];
        self::assertSame(
            'created%5Bgt%5D=5&created%5Bfields%5D%5B0%5D=a&created%5Bfields%5D%5B1%5D=b&created%5Bopts%5D%5Bon%5D=true&created%5Brows%5D%5B0%5D%5Bkey%5D=k&created%5Brows%5D%5B0%5D%5Bn%5D=1',
            QuerySerializer::encode(['created' => $value], ['created' => 'nested']),
        );
        self::assertSame('expand%5B0%5D=data&expand%5B1%5D=x', QuerySerializer::encode(['expand' => ['data', 'x']], ['expand' => 'nested']));
        self::assertSame('', QuerySerializer::encode(['expand' => []], ['expand' => 'nested']));
    }

    public function testQueryRejectsBracketKeysAndCollisions(): void
    {
        foreach ([[['a' => (object) ['b[c]' => 1]], 'a'], [['a' => (object) ['b' => 1], 'a[b]' => 'x'], 'a']] as [$query, $name]) {
            try {
                QuerySerializer::encode($query, [$name => 'nested']);
                self::fail('Ambiguous nested query accepted.');
            } catch (\UnexpectedValueException) {
                self::assertTrue(true);
            }
        }
    }

    public function testFlatDeepObjectStillRefusesNesting(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        QuerySerializer::encode(['a' => (object) ['b' => (object) ['c' => 1]]], ['a' => 'deepObject']);
    }

    public function testFormBodyNestsAFieldDeclaredNested(): void
    {
        $body = new class extends \Dmorenof\SdkRuntime\DTO {
            public function jsonSerializeForRequest(): \stdClass
            {
                return (object) ['type' => 'x', 'collection_options' => (object) ['fields' => 'all', 'list' => [(object) ['k' => 'v']]], 'expand' => ['a']];
            }
        };
        self::assertSame(
            'type=x&collection_options%5Bfields%5D=all&collection_options%5Blist%5D%5B0%5D%5Bk%5D=v&expand%5B0%5D=a',
            FormSerializer::encode($body, ['collection_options' => 'nested', 'expand' => 'nested']),
        );
    }

    public function testMultipartWritesANestedFieldAsTextParts(): void
    {
        $body = new class extends \Dmorenof\SdkRuntime\DTO {
            public function multipartSerializeForRequest(): object
            {
                return (object) ['file_link_data' => (object) ['create' => true, 'metadata' => (object) ['k' => 'v']]];
            }
        };
        $mime = (string) \Dmorenof\SdkRuntime\MultipartSerializer::encode($body, ['file_link_data' => ['contentType' => 'text/plain', 'nested' => true]]);
        self::assertStringContainsString('name="file_link_data[create]"', $mime);
        self::assertStringContainsString("\r\n\r\ntrue\r\n", $mime);
        self::assertStringContainsString('name="file_link_data[metadata][k]"', $mime);
    }
}
