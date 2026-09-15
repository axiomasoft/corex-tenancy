<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Events\Identity;

use CoreX\Tenancy\Central\ActorRef;
use CoreX\Tenancy\Central\CentralValue;
use DateTimeImmutable;

final readonly class MembershipGranted
{
    public function __construct(
        string $identityId,
        string $accountId,
        string $membershipId,
        public ActorRef $actor,
        public DateTimeImmutable $occurredAt,
    ) {
        $this->identityId = CentralValue::uuid(value: $identityId, field: 'identity_id');
        $this->accountId = CentralValue::uuid(value: $accountId, field: 'account_id');
        $this->membershipId = CentralValue::uuid(value: $membershipId, field: 'membership_id');
    }

    public string $identityId;

    public string $accountId;

    public string $membershipId;
}
