<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Central;

enum MembershipReason: string
{
    case Removed = 'removed';
    case Suspended = 'suspended';
    case Policy = 'policy';
}
