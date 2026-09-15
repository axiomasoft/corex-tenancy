<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Jobs;

use CoreX\Tenancy\Models\Account;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Provisioning pipeline step (`TenantCreated ⇒ JobPipeline::make([self::class])`,
 * R-11 §2.3 п.5) — deliberately WITHOUT `MigrateDatabase`/`SeedDatabase`,
 * the schema already lives in the golden template. Idempotent: a second run
 * against an already-provisioned account no-ops (replayable pipeline,
 * premortem B4) instead of re-cloning or erroring.
 */
final class CreateDatabaseFromTemplate implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Account $tenant,
    ) {}

    public function handle(): bool
    {
        $manager = $this->tenant->database()->manager();
        $name = $this->tenant->database()->getName();

        if ($manager->databaseExists($name)) {
            return true;
        }

        return $manager->createDatabase($this->tenant);
    }
}
