<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Commands;

use CoreX\Tenancy\Identity\IdentitySyncer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Control-plane sweep (B-11 §3.2): re-syncs every `root_identity_accounts`
 * row that is `failed`/`pending`, or whose `synced_at` has fallen behind its
 * identity's `updated_at` — the retry path for {@see IdentitySyncer}'s
 * per-account failure isolation (AC-16 з).
 */
final class IdentitiesReconcileCommand extends Command
{
    protected $signature = 'identities:reconcile';

    protected $description = 'Re-sync failed, pending, and stale identity projections into their account users tables.';

    public function handle(IdentitySyncer $syncer): int
    {
        $centralConnection = (string) config('tenancy.central_connection');

        $rows = DB::connection($centralConnection)
            ->table('root_identity_accounts as ria')
            ->join('root_identities as ri', 'ri.id', '=', 'ria.identity_id')
            ->where(function ($query): void {
                $query->whereIn('ria.sync_status', ['failed', 'pending'])
                    ->orWhereColumn('ria.synced_at', '<', 'ri.updated_at');
            })
            ->select('ria.identity_id', 'ria.account_id')
            ->get();

        foreach ($rows as $row) {
            $syncer->syncToAccount((string) $row->identity_id, (string) $row->account_id);
        }

        $this->components->info(sprintf('Reconciled %d identity/account pair(s).', $rows->count()));

        return self::SUCCESS;
    }
}
