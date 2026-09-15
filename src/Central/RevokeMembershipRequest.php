<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Central;

final readonly class RevokeMembershipRequest
{
    public function __construct(
        string $requestId,
        string $identityId,
        string $accountId,
        string $product,
        public MembershipReason $reason,
        ?string $actingIdentityId,
    ) {
        $this->requestId = CentralValue::uuid(value: $requestId, field: 'request_id');
        $this->identityId = CentralValue::uuid(value: $identityId, field: 'identity_id');
        $this->accountId = CentralValue::uuid(value: $accountId, field: 'account_id');
        $this->product = CentralValue::product(value: $product);
        $this->actingIdentityId = $actingIdentityId === null ? null : CentralValue::uuid(value: $actingIdentityId, field: 'acting_identity_id');
    }

    public string $requestId;

    public string $identityId;

    public string $accountId;

    public string $product;

    public ?string $actingIdentityId;

    /** @return array<string, string|null> */
    public function payload(): array
    {
        return ['request_id' => $this->requestId, 'identity_id' => $this->identityId, 'account_id' => $this->accountId, 'product' => $this->product, 'reason' => $this->reason->value, 'acting_identity_id' => $this->actingIdentityId];
    }
}
