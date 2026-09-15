<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Central;

enum MembershipState: string
{
    case Active = 'active';
    case Invited = 'invited';
    case Pending = 'pending';
    case Blocked = 'blocked';
    case Revoked = 'revoked';
    case Deleted = 'deleted';
    case Missing = 'missing';
}
