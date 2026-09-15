<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Identity;

use Carbon\CarbonImmutable;
use CoreX\Tenancy\AccountRef;
use CoreX\Tenancy\Contracts\TenancyManager;
use CoreX\Tenancy\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

/** @internal Unregistered management-only reference; never a membership grant. */
final readonly class ManagementIdentityHydrator
{
    public function __construct(
        private string $centralConnection,
        private TenancyManager $tenancy,
        private string $managementScope,
    ) {}

    public function hydrate(string $identityId, string $accountId): void
    {
        $this->assertManagement();
        $this->assertUuid($identityId);
        $this->assertUuid($accountId);
        $root = DB::connection($this->centralConnection);
        $account = $root->table('root_accounts')->where('id', $accountId)->first();

        if ($account === null) {
            $this->bookkeep($identityId, $accountId, 'failed');

            return;
        }

        $identity = $root->table('root_identities')->where('id', $identityId)->first();
        $membership = $root->table('root_identity_accounts')
            ->where('identity_id', $identityId)->where('account_id', $accountId)->first();
        $deny = $identity === null || ($identity->deleted_at ?? null) !== null
            || $account->status !== 'active' || ($account->deleted_at ?? null) !== null
            || $membership?->status !== 'active' || ($membership->deleted_at ?? null) !== null;
        $ref = new AccountRef(id: $accountId, slug: (string) ($account->slug ?? ''), status: (string) $account->status, features: [], limits: []);

        try {
            $this->tenancy->runFor(account: $ref, workspace: null, callback: function () use ($identityId, $identity, $deny): void {
                // Commit deny independently: a subsequent profile collision must
                // never roll back deactivation. No credentials/lease/fences touched.
                if ($deny) {
                    User::withTrashed()->whereKey($identityId)->update(['is_active' => false]);
                }

                if ($identity === null || ($identity->deleted_at ?? null) !== null) {
                    return;
                }

                DB::connection()->transaction(function () use ($identityId, $identity): void {
                    $existing = User::withTrashed()->lockForUpdate()->find($identityId);

                    if ($existing?->trashed()) {
                        $existing->is_active = false;
                        $existing->save();

                        return;
                    }

                    $sourceTime = CarbonImmutable::parse($identity->updated_at);

                    if ($existing?->synced_at !== null && $sourceTime->lessThanOrEqualTo($existing->synced_at)) {
                        return;
                    }

                    $values = [
                        'email' => $identity->email,
                        'name' => $identity->name,
                        'phone' => $identity->phone,
                        'locale' => $identity->locale,
                        'synced_at' => $sourceTime,
                    ];

                    if ($existing === null) {
                        User::query()->create(['id' => $identityId, 'is_active' => false, ...$values]);

                        return;
                    }

                    // UUID is the only key. Unique-email failures cannot merge or
                    // rekey another row, and hydration never enables a user.
                    $existing->fill($values)->save();
                });
            });
        } catch (Throwable) {
            $this->bookkeep($identityId, $accountId, 'failed');

            return;
        }

        $this->bookkeep($identityId, $accountId, $identity === null ? 'failed' : 'synced');
    }

    public function fanOut(string $identityId): void
    {
        $this->assertManagement();
        $this->assertUuid($identityId);
        $ids = DB::connection($this->centralConnection)->table('root_identity_accounts')
            ->where('identity_id', $identityId)->pluck('account_id');

        foreach ($ids as $id) {
            try {
                $this->hydrate(identityId: $identityId, accountId: (string) $id);
            } catch (Throwable) {
                $this->bookkeep($identityId, (string) $id, 'failed');
            }
        }
    }

    private function bookkeep(string $identityId, string $accountId, string $status): void
    {
        $this->assertManagement();
        DB::connection($this->centralConnection)->table('root_identity_accounts')
            ->where('identity_id', $identityId)->where('account_id', $accountId)
            ->update(['sync_status' => $status]);
    }

    private function assertManagement(): void
    {
        if ($this->managementScope !== 'management-root' || $this->tenancy->initialized() || $this->tenancy->context() !== null) {
            throw new DomainException('Management identity hydration requires trusted root scope.');
        }
    }

    private function assertUuid(string $id): void
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id) !== 1) {
            throw new DomainException('Identity and account must be canonical UUIDs.');
        }
    }
}
