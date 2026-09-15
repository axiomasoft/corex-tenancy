<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Central;

use DateTimeImmutable;

final readonly class MembershipSnapshot
{
    public function __construct(
        string $profileVersion,
        string $requestId,
        string $identityId,
        string $accountId,
        string $product,
        ?string $membershipId,
        MembershipState $state,
        string $revision,
        string $revokeGeneration,
        DateTimeImmutable $readStartedAt,
        DateTimeImmutable $observedAt,
    ) {
        $this->profileVersion = CentralValue::profile(value: $profileVersion);
        $this->requestId = CentralValue::uuid(value: $requestId, field: 'request_id');
        $this->identityId = CentralValue::uuid(value: $identityId, field: 'identity_id');
        $this->accountId = CentralValue::uuid(value: $accountId, field: 'account_id');
        $this->product = CentralValue::product(value: $product);
        $this->membershipId = $membershipId === null ? null : CentralValue::uuid(value: $membershipId, field: 'membership_id');
        $this->state = $state;
        $this->revision = CentralValue::counter(value: $revision);
        $this->revokeGeneration = CentralValue::counter(value: $revokeGeneration);
        $this->readStartedAt = $readStartedAt;
        $this->observedAt = $observedAt;

        if ($this->state === MembershipState::Active && $this->membershipId === null || $this->observedAt < $this->readStartedAt) {
            throw new CentralProtocolViolation('Invalid membership authority snapshot.');
        }
    }

    public string $profileVersion;

    public string $requestId;

    public string $identityId;

    public string $accountId;

    public string $product;

    public ?string $membershipId;

    public MembershipState $state;

    public string $revision;

    public string $revokeGeneration;

    public DateTimeImmutable $readStartedAt;

    public DateTimeImmutable $observedAt;

    /** @param array<string, mixed> $payload */
    public static function fromPayload(array $payload): self
    {
        CentralValue::fields(payload: $payload, fields: ['profile_version', 'request_id', 'identity_id', 'account_id', 'product', 'membership_id', 'state', 'revision', 'revoke_generation', 'read_started_at', 'observed_at']);

        if ($payload['membership_id'] !== null && ! is_string($payload['membership_id'])) {
            throw new CentralProtocolViolation('Invalid nullable membership UUID.');
        }

        $state = MembershipState::tryFrom(CentralValue::string(payload: $payload, field: 'state'))
            ?? throw new CentralProtocolViolation('Unknown membership state.');

        return new self(
            profileVersion: CentralValue::string(payload: $payload, field: 'profile_version'),
            requestId: CentralValue::string(payload: $payload, field: 'request_id'),
            identityId: CentralValue::string(payload: $payload, field: 'identity_id'),
            accountId: CentralValue::string(payload: $payload, field: 'account_id'),
            product: CentralValue::string(payload: $payload, field: 'product'),
            membershipId: $payload['membership_id'],
            state: $state,
            revision: CentralValue::string(payload: $payload, field: 'revision'),
            revokeGeneration: CentralValue::string(payload: $payload, field: 'revoke_generation'),
            readStartedAt: CentralValue::utc(value: CentralValue::string(payload: $payload, field: 'read_started_at')),
            observedAt: CentralValue::utc(value: CentralValue::string(payload: $payload, field: 'observed_at')),
        );
    }
}
