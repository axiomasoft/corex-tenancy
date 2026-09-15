<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Actions;

use CoreX\Tenancy\AccountRef;
use CoreX\Tenancy\Central\MembershipQuery;
use CoreX\Tenancy\Central\MembershipState;
use CoreX\Tenancy\Contracts\CentralApiClient;
use CoreX\Tenancy\Contracts\TenancyManager;
use CoreX\Tenancy\Models\Account;
use CoreX\Tenancy\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class ApplyMembershipProjection
{
    public function __construct(
        private string $centralConnection,
        private CentralApiClient $centralApi,
        private TenancyManager $tenancy,
    ) {}

    public function refresh(string $identityId, string $accountId, string $product, string $requestId): void
    {
        $account = Account::on($this->centralConnection)->find($accountId);

        if ($account === null) {
            return;
        }

        $key = 'corex:membership:'.hash('sha256', $accountId.'|'.$identityId);
        $accountRef = new AccountRef(
            id: (string) $account->id,
            slug: (string) ($account->slug ?? ''),
            status: (string) $account->status,
            features: [],
            limits: [],
        );

        Cache::lock(name: $key, seconds: 10)->block(seconds: 5, callback: function () use ($accountId, $accountRef, $identityId, $product, $requestId): void {
            try {
                $snapshot = $this->centralApi->membershipFor(new MembershipQuery(
                    identityId: $identityId,
                    accountId: $accountId,
                    product: $product,
                    requestId: $requestId,
                ));
            } catch (Throwable) {
                // A membership response is authoritative only when it is
                // authenticated, available, and matches the exact query. An
                // adapter defect is no authority either: fail closed rather
                // than leave an old active projection usable. Failures while
                // applying this deny are intentionally outside this catch so
                // the queue may retry the local write.
                $this->setActiveState(account: $accountRef, identityId: $identityId, active: false);

                return;
            }

            $this->setActiveState(account: $accountRef, identityId: $identityId, active: $snapshot->state === MembershipState::Active);
        });
    }

    private function setActiveState(AccountRef $account, string $identityId, bool $active): void
    {
        $this->tenancy->runFor(account: $account, workspace: null, callback: function () use ($active, $identityId): void {
            User::query()->whereKey($identityId)->update(['is_active' => $active]);

            if (! $active) {
                DB::table('sessions')->where('user_id', $identityId)->delete();
            }
        });
    }
}
