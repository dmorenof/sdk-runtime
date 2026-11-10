<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Tests;

use Dmorenof\SdkRuntime\Constraints;
use Dmorenof\SdkRuntime\DTO;
use Dmorenof\SdkRuntime\FormatValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FormattedModel extends DTO
{
    protected const array VALIDATION = [
        'when' => ['format' => 'date-time'],
        'count' => ['format' => 'int32'],
        'blob' => ['format' => 'byte'],
    ];

    public string $when;
    public int $count;
    public string $blob;
}

final class FormatValidatorTest extends TestCase
{
    /** @return iterable<string, array{string, mixed, bool}> */
    public static function cases(): iterable
    {
        yield 'date ok' => ['date', '2026-11-10', true];
        yield 'date impossible day' => ['date', '2026-02-30', false];
        yield 'date bad month' => ['date', '2026-13-01', false];
        yield 'date not padded' => ['date', '2026-1-1', false];
        yield 'time with offset' => ['time', '12:30:45.123+01:00', true];
        yield 'time utc' => ['time', '12:30:45Z', true];
        yield 'time hour 24' => ['time', '24:00:00Z', false];
        yield 'time without seconds' => ['time', '12:30', false];
        yield 'date-time utc' => ['date-time', '2026-11-10T12:30:45Z', true];
        yield 'date-time offset' => ['date-time', '2026-11-10T12:30:45+01:00', true];
        yield 'date-time impossible date' => ['date-time', '2026-02-30T12:30:45Z', false];
        yield 'date-time space separator' => ['date-time', '2026-11-10 12:30:45Z', false];
        yield 'duration full' => ['duration', 'P1Y2M3DT4H5M6S', true];
        yield 'duration weeks' => ['duration', 'P3W', true];
        yield 'duration empty period' => ['duration', 'P', false];
        yield 'duration text' => ['duration', 'hello', false];
        yield 'email ok' => ['email', 'ada@example.com', true];
        yield 'email bad' => ['email', 'not-an-email', false];
        yield 'ipv4 ok' => ['ipv4', '192.168.0.1', true];
        yield 'ipv4 out of range' => ['ipv4', '256.1.1.1', false];
        yield 'ipv6 ok' => ['ipv6', '::1', true];
        yield 'ipv6 rejects ipv4' => ['ipv6', '1.2.3.4', false];
        yield 'uuid ok' => ['uuid', '123e4567-e89b-12d3-a456-426614174000', true];
        yield 'uuid without dashes' => ['uuid', '123e4567e89b12d3a456426614174000', false];
        yield 'uri absolute' => ['uri', 'https://example.com/a?b=1', true];
        yield 'uri needs a scheme' => ['uri', 'example.com/path', false];
        yield 'uri empty' => ['uri', '', false];
        yield 'uri-reference relative' => ['uri-reference', '/a/b?c=1', true];
        yield 'uri-reference empty' => ['uri-reference', '', true];
        yield 'hostname ok' => ['hostname', 'example.com', true];
        yield 'hostname with space' => ['hostname', 'bad host', false];
        yield 'json-pointer root' => ['json-pointer', '/', true];
        yield 'regex ok' => ['regex', '^[a-z]+$', true];
        yield 'regex broken' => ['regex', '(', false];
        yield 'byte ok' => ['byte', 'SGVsbG8gV29ybGQ=', true];
        yield 'byte empty' => ['byte', '', true];
        yield 'byte unpadded' => ['byte', 'SGVsbG8', false];
        yield 'byte bad alphabet' => ['byte', 'SGVs*G8=', false];
        yield 'int32 max' => ['int32', 2147483647, true];
        yield 'int32 min' => ['int32', -2147483648, true];
        yield 'int32 above' => ['int32', 2147483648, false];
        yield 'int32 below' => ['int32', -2147483649, false];
        yield 'int64 max' => ['int64', PHP_INT_MAX, true];
    }

    #[DataProvider('cases')]
    public function testFormatAssertions(string $format, mixed $value, bool $valid): void
    {
        self::assertSame($valid, FormatValidator::check($format, $value));

        if ($valid) {
            Constraints::validate($value, ['format' => $format], 'field');
            return;
        }
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage("Validation format failed at field (format '$format').");
        Constraints::validate($value, ['format' => $format], 'field');
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function nonAssertingCases(): iterable
    {
        yield 'unknown format' => ['x-vendor-code', 'whatever'];
        yield 'float annotation' => ['float', 1.5];
        yield 'double annotation' => ['double', 1.5];
        yield 'password annotation' => ['password', 'anything'];
        yield 'binary annotation' => ['binary', 'raw bytes'];
        yield 'string format on a number' => ['date', 20261110];
        yield 'string format on a list' => ['email', ['a@b.co']];
        yield 'string format on null' => ['uuid', null];
        yield 'integer format on a string' => ['int32', '99999999999'];
        yield 'integer format on a float' => ['int32', 3.5];
    }

    #[DataProvider('nonAssertingCases')]
    public function testFormatsThatAssertNothingNeverFail(string $format, mixed $value): void
    {
        self::assertNull(FormatValidator::check($format, $value));
        Constraints::validate($value, ['format' => $format], 'field');
        $this->addToAssertionCount(1);
    }

    public function testFormatRuleMustBeANonEmptyString(): void
    {
        $this->expectException(\LogicException::class);
        Constraints::validate('x', ['format' => ''], 'field');
    }

    public function testFormatAppliesToListItems(): void
    {
        $rules = ['items' => ['type' => 'string', 'rules' => ['format' => 'uuid']]];
        Constraints::validate(['123e4567-e89b-12d3-a456-426614174000'], $rules, 'ids');

        $this->expectException(\UnexpectedValueException::class);
        Constraints::validate(['123e4567-e89b-12d3-a456-426614174000', 'nope'], $rules, 'ids');
    }

    public function testFormatCombinesWithOtherRules(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        Constraints::validate('a@b.co', ['format' => 'email', 'maxLength' => 3], 'field');
    }

    public function testModelFieldsAreCheckedAtValidation(): void
    {
        $valid = new FormattedModel((object) ['when' => '2026-11-10T12:30:45Z', 'count' => 5, 'blob' => 'SGk=']);
        $valid->validate();
        $this->addToAssertionCount(1);

        $this->expectException(\UnexpectedValueException::class);
        $invalid = new FormattedModel((object) ['when' => 'yesterday', 'count' => 5, 'blob' => 'SGk=']);
        $invalid->validate();
    }

    public function testAssertedFormatsListCoversTheDocumentedSet(): void
    {
        $asserted = FormatValidator::asserted();

        foreach (['date', 'time', 'date-time', 'duration', 'email', 'hostname', 'ipv4', 'ipv6', 'uri', 'uri-reference', 'uuid', 'json-pointer', 'regex', 'byte', 'int32', 'int64'] as $name) {
            self::assertContains($name, $asserted);
        }
        self::assertNotContains('float', $asserted);
        self::assertSame($asserted, array_values(array_unique($asserted)));
    }
}
