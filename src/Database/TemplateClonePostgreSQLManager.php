<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Database;

use CoreX\Tenancy\Models\Account;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Stancl\Tenancy\Database\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\TenantDatabaseManagers\PostgreSQLDatabaseManager;
use Throwable;

/**
 * `CREATE DATABASE … TEMPLATE "tpl_v42" STRATEGY WAL_LOG` (golden-clone
 * provisioning) instead of stancl's default `TEMPLATE=template0` — `tpl_v42`
 * here is only an illustrative name; the real template name is read from
 * `root_templates.db_name`. `STRATEGY WAL_LOG` is mandatory — PG15+
 * defaults `CREATE DATABASE … TEMPLATE` to FILE_COPY, far slower for a
 * populated golden template.
 *
 * Template-parity gate: refuses to clone a template whose
 * `root_templates.status` isn't `ready` — the FROZEN DB_SCHEMA contract
 * carries no schema-hash column, so `status` is the only available parity
 * signal.
 *
 * Quarantine handling: PG rejects `CREATE DATABASE … TEMPLATE` while any
 * backend is connected to the template DB (SQLSTATE 55006 — "source
 * database is being accessed by other users"). The golden template is
 * never meant to have live connections; this is a defensive pre-step +
 * bounded retry, not a substitute for that invariant.
 *
 * @internal spec: D24, premortem A3/B3, R-11 §2.3 п.2, F-DDL1
 */
final class TemplateClonePostgreSQLManager extends PostgreSQLDatabaseManager
{
    private const int QUARANTINE_RETRIES = 5;

    private const int QUARANTINE_BACKOFF_MS = 200;

    public function createDatabase(TenantWithDatabase $tenant): bool
    {
        assert($tenant instanceof Account);

        $name = $tenant->database()->getName();
        $this->validateParameter($name);

        $template = $this->resolveReadyTemplate((int) $tenant->template_version);
        $this->validateParameter($template->db_name);

        $cluster = $this->resolveCluster((string) $tenant->cluster_id);
        $this->validateParameter($cluster->app_db_user);

        $created = $this->cloneTemplate($name, $template->db_name);

        $this->connection()->statement("GRANT ALL PRIVILEGES ON DATABASE \"{$name}\" TO \"{$cluster->app_db_user}\"");
        $this->connection()->statement("ALTER DATABASE \"{$name}\" OWNER TO \"{$cluster->app_db_user}\"");

        return $created;
    }

    private function cloneTemplate(string $name, string $templateDbName): bool
    {
        for ($attempt = 1; $attempt <= self::QUARANTINE_RETRIES; $attempt++) {
            $this->terminateTemplateBackends($templateDbName);

            try {
                return $this->connection()->statement(
                    "CREATE DATABASE \"{$name}\" TEMPLATE \"{$templateDbName}\" STRATEGY WAL_LOG",
                );
            } catch (Throwable $e) {
                $isQuarantined = str_contains($e->getMessage(), '55006')
                    || str_contains($e->getMessage(), 'is being accessed by other users');

                if (! $isQuarantined || $attempt === self::QUARANTINE_RETRIES) {
                    throw $e;
                }

                usleep(self::QUARANTINE_BACKOFF_MS * 1000 * $attempt);
            }
        }

        throw new RuntimeException("Unreachable: template clone retries exhausted for [{$templateDbName}].");
    }

    private function terminateTemplateBackends(string $templateDbName): void
    {
        $this->connection()->statement(
            'SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = ? AND pid <> pg_backend_pid()',
            [$templateDbName],
        );
    }

    private function resolveReadyTemplate(int $version): object
    {
        $template = DB::connection(config('tenancy.central_connection'))
            ->table('root_templates')
            ->where('version', $version)
            ->first();

        if ($template === null || $template->status !== 'ready') {
            throw new RuntimeException(
                "Template version [{$version}] is not ready for cloning (template-parity gate, D24).",
            );
        }

        return $template;
    }

    private function resolveCluster(string $clusterId): object
    {
        $cluster = DB::connection(config('tenancy.central_connection'))
            ->table('root_clusters')
            ->where('id', $clusterId)
            ->first();

        if ($cluster === null) {
            throw new RuntimeException("Cluster [{$clusterId}] not found in root_clusters.");
        }

        return $cluster;
    }
}
