<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Wakeup\Internal;

use CoreX\Tenancy\TenantContext;
use CoreX\Wakeup\Contracts\WakeupHint;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Best-effort root routing hint. The tenant request remains authoritative;
 * losing this table merely makes the root process discover work later.
 */
final class RootRegistryWakeupHint implements WakeupHint
{
    public function __construct(
        private readonly Connection $root,
    ) {}

    public function scheduled(TenantContext $context, DateTimeImmutable $fireAt): void
    {
        try {
            $this->root->transaction(function () use ($context, $fireAt): void {
                $hint = $this->root->table('root_wakeup_hints')
                    ->where('account_id', $context->account->id)
                    ->lockForUpdate()
                    ->first();
                $now = now();

                if ($hint === null) {
                    $this->root->table('root_wakeup_hints')->insert([
                        'account_id' => $context->account->id,
                        'next_fire_at' => $fireAt,
                        'hint_revision' => 1,
                        'leased_until' => null,
                        'claim_epoch' => 0,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    return;
                }

                $currentFireAt = new DateTimeImmutable((string) $hint->next_fire_at);

                $this->root->table('root_wakeup_hints')
                    ->where('account_id', $context->account->id)
                    ->where('hint_revision', $hint->hint_revision)
                    ->update([
                        'next_fire_at' => min($currentFireAt, $fireAt),
                        'hint_revision' => (int) $hint->hint_revision + 1,
                        'updated_at' => $now,
                    ]);
            });
        } catch (Throwable $exception) {
            // A hint only accelerates discovery. The tenant's committed request
            // remains the source of truth and reconciliation can recreate it.
            Log::warning('Unable to publish CoreX root wakeup hint.', [
                'account_id' => $context->account->id,
                'exception' => $exception,
            ]);
        }
    }
}
