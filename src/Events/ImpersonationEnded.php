<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Events;

use CoreX\Events\DomainEvent;
use CoreX\Tenancy\Impersonation\DatabaseImpersonationService;
use Override;

/**
 * Fired after {@see DatabaseImpersonationService::stop()}
 * has ended the session and mirrored `root_account_events` (B-11 §3.4) —
 * on explicit `stop()`, TTL expiry, or an externally-set `revoked_at`.
 */
final class ImpersonationEnded extends DomainEvent
{
    public function __construct(
        public readonly string $grantId,
        public readonly string $staffIdentityId,
        public readonly string $targetUserId,
    ) {
        parent::__construct();
    }

    #[Override]
    public static function name(): string
    {
        return 'auth.impersonation.ended';
    }
}
