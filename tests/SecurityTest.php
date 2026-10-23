<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\Security\BasicAuthCredentials;
use Dmorenof\SdkRuntime\Security\SecurityApplier;
use Dmorenof\SdkRuntime\Security\SecurityCredentials;
use PHPUnit\Framework\TestCase;

final class SecurityTest extends TestCase
{
    private const array SCHEMES = [
        'Basic' => ['type' => 'http', 'scheme' => 'basic', 'location' => null, 'parameterName' => null],
        'Key' => ['type' => 'apiKey', 'scheme' => null, 'location' => 'header', 'parameterName' => 'X-Key'],
        'Q' => ['type' => 'apiKey', 'scheme' => null, 'location' => 'query', 'parameterName' => 'k'],
    ];

    public function testAppliesFirstSatisfiedAlternative(): void
    {
        $headers = [];
        $query = [];
        SecurityApplier::apply(
            new SecurityCredentials(['Key' => 'abc']),
            [['Basic' => []], ['Key' => []]],
            self::SCHEMES,
            $headers,
            $query,
        );

        self::assertSame(['X-Key' => 'abc'], $headers);
        self::assertSame([], $query);
    }

    public function testAppliesBasicAndQueryTogether(): void
    {
        $headers = [];
        $query = [];
        SecurityApplier::apply(
            new SecurityCredentials(['Basic' => new BasicAuthCredentials('u', 'p'), 'Q' => 'z']),
            [['Basic' => [], 'Q' => []]],
            self::SCHEMES,
            $headers,
            $query,
        );

        self::assertSame('Basic ' . base64_encode('u:p'), $headers['Authorization']);
        self::assertSame(['k' => 'z'], $query);
    }

    public function testRejectsWhenNothingIsSatisfied(): void
    {
        $headers = [];
        $query = [];
        $this->expectException(\InvalidArgumentException::class);
        SecurityApplier::apply(new SecurityCredentials(), [['Key' => []]], self::SCHEMES, $headers, $query);
    }

    public function testOptionalSecurityNeedsNoCredentials(): void
    {
        $headers = [];
        $query = [];
        SecurityApplier::apply(new SecurityCredentials(), [[]], self::SCHEMES, $headers, $query);
        self::assertSame([], $headers);
    }

    public function testCredentialsAreRedactedFromDebugOutput(): void
    {
        self::assertStringNotContainsString('secret', print_r(new SecurityCredentials(['Key' => 'secret']), true));
        self::assertStringNotContainsString('hunter2', print_r(new BasicAuthCredentials('u', 'hunter2'), true));
    }
}
