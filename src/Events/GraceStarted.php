<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Events;

use CoreX\Events\DomainEvent;
use CoreX\Tenancy\AccountRef;
use CoreX\Tenancy\Lifecycle\AccountLifecycle;
use CoreX\Tenancy\TenantContext;
use DateTimeImmutable;
use Override;

/**
 * Fired after {@see AccountLifecycle::startGrace()} has transitioned into
 * `grace` and mirrored `root_account_events` (B-11 §5.1). Built with an
 * explicit {@see TenantContext} — see {@see TrialStarted}'s docblock for
 * why.
 */
final class GraceStarted extends DomainEvent
{
    public readonly string $accountId;

    public function __construct(
        AccountRef $account,
        public readonly DateTimeImmutable $graceUntil,
    ) {
        parent::__construct(new TenantContext($account));
        $this->accountId = $account->id;
    }

    #[Override]
    public static function name(): string
    {
        return 'account.grace_started';
    }
}
