<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Timing;

/** @internal Reference lease policy; actual platform bounds remain unqualified. */
final class Lease
{
    public static function canCommit(int $elapsedUpperSeconds, int $remainingCommitUpperSeconds): bool
    {
        return $elapsedUpperSeconds >= 0
            && $remainingCommitUpperSeconds >= 0
            && $elapsedUpperSeconds + $remainingCommitUpperSeconds < 45;
    }
}
