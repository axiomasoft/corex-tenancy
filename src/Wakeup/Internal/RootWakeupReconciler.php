<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Wakeup\Internal;

use CoreX\Tenancy\AccountRef;
use CoreX\Tenancy\Contracts\TenancyManager;
use CoreX\Tenancy\TenantContext;
use CoreX\Wakeup\Contracts\WakeupSweeper;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

/**
 * Claims one root hint at a time and invokes the existing tenant-local
 * sweeper through TenancyManager. It never constructs a tenant DSN itself.
 */
final class RootWakeupReconciler
{
    public function __construct(
        private readonly Connection $root,
        private readonly TenancyManager $tenancy,
        private readonly WakeupSweeper $sweeper,
        private readonly int $leaseSeconds,
    ) {}

    public function sweep(int $limit): int
    {
        if ($limit < 1 || $limit > 1_000) {
            throw new RuntimeException('Root wakeup sweep limit must be between 1 and 1000.');
        }

        $swept = 0;

        while ($swept < $limit) {
            $claim = $this->claimNext();

            if ($claim === null) {
                return $swept;
            }

            $this->runClaim($claim);
            $swept++;
        }

        return $swept;
    }

    /**
     * Rebuilds advisory root hints from every eligible account in one bounded
     * page. Its root-local cursor and bounded retry state deliberately have no
     * Redis or root-hint-table dependency.
     *
     * @return array{processed: int, next_cursor: string|null}
     */
    public function reconcile(int $limit, ?string $afterAccountId = null): array
    {
        if ($limit < 1 || $limit > 1_000) {
            throw new RuntimeException('Root wakeup reconciliation limit must be between 1 and 1000.');
        }

        $state = $this->root->transaction(function (): object {
            $state = $this->root->table('root_wakeup_reconciliation_state')
                ->where('name', 'default')
                ->lockForUpdate()
                ->first();

            if ($state !== null) {
                return $state;
            }

            $now = now();
            $this->root->table('root_wakeup_reconciliation_state')->insert([
                'name' => 'default',
                'cursor_account_id' => null,
                'attempts' => 0,
                'retry_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            return $this->root->table('root_wakeup_reconciliation_state')->where('name', 'default')->firstOrFail();
        });

        if ($state->retry_at !== null && new DateTimeImmutable((string) $state->retry_at) > new DateTimeImmutable('now')) {
            return ['processed' => 0, 'next_cursor' => $state->cursor_account_id];
        }

        $cursor = $afterAccountId ?? $state->cursor_account_id;
        $accounts = $this->root->table('root_accounts')
            ->where('status', 'active')
            ->when($cursor !== null, static fn ($query) => $query->where('id', '>', $cursor))
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'slug', 'status']);
        $hint = new RootRegistryWakeupHint(root: $this->root);
        $cursor = null;

        foreach ($accounts as $account) {
            $accountRef = new AccountRef(
                id: (string) $account->id,
                slug: (string) $account->slug,
                status: (string) $account->status,
                features: [],
                limits: [],
            );
            $context = new TenantContext(account: $accountRef);

            try {
                $nextFireAt = $this->tenancy->runFor(
                    account: $accountRef,
                    workspace: null,
                    callback: fn (): ?DateTimeImmutable => $this->sweeper->nextDue(context: $context),
                );
            } catch (Throwable) {
                $attempts = (int) $state->attempts + 1;
                $this->root->table('root_wakeup_reconciliation_state')->where('name', 'default')->update([
                    'attempts' => $attempts,
                    'retry_at' => now()->addSeconds(min(300, 2 ** min($attempts, 8))),
                    'updated_at' => now(),
                ]);

                return ['processed' => 0, 'next_cursor' => $cursor];
            }

            if ($nextFireAt !== null) {
                $hint->scheduled(context: $context, fireAt: $nextFireAt);
            }

            $cursor = $accountRef->id;
        }

        $nextCursor = $accounts->count() === $limit ? $cursor : null;
        $this->root->table('root_wakeup_reconciliation_state')->where('name', 'default')->update([
            'cursor_account_id' => $nextCursor,
            'attempts' => 0,
            'retry_at' => null,
            'updated_at' => now(),
        ]);

        return ['processed' => $accounts->count(), 'next_cursor' => $nextCursor];
    }

    /** @return array{account: AccountRef, revision: int, epoch: int}|null */
    private function claimNext(): ?array
    {
        return $this->root->transaction(function (): ?array {
            while (true) {
                $now = now();
                $hint = $this->root->table('root_wakeup_hints')
                    ->where('next_fire_at', '<=', $now)
                    ->where(static fn ($query) => $query->whereNull('leased_until')->orWhere('leased_until', '<=', $now))
                    ->orderBy('next_fire_at')
                    ->lockForUpdate()
                    ->first();

                if ($hint === null) {
                    return null;
                }

                $account = $this->root->table('root_accounts')->where('id', $hint->account_id)->first();

                if ($account === null) {
                    $this->root->table('root_wakeup_hints')->where('account_id', $hint->account_id)->delete();

                    continue;
                }

                $epoch = (int) $hint->claim_epoch + 1;
                $this->root->table('root_wakeup_hints')
                    ->where('account_id', $hint->account_id)
                    ->where('hint_revision', $hint->hint_revision)
                    ->where('claim_epoch', $hint->claim_epoch)
                    ->update([
                        'claim_epoch' => $epoch,
                        'leased_until' => $now->copy()->addSeconds($this->leaseSeconds),
                        'updated_at' => $now,
                    ]);

                return [
                    'account' => new AccountRef(
                        id: (string) $account->id,
                        slug: (string) $account->slug,
                        status: (string) $account->status,
                        features: [],
                        limits: [],
                    ),
                    'revision' => (int) $hint->hint_revision,
                    'epoch' => $epoch,
                ];
            }
        });
    }

    /** @param array{account: AccountRef, revision: int, epoch: int} $claim */
    private function runClaim(array $claim): void
    {
        $nextFireAt = $this->tenancy->runFor(
            account: $claim['account'],
            workspace: null,
            callback: function () use ($claim): ?DateTimeImmutable {
                $context = new TenantContext(account: $claim['account']);
                $this->sweeper->sweep(context: $context, limit: 100);

                return $this->sweeper->nextDue(context: $context);
            },
        );

        $this->root->transaction(function () use ($claim, $nextFireAt): void {
            $query = $this->root->table('root_wakeup_hints')
                ->where('account_id', $claim['account']->id)
                ->where('hint_revision', $claim['revision'])
                ->where('claim_epoch', $claim['epoch']);

            if ($nextFireAt === null) {
                $query->delete();

                return;
            }

            $query->update([
                'next_fire_at' => $nextFireAt,
                'leased_until' => null,
                'updated_at' => now(),
            ]);
        });
    }
}
