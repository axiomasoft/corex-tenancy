<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Contracts;

use CoreX\Tenancy\Central\MembershipQuery;
use CoreX\Tenancy\Central\MembershipSnapshot;
use CoreX\Tenancy\Central\RevokeMembershipReceipt;
use CoreX\Tenancy\Central\RevokeMembershipRequest;

interface CentralApiClient
{
    public function revokeMembership(RevokeMembershipRequest $request): RevokeMembershipReceipt;

    public function membershipFor(MembershipQuery $query): MembershipSnapshot;
}
