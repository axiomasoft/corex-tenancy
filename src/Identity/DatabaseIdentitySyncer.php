<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Identity;

use Carbon\CarbonImmutable;
use CoreX\Tenancy\AccountRef;
use CoreX\Tenancy\Contracts\TenancyManager;
use CoreX\Tenancy\Events\UserSynced;
use CoreX\Tenancy\Jobs\SyncIdentityToAccount;
use CoreX\Tenancy\Models\Account;
use CoreX\Tenancy\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Reads `root_identities` (central) and writes the denormalized `users`
 * projection inside the target account's tenant database (B-11 §2.2/§3.2,
 * D4/C6). A version-guard (premortem C3/C6) skips the write once the
 * account's projection is already at least as fresh as the source, so two
 * concurrent {@see fanOut()} waves converge on the latest state instead of
 * an older job clobbering a newer one. Any failure — missing identity,
 * missing account, a tenant-context error — is swallowed into
 * `sync_status=failed` (AC-16 г): retried later by `identities:reconcile`,
 * never left to throw across the whole fan-out.
 */
final class DatabaseIdentitySyncer implements IdentitySyncer
{
    public function __construct(
        private readonly string $centralConnection,
        private readonly TenancyManager $tenancy,
    ) {}

    public function syncToAccount(string $identityId, string $accountId): void
    {
        $identity = DB::connection($this->centralConnection)
            ->table('root_identities')
            ->where('id', $identityId)
            ->first();

        $account = Account::on($this->centralConnection)->find($accountId);

        if ($identity === null || $account === null) {
            $this->markFailed($identityId, $accountId);

            return;
        }

        $membership = DB::connection($this->centralConnection)
            ->table('root_identity_accounts')
            ->where('identity_id', $identityId)
            ->where('account_id', $accountId)
            ->first();

        $accountRef = new AccountRef(
            id: (string) $account->id,
            slug: (string) ($account->slug ?? ''),
            status: (string) $account->status,
            features: [],
            limits: [],
        );

        try {
            $this->tenancy->runFor($accountRef, null, function () use ($identity, $identityId, $accountId): void {
                DB::connection()->transaction(function () use ($identity, $identityId, $accountId): void {
                    // The lock also covers an absent user row, serializing first delivery.
                    DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['corex-identity:'.$identityId]);
                    $existing = User::withTrashed()->whereKey($identityId)->first();
                    $sourceUpdatedAt = CarbonImmutable::parse($identity->updated_at);
                    $isMembershipActive = DB::connection($this->centralConnection)->table('root_identity_accounts')
                        ->where('identity_id', $identityId)->where('account_id', $accountId)->value('status') === 'active';

                    if ($existing !== null
                        && $existing->identity_source_updated_at !== null
                        && $sourceUpdatedAt->lessThanOrEqualTo($existing->identity_source_updated_at)) {
                        // Membership authority is independent of the profile clock. A
                        // revoke must apply even when the identity profile has not
                        // changed; a stale profile snapshot must still not overwrite
                        // local profile data.
                        User::query()
                            ->whereKey($identity->id)
                            ->update(['is_active' => $isMembershipActive]);

                        return;
                    }

                    User::withTrashed()->updateOrCreate(
                        attributes: ['id' => $identity->id],
                        values: [
                            'email' => $identity->email,
                            'name' => $identity->name,
                            'phone' => $identity->phone,
                            'locale' => $identity->locale,
                            'is_active' => $isMembershipActive,
                            'synced_at' => CarbonImmutable::now(),
                            'identity_source_updated_at' => $sourceUpdatedAt->format('Y-m-d H:i:s.uP'),
                        ],
                    );

                    DB::afterCommit(static fn () => event(new UserSynced(identityId: $identityId, accountId: $accountId)));
                });
            });
        } catch (Throwable) {
            $this->markFailed($identityId, $accountId);

            return;
        }

        if ($membership !== null) {
            DB::connection($this->centralConnection)
                ->table('root_identity_accounts')
                ->where('identity_id', $identityId)
                ->where('account_id', $accountId)
                ->where('updated_at', $membership->updated_at)
                ->whereExists(static fn ($query) => $query->selectRaw('1')->from('root_identities')
                    ->where('id', $identityId)->where('updated_at', $identity->updated_at))
                ->update(['sync_status' => 'synced', 'synced_at' => CarbonImmutable::now()]);
        }
    }

    public function fanOut(string $identityId): void
    {
        $accountIds = DB::connection($this->centralConnection)
            ->table('root_identity_accounts')
            ->where('identity_id', $identityId)
            ->pluck('account_id');

        foreach ($accountIds as $accountId) {
            SyncIdentityToAccount::dispatch($identityId, (string) $accountId);
        }
    }

    private function markFailed(string $identityId, string $accountId): void
    {
        DB::connection($this->centralConnection)
            ->table('root_identity_accounts')
            ->where('identity_id', $identityId)
            ->where('account_id', $accountId)
            ->update(['sync_status' => 'failed']);
    }
}
