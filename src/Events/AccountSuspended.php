<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Events;

use CoreX\Events\DomainEvent;
use CoreX\Tenancy\AccountRef;
use CoreX\Tenancy\Lifecycle\AccountLifecycle;
use CoreX\Tenancy\TenantContext;
use Override;

/**
 * Fired after {@see AccountLifecycle::suspend()} has transitioned
 * `active → suspended` and mirrored `root_account_events` (B-11 §5.1). Built
 * with an explicit {@see TenantContext} — see {@see TrialStarted}'s
 * docblock for why.
 */
final class AccountSuspended extends DomainEvent
{
    public readonly string $accountId;

    public function __construct(
        AccountRef $account,
        public readonly string $reason,
    ) {
        parent::__construct(new TenantContext($account));
        $this->accountId = $account->id;
    }

    #[Override]
    public static function name(): string
    {
        return 'account.suspended';
    }
}
