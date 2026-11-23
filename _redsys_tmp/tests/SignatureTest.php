<?php
declare(strict_types=1);

namespace Redsys\Tests;

use PHPUnit\Framework\TestCase;
use Redsys\Signature;

final class SignatureTest extends TestCase
{
    /** The generic test key Redsys publishes for every merchant (manual of the redirection connection, section 6). */
    private const KEY = 'sq7HjrUOBfKmC576ILgskD5srU870gJ7';

    /** The Base64 JSON of the manual's first example (amount 999, order 1234567890, merchant 999008881). */
    private const PARAMETERS = 'eyJEU19NRVJDSEFOVF9BTU9VTlQiOiI5OTkiLCJEU19NRVJDSEFOVF9PUkRFUiI6IjEyMzQ1Njc4OTAiLCJEU19NRVJDSEFOVF9NRVJDSEFOVENPREUiOiI5OTkwMDg4ODEiLCJEU19NRVJDSEFOVF9DVVJSRU5DWSI6Ijk3OCIsIkRTX01FUkNIQU5UX1RSQU5TQUNUSU9OVFlQRSI6IjAiLCJEU19NRVJDSEFOVF9URVJNSU5BTCI6IjEiLCJEU19NRVJDSEFOVF9NRVJDSEFOVFVSTCI6Imh0dHA6XC9cL3d3dy5wcnVlYmEuY29tXC91cmxOb3RpZmljYWNpb24ucGhwIiwiRFNfTUVSQ0hBTlRfVVJMT0siOiJodHRwOlwvXC93d3cucHJ1ZWJhLmNvbVwvdXJsT0sucGhwIiwiRFNfTUVSQ0hBTlRfVVJMS08iOiJodHRwOlwvXC93d3cucHJ1ZWJhLmNvbVwvdXJsS08ucGhwIn0';

    /** Computed by three other implementations that agree: the `openssl` command line (des-ede3-cbc, then dgst -mac HMAC), Python's `cryptography` and this one. */
    private const EXPECTED = 'Yx3zR6dIlm1GdJjKSBP9vX6qV+iedPxPSMnZGpUhfPI=';

    public function testTheManualsExampleGivesTheSignatureOfIndependentImplementations(): void
    {
        self::assertSame(self::EXPECTED, Signature::sign(self::PARAMETERS, '1234567890', self::KEY));
    }

    public function testTheSignatureDependsOnTheOrderNumber(): void
    {
        self::assertNotSame(self::EXPECTED, Signature::sign(self::PARAMETERS, '1234567891', self::KEY));
    }

    public function testAnOrderNumberOfAnyLengthIsPaddedWithZeroBytes(): void
    {
        // 8 characters is exactly one block: no padding; 9 needs a second block.
        self::assertNotSame(Signature::sign('x', '12345678', self::KEY), Signature::sign('x', '123456789', self::KEY));
        self::assertSame(Signature::sign('x', "1234\0\0\0\0", self::KEY), Signature::sign('x', '1234', self::KEY));
    }

    public function testVerifyAcceptsEveryBase64SpellingOfTheSignature(): void
    {
        $url = rtrim(strtr(self::EXPECTED, '+/', '-_'), '=');
        self::assertTrue(Signature::verify(self::EXPECTED, self::PARAMETERS, '1234567890', self::KEY));
        self::assertTrue(Signature::verify($url, self::PARAMETERS, '1234567890', self::KEY));
        self::assertSame('Yx3zR6dIlm1GdJjKSBP9vX6qV-iedPxPSMnZGpUhfPI', $url, 'the vector has a + that the URL-safe alphabet writes as -');
    }

    public function testVerifyRefusesAnyOtherSignatureParametersOrKey(): void
    {
        self::assertFalse(Signature::verify('Yx3zR6dIlm1GdJjKSBP9vX6qV+iedPxPSMnZGpUhfPA=', self::PARAMETERS, '1234567890', self::KEY));
        self::assertFalse(Signature::verify(self::EXPECTED, self::PARAMETERS . 'A', '1234567890', self::KEY));
        self::assertFalse(Signature::verify(self::EXPECTED, self::PARAMETERS, '1234567890', base64_encode(str_repeat('k', 24))));
        self::assertFalse(Signature::verify('', self::PARAMETERS, '1234567890', self::KEY));
    }

    public function testAKeyThatIsNotRedsysBase64Of24BytesIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Signature::sign(self::PARAMETERS, '1234567890', 'not a key');
    }
}
