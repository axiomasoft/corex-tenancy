<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Lifecycle;

use RuntimeException;

/**
 * Pure transition-validation for `root_accounts.status` (B-11 §5.1). Single
 * source of truth for the state graph — {@see AccountLifecycle} is the only
 * consumer allowed to mutate `root_accounts.status`, and every mutation
 * routes through {@see assertTransition()} first.
 *
 * pending → provisioning → trial → active
 * trial → grace
 * active → suspended → (active | grace)
 * active → grace
 * grace → (active | exporting)
 * exporting → deleted
 *
 * @internal spec: B-11 §5.1, P2.12
 */
final class AccountLifecycleFsm
{
    /**
     * @var array<string, list<string>>
     */
    private const array TRANSITIONS = [
        'pending' => ['provisioning'],
        'provisioning' => ['trial'],
        'trial' => ['active', 'grace'],
        'active' => ['suspended', 'grace'],
        'suspended' => ['active', 'grace'],
        'grace' => ['active', 'exporting'],
        'exporting' => ['deleted'],
    ];

    public function assertTransition(string $from, string $to): void
    {
        if (! $this->allows($from, $to)) {
            throw new RuntimeException("Invalid account lifecycle transition: [{$from}] → [{$to}].");
        }
    }

    public function allows(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], strict: true);
    }
}
