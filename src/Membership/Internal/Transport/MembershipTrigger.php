<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Transport;

use CoreX\Tenancy\Central\MembershipReason;
use DateTimeImmutable;

/** @internal Trigger data only: no active state, positive lease or grant method. */
final readonly class MembershipTrigger
{
    public function __construct(
        public string $profileVersion,
        public string $eventId,
        public string $deliveryId,
        public string $identityId,
        public string $accountId,
        public string $product,
        public MembershipEventKind $kind,
        public string $commitRef,
        public MembershipReason $reason,
        public ?string $actingIdentityId,
        public DateTimeImmutable $occurredAt,
        public DateTimeImmutable $issuedAt,
        public DateTimeImmutable $expiresAt,
    ) {
        Value::profile($profileVersion);
        Value::uuid($eventId);
        Value::uuid($deliveryId);
        Value::uuid($identityId);
        Value::uuid($accountId);
        Value::product($product);

        if ($actingIdentityId !== null) {
            Value::uuid($actingIdentityId);
        }
    }

    public function logicalDigest(): string
    {
        return hash('sha256', json_encode([$this->profileVersion, $this->eventId, $this->identityId, $this->accountId, $this->product, $this->kind->value, $this->commitRef, $this->reason->value, $this->actingIdentityId, $this->occurredAt->format('Y-m-d\TH:i:s.uP')], JSON_THROW_ON_ERROR));
    }
}
