<?php
declare(strict_types=1);

namespace Dmorenof\SdkRuntime\Security;

/**
 * The credential of an OpenAPI `mutualTLS` scheme: the client presents a TLS certificate instead of sending a header.
 * Either the files of the certificate (and its private key), which the default Guzzle transport hands to the TLS
 * handshake for each call that needs them, or the statement that the HTTP client already presents one.
 */
final readonly class ClientCertificate
{
    private function __construct(
        private ?string $certificate,
        private ?string $privateKey,
        private ?string $password,
    ) {}

    /**
     * @param string $certificate Path to the certificate in PEM format. It may also hold the private key.
     * @param string|null $privateKey Path to the private key in PEM format, when it is not in the certificate file.
     * @param string|null $password Passphrase of the private key, if it has one.
     */
    public static function fromFiles(string $certificate, ?string $privateKey = null, ?string $password = null): self
    {
        foreach (['certificate' => $certificate, 'privateKey' => $privateKey] as $label => $path) {
            if ($path !== null && ($path === '' || preg_match('/[\0\r\n]/', $path) === 1)) {
                throw new \InvalidArgumentException("The $label path of a client certificate must be a nonempty path without line breaks.");
            }
        }
        if ($password !== null && ($password === '' || str_contains($password, "\0"))) {
            throw new \InvalidArgumentException('The passphrase of a client certificate must be nonempty and contain no NUL byte.');
        }
        return new self($certificate, $privateKey, $password);
    }

    /**
     * The HTTP client you pass in already presents its own certificate (set up on the client, which is the only place
     * a PSR-18 client other than Guzzle can take one). This satisfies the requirement and adds nothing to the call.
     */
    public static function configuredOnTheClient(): self
    {
        return new self(null, null, null);
    }

    public function isConfiguredOnTheClient(): bool
    {
        return $this->certificate === null;
    }

    /**
     * What Guzzle needs for the TLS handshake of one call (`cert` and `ssl_key` request options).
     *
     * @internal Used by the transport; not covered by the backward-compatibility promise.
     * @return array<string, string|array{string, string}> Nothing when the client holds its own certificate.
     */
    public function guzzleOptions(): array
    {
        if ($this->certificate === null) {
            return [];
        }
        if ($this->privateKey === null) {
            return ['cert' => $this->password === null ? $this->certificate : [$this->certificate, $this->password]];
        }
        return [
            'cert' => $this->certificate,
            'ssl_key' => $this->password === null ? $this->privateKey : [$this->privateKey, $this->password],
        ];
    }

    /** @return array<string, string> Never the passphrase. */
    public function __debugInfo(): array
    {
        return ['certificate' => $this->certificate ?? '(configured on the client)'];
    }
}
