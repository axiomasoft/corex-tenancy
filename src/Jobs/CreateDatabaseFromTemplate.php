<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Jobs;

use CoreX\Tenancy\Database\TemplateIntegrityVerifier;
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

    public function handle(TemplateIntegrityVerifier $templateIntegrityVerifier): bool
    {
        $tenant = Account::on((string) config('tenancy.central_connection'))->findOrFail($this->tenant->id);

        $templateIntegrityVerifier->assertCurrent(
            version: (int) $tenant->template_version,
            clusterId: (string) $tenant->cluster_id,
        );

        $manager = $tenant->database()->manager();
        $name = $tenant->database()->getName();

        if ($manager->databaseExists($name)) {
            $templateIntegrityVerifier->assertAccount($tenant);

            return true;
        }

        return $manager->createDatabase($tenant);
    }
}
