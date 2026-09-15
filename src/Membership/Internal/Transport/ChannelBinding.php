<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Transport;

use CoreX\Tenancy\Central\CentralUnauthorized;
use CoreX\Tenancy\Central\CentralUnavailable;

/** @internal Supplied local profile; no guessed endpoint or runtime registration. */
final readonly class ChannelBinding
{
    public function __construct(
        public string $readUri,
        public string $revokeUri,
        public string $issuer,
        public string $resource,
        public string $clientId,
        public string $subject,
        public string $accountId,
        public string $product,
        public string $certificatePath,
        public string $keyPath,
        public string $caPath,
        public string $certificateThumbprint,
        public int $certificateAcceptedUntil,
        public bool $deduplicationQualified,
    ) {}

    public function assertUsable(string $accountId, string $product, int $now): void
    {
        foreach ([$this->readUri, $this->revokeUri, $this->issuer, $this->resource] as $uri) {
            $parts = parse_url($uri);

            if (! is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
                || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                throw new CentralUnavailable('Qualified absolute HTTPS binding is absent.');
            }
        }
        Value::uuid($accountId);
        Value::product($product);

        if ($this->accountId !== $accountId || $this->product !== $product || $this->clientId === '' || $this->subject === ''
            || $now >= $this->certificateAcceptedUntil) {
            throw new CentralUnauthorized('Account, product or certificate registration denied.');
        }
        foreach ([$this->certificatePath, $this->keyPath, $this->caPath] as $path) {
            if ($path === '' || ! is_file($path) || ! is_readable($path)) {
                throw new CentralUnavailable('Mutual TLS material is unavailable.');
            }
        }
        $pem = file_get_contents($this->certificatePath);
        $certificate = $pem === false ? false : openssl_x509_read($pem);
        $metadata = $certificate === false ? false : openssl_x509_parse($certificate);
        $key = openssl_pkey_get_private('file://'.$this->keyPath);

        if ($certificate === false || $metadata === false || $key === false
            || ! openssl_x509_check_private_key($certificate, $key)
            || $now < $metadata['validFrom_time_t'] || $now >= $metadata['validTo_time_t']) {
            throw new CentralUnauthorized('Invalid mutual TLS certificate/key.');
        }
        $fingerprint = openssl_x509_fingerprint($certificate, 'sha256', true);
        $thumbprint = $fingerprint === false ? '' : rtrim(strtr(base64_encode($fingerprint), '+/', '-_'), '=');

        if (! hash_equals($this->certificateThumbprint, $thumbprint)) {
            throw new CentralUnauthorized('Certificate does not match supplied registration.');
        }
    }
}
