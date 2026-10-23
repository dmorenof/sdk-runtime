<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\Operation;
use PHPUnit\Framework\TestCase;

final class OperationTest extends TestCase
{
    public function testMethodAndPathAreEnoughAndEverythingElseIsInherited(): void
    {
        $operation = Operation::fromArray(['method' => 'GET', 'path' => '/ping']);

        self::assertSame('GET /ping', $operation->label);
        self::assertSame(Operation::DEFAULTS['success'], $operation->success);
        self::assertSame([], $operation->security);
        self::assertNull($operation->responseType);
        self::assertTrue($operation->succeeded(200));
        self::assertFalse($operation->succeeded(201));
    }

    public function testDeclaredValuesOverrideDefaults(): void
    {
        $operation = Operation::fromArray([
            'method' => 'POST',
            'path' => '/users',
            'label' => 'create user',
            'success' => [201, 202],
            'responseType' => 'App\\User',
        ]);

        self::assertSame('create user', $operation->label);
        self::assertTrue($operation->succeeded(202));
        self::assertSame('App\\User', $operation->responseType);
    }

    public function testErrorTypeFallsBackToDefault(): void
    {
        $operation = Operation::fromArray([
            'method' => 'GET',
            'path' => '/x',
            'errors' => [404 => 'App\\NotFound', 'default' => 'App\\Problem'],
        ]);

        self::assertSame('App\\NotFound', $operation->errorTypeFor(404));
        self::assertSame('App\\Problem', $operation->errorTypeFor(500));
        self::assertNull(Operation::fromArray(['method' => 'GET', 'path' => '/x'])->errorTypeFor(500));
    }

    public function testRejectsUnknownKeysInsteadOfIgnoringThem(): void
    {
        $this->expectException(\Error::class);
        /** @phpstan-ignore argument.type */
        Operation::fromArray(['method' => 'GET', 'path' => '/x', 'sucess' => [204]]);
    }
}
