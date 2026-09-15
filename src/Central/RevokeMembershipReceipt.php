<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Central;

use DateTimeImmutable;

final readonly class RevokeMembershipReceipt
{
    public function __construct(
        string $profileVersion,
        string $requestId,
        string $identityId,
        string $accountId,
        string $product,
        string $commitRef,
        string $revision,
        string $revokeGeneration,
        DateTimeImmutable $committedAt,
    ) {
        $this->profileVersion = CentralValue::profile(value: $profileVersion);
        $this->requestId = CentralValue::uuid(value: $requestId, field: 'request_id');
        $this->identityId = CentralValue::uuid(value: $identityId, field: 'identity_id');
        $this->accountId = CentralValue::uuid(value: $accountId, field: 'account_id');
        $this->product = CentralValue::product(value: $product);
        $this->commitRef = $commitRef;
        $this->revision = CentralValue::counter(value: $revision);
        $this->revokeGeneration = CentralValue::counter(value: $revokeGeneration);
        $this->committedAt = $committedAt;

        if (preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $commitRef) !== 1) {
            throw new CentralProtocolViolation('Invalid opaque commit reference.');
        }
    }

    public string $profileVersion;

    public string $requestId;

    public string $identityId;

    public string $accountId;

    public string $product;

    public string $commitRef;

    public string $revision;

    public string $revokeGeneration;

    public DateTimeImmutable $committedAt;

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        CentralValue::fields(payload: $payload, fields: ['profile_version', 'request_id', 'identity_id', 'account_id', 'product', 'commit_ref', 'revision', 'revoke_generation', 'committed_at']);

        return new self(
            profileVersion: CentralValue::string(payload: $payload, field: 'profile_version'),
            requestId: CentralValue::string(payload: $payload, field: 'request_id'),
            identityId: CentralValue::string(payload: $payload, field: 'identity_id'),
            accountId: CentralValue::string(payload: $payload, field: 'account_id'),
            product: CentralValue::string(payload: $payload, field: 'product'),
            commitRef: CentralValue::string(payload: $payload, field: 'commit_ref'),
            revision: CentralValue::string(payload: $payload, field: 'revision'),
            revokeGeneration: CentralValue::string(payload: $payload, field: 'revoke_generation'),
            committedAt: CentralValue::utc(value: CentralValue::string(payload: $payload, field: 'committed_at')),
        );
    }
}
