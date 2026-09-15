<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Identity;

/**
 * B-11 §3.2 (L618–624): keeps an account's tenant-DB `users` projection in
 * step with the central `root_identities` row (full denormalization, D4).
 */
interface IdentitySyncer
{
    /** Idempotent upsert of the users-row for one account (AC-16). */
    public function syncToAccount(string $identityId, string $accountId): void;

    /** Queued fan-out across every account membership (root_identity_accounts). */
    public function fanOut(string $identityId): void;
}
