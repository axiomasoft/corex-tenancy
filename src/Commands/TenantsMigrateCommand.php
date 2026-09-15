<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Commands;

use CoreX\Tenancy\Migrations\MigrationWaveOrchestrator;
use Illuminate\Console\Command;

/**
 * Runs one `root_migration_batches` wave (B-11 §5.6, P2.12). `-p`/
 * `--skip-failing` are accepted pass-through flags, logged only —
 * {@see MigrationWaveOrchestrator::runBatch()} already never aborts a batch
 * on a single account's failure (AC-11); real parallel worker dispatch is
 * Scope Excluded.
 */
final class TenantsMigrateCommand extends Command
{
    protected $signature = 'tenants:migrate {--batch=} {--p=8} {--skip-failing}';

    protected $description = 'Run a migration-wave batch across its target accounts.';

    public function handle(MigrationWaveOrchestrator $orchestrator): int
    {
        $batchId = $this->option('batch');

        if (! is_string($batchId) || $batchId === '') {
            $this->components->error('--batch=<root_migration_batches.id> is required.');

            return self::FAILURE;
        }

        $this->components->info(sprintf(
            'tenants:migrate batch=%s -p=%s skip-failing=%s',
            $batchId,
            (string) $this->option('p'),
            $this->option('skip-failing') ? 'yes' : 'no',
        ));

        $orchestrator->runBatch($batchId);

        return self::SUCCESS;
    }
}
