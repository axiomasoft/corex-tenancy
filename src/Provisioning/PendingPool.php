<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Provisioning;

use CoreX\Tenancy\Database\TemplateIntegrityVerifier;
use CoreX\Tenancy\Models\Account;
use CoreX\Tenancy\ProvisionParams;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pending-pool mechanics (B-11 §5.2, AC-4/AC-5): keeps `pending_pool_size`
 * pre-cloned account databases per cell so signup never waits on
 * `CREATE DATABASE … TEMPLATE` (p95 < 3с). Pending болванки carry
 * `status='pending'`, `slug`/`owner_identity_id` NULL — the frozen
 * DB_SCHEMA table-CHECK on `root_accounts` already allows that state
 * (D14/P2.2), so no `pending_since` column (stancl's own `HasPending`
 * trait) is needed: `status` alone encodes "not yet claimed".
 *
 * Claiming (premortem A6): {@see pullPending()} flips `status`
 * `pending → provisioning` inside a `SELECT … FOR UPDATE SKIP LOCKED`
 * transaction — two concurrent callers never claim the same row. `slug`/
 * `owner_identity_id` stay NULL: the full pending→active transition (slug,
 * owner, status=trial) is P2.12's `AccountLifecycle::activateFromPool`
 * (Scope Excluded) — this class only reserves the row and lays the
 * atomicity invariant for that later transition.
 */
final class PendingPool
{
    public function __construct(
        private readonly string $centralConnection,
        private readonly StanclTenantDatabaseProvisioner $provisioner,
        private readonly TemplateIntegrityVerifier $templateIntegrityVerifier,
    ) {}

    /**
     * Create $count pending болванки on $clusterId (least-loaded active
     * cluster per call if omitted), cloning each database synchronously
     * from the cell's current ready template (D24 parity gate).
     *
     * @return list<string> created account ids
     */
    public function createPending(int $count, ?string $clusterId = null): array
    {
        $created = [];

        for ($i = 0; $i < $count; $i++) {
            $created[] = $this->cloneOne($clusterId, 'pending');
        }

        return $created;
    }

    /**
     * Number of pending (unclaimed) болванки, optionally scoped to one
     * cluster — used by the pending-create command to top up to
     * `root_clusters.pending_pool_size`.
     */
    public function pendingCount(?string $clusterId = null): int
    {
        $query = DB::connection($this->centralConnection)
            ->table('root_accounts')
            ->where('status', 'pending');

        if ($clusterId !== null) {
            $query->where('cluster_id', $clusterId);
        }

        return $query->count();
    }

    /**
     * Claim a pending account from the least-loaded cell (`ORDER BY
     * db_count`); an empty pool falls back to a synchronous clone
     * (< 10с, AC-5).
     */
    public function pullPending(): Account
    {
        $claimedId = DB::connection($this->centralConnection)->transaction(function (): ?string {
            $candidate = DB::connection($this->centralConnection)
                ->table('root_accounts as ra')
                ->join('root_clusters as rc', 'rc.id', '=', 'ra.cluster_id')
                ->where('ra.status', 'pending')
                ->orderBy('rc.db_count')
                ->select('ra.id')
                ->lock('for update of ra skip locked')
                ->first();

            if ($candidate === null) {
                return null;
            }

            $account = Account::on($this->centralConnection)->findOrFail($candidate->id);
            $this->templateIntegrityVerifier->assertAccount($account);

            DB::connection($this->centralConnection)
                ->table('root_accounts')
                ->where('id', $candidate->id)
                ->update(['status' => 'provisioning']);

            return $candidate->id;
        });

        $id = $claimedId ?? $this->cloneOne(null, 'provisioning');

        return Account::on($this->centralConnection)->findOrFail($id);
    }

