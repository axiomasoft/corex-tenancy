<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Migrations;

use Closure;
use CoreX\Tenancy\AccountRef;
use CoreX\Tenancy\Contracts\TenancyManager;
use CoreX\Tenancy\Models\AccountSchemaState;
use CoreX\Tenancy\Models\MigrationBatch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs a `root_migration_batches` wave across its target accounts,
 * sequentially (B-11 §5.6, P2.12) — synchronous/injectable so it's testable
 * without a real queue worker. `-p`/`--skip-failing` (see
 * `TenantsMigrateCommand`) are accepted pass-through flags only: this
 * orchestrator already never aborts a batch on a single account's failure
 * (AC-11), and real parallel dispatch is Scope Excluded (B-15 DDL-worker
 * coordination — only the advisory-lock CALL itself is required, AC-12).
 *
 * Batch duration budgets / rate-limiting / `CREATE INDEX CONCURRENTLY`
 * guidance are operational concerns for the migration-file author, not
 * something this class enforces — deliberately out of scope.
 */
final class MigrationWaveOrchestrator
{
    /**
     * @param  (Closure(AccountRef): void)|null  $migrationRunner
     */
    public function __construct(
        private readonly string $centralConnection,
        private readonly TenancyManager $tenancy,
        private readonly ?Closure $migrationRunner = null,
    ) {}

    public function runBatch(string $batchId, int $chunkSize = 5): void
    {
        /** @var MigrationBatch $batch */
        $batch = MigrationBatch::query()->findOrFail($batchId);

        $batch->forceFill(['status' => 'running', 'started_at' => $batch->started_at ?? now()])->save();

        foreach ($this->targetAccountStates($batch)->chunk($chunkSize) as $chunk) {
            /** @var Collection<int, AccountSchemaState> $chunk */
            foreach ($chunk as $state) {
                $this->migrateOne($state, $batch);
            }
        }

        $batch->forceFill(['status' => 'done', 'finished_at' => now()])->save();
    }

    /**
     * @return Collection<int, AccountSchemaState>
     */
    private function targetAccountStates(MigrationBatch $batch): Collection
    {
        $accountsWithoutState = DB::connection($this->centralConnection)
            ->table('root_accounts')
            ->whereNull('deleted_at')
            ->whereNotIn('id', AccountSchemaState::query()->select('account_id'))
            ->pluck('id');

        foreach ($accountsWithoutState as $accountId) {
            AccountSchemaState::query()->create([
                'account_id' => (string) $accountId,
                'current_version' => 0,
                'target_version' => $batch->to_version,
                'status' => 'ok',
                'batch_id' => $batch->id,
            ]);
        }

        AccountSchemaState::query()
            ->where('target_version', '<', $batch->to_version)
            ->update(['target_version' => $batch->to_version, 'batch_id' => $batch->id]);

        return AccountSchemaState::query()
            ->where('target_version', $batch->to_version)
            ->where('current_version', '<', $batch->to_version)
            ->orderBy('account_id')
            ->get();
    }

    private function migrateOne(AccountSchemaState $state, MigrationBatch $batch): void
    {
        $wasAlreadyFailed = $state->status === 'failed';

        $state->forceFill(['status' => 'migrating'])->save();

        $accountRow = DB::connection($this->centralConnection)->table('root_accounts')->where('id', $state->account_id)->first();

        if ($accountRow === null) {
            $state->forceFill(['status' => $wasAlreadyFailed ? 'blocked' : 'failed', 'last_error' => 'account not found in root_accounts'])->save();

            return;
        }

        $accountRef = new AccountRef(
            id: (string) $accountRow->id,
            slug: (string) ($accountRow->slug ?? ''),
            status: (string) $accountRow->status,
            features: [],
            limits: [],
        );

        try {
            $this->tenancy->runFor($accountRef, null, function () use ($accountRef): void {
                $runner = $this->migrationRunner ?? self::defaultMigrationRunner();
                $runner($accountRef);
            });

            $state->forceFill([
                'current_version' => $batch->to_version,
                'status' => 'ok',
                'last_migrated_at' => now(),
                'last_error' => null,
            ])->save();
        } catch (Throwable $e) {
            $state->forceFill([
                'status' => $wasAlreadyFailed ? 'blocked' : 'failed',
                'last_error' => $e->getMessage(),
            ])->save();
        }
    }

    /**
     * @return Closure(AccountRef): void
     */
    private static function defaultMigrationRunner(): Closure
    {
        // $account is not needed by the default runner itself (it acts on
        // whichever account TenancyManager::runFor() already switched the
        // 'tenant' connection to) — kept in the signature only so a
        // caller-supplied $migrationRunner can log/branch on it.
        return static function (AccountRef $account): void {
            DB::connection('tenant')->transaction(static function () use ($account): void {
                // AC-12 — the advisory-lock CALL is the actual requirement
                // here (B-15 DDL-worker coordination itself is Scope
                // Excluded); `pg_advisory_xact_lock` auto-releases at
                // transaction end.
                DB::connection('tenant')->select("SELECT pg_advisory_xact_lock(hashtext('fld_ddl')) /* account:{$account->id} */");

                Artisan::call('migrate', [
                    '--database' => 'tenant',
                    '--path' => realpath(__DIR__.'/../../database/migrations/tenant'),
                    '--realpath' => true,
                    '--force' => true,
                ]);
            });
        };
    }
}