    /**
     * Delete stale pending болванки — DROP DATABASE + DELETE root_accounts
     * — either by `template_version` (schema drifted from a new golden
     * template) and/or by age (`created_at` older than $olderThan). At
     * least one filter is required: an unfiltered clear would nuke the
     * whole pool.
     *
     * @return int number of болванки cleared
     */
    public function clearPending(?int $templateVersion = null, ?DateTimeInterface $olderThan = null): int
    {
        if ($templateVersion === null && $olderThan === null) {
            throw new RuntimeException('pending-clear requires --template-version and/or --older-than.');
        }

        if (DB::connection($this->centralConnection)->transactionLevel() !== 0) {
            throw new RuntimeException('Pending cleanup must run outside a transaction.');
        }

        $query = DB::connection($this->centralConnection)
            ->table('root_accounts')
            ->where(fn ($query) => $query->where('status', 'pending')
                ->orWhere(fn ($query) => $query->where('status', 'provisioning')->where('data->pool_cleanup', true)));

        if ($templateVersion !== null) {
            $query->where('template_version', $templateVersion);
        }

        if ($olderThan !== null) {
            $query->where('created_at', '<', $olderThan);
        }

        $stale = $query->get(['id', 'db_name', 'cluster_id']);
        $cleared = 0;

        foreach ($stale as $row) {
            // A committed claim removes the row from signup selection before external DDL.
            // The marker makes a failed DROP recoverable by the next cleanup run.
            $claimed = DB::connection($this->centralConnection)->table('root_accounts')
                ->where('id', $row->id)
                ->where(fn ($query) => $query->where('status', 'pending')
                    ->orWhere(fn ($query) => $query->where('status', 'provisioning')->where('data->pool_cleanup', true)))
                ->update(['status' => 'provisioning', 'data' => DB::raw("jsonb_set(CASE WHEN jsonb_typeof(data) = 'object' THEN data ELSE '{}'::jsonb END, '{pool_cleanup}', 'true')")]);

            if ($claimed !== 1) {
                continue;
            }

            DB::connection($this->centralConnection)->statement('DROP DATABASE IF EXISTS "'.$row->db_name.'"');

            DB::connection($this->centralConnection)->transaction(function () use ($row, &$cleared): void {
                $deleted = DB::connection($this->centralConnection)
                    ->table('root_accounts')
                    ->where('id', $row->id)
                    ->where('status', 'provisioning')
                    ->where('data->pool_cleanup', true)
                    ->delete();

                if ($deleted === 0) {
                    return;
                }

                DB::connection($this->centralConnection)
                    ->table('root_clusters')
                    ->where('id', $row->cluster_id)
                    ->decrement('db_count');
                $cleared++;
            });
        }

        return $cleared;
    }

    private function cloneOne(?string $clusterId, string $status): string
    {
        $cluster = $clusterId !== null
            ? $this->clusterRow($clusterId)
            : $this->leastLoadedActiveCluster();

        $template = $this->readyTemplateFor((int) $cluster->template_version);

        $this->templateIntegrityVerifier->assertCurrent(
            version: (int) $template->version,
            clusterId: (string) $cluster->id,
        );

        $id = (string) Str::uuid7();
        $dbName = 'acc_'.str_replace('-', '', $id);

        Account::on($this->centralConnection)->create([
            'id' => $id,
            'cluster_id' => $cluster->id,
            'db_name' => $dbName,
            'status' => 'provisioning',
            'template_version' => $template->version,
            'embedding_model' => $template->embedding_model,
            'embedding_dim' => $template->embedding_dim,
        ]);

        $this->provisioner->provision(new ProvisionParams(
            accountId: $id,
            clusterId: $cluster->id,
            templateVersion: (int) $template->version,
            embeddingModel: $template->embedding_model,
            embeddingDim: (int) $template->embedding_dim,
        ));

        DB::connection($this->centralConnection)->transaction(function () use ($cluster, $id, $status): void {
            DB::connection($this->centralConnection)->table('root_accounts')
                ->where('id', $id)->update(['status' => $status]);

            DB::connection($this->centralConnection)
                ->table('root_clusters')
                ->where('id', $cluster->id)
                ->increment('db_count');
        });

        return $id;
    }

    private function clusterRow(string $clusterId): object
    {
        $cluster = DB::connection($this->centralConnection)
            ->table('root_clusters')
            ->where('id', $clusterId)
            ->first();

        if ($cluster === null) {
            throw new RuntimeException("Cluster [{$clusterId}] not found in root_clusters.");
        }

        return $cluster;
    }

    private function leastLoadedActiveCluster(): object
    {
        $cluster = DB::connection($this->centralConnection)
            ->table('root_clusters')
            ->where('status', 'active')
            ->orderBy('db_count')
            ->first();

        if ($cluster === null) {
            throw new RuntimeException('No active cluster available in root_clusters.');
        }

        return $cluster;
    }

    private function readyTemplateFor(int $version): object
    {
        $template = DB::connection($this->centralConnection)
            ->table('root_templates')
            ->where('version', $version)
            ->first();

        if ($template === null || $template->status !== 'ready') {
            throw new RuntimeException("Template version [{$version}] is not ready for cloning (template-parity gate, D24).");
        }

        return $template;
    }
}
