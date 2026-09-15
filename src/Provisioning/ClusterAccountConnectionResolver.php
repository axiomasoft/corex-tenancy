<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Provisioning;

use CoreX\Tenancy\AccountRef;
use CoreX\Tenancy\Contracts\AccountConnectionResolver;
use CoreX\Tenancy\DatabaseConnectionConfig;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Builds the tenant connection config from `root_clusters`+`db_name` (AC-9):
 * every call re-reads both tables, so a `cluster_id` cell-migration on
 * `root_accounts` changes the resolved connection without a deploy.
 */
final class ClusterAccountConnectionResolver implements AccountConnectionResolver
{
    public function __construct(
        private readonly string $centralConnection,
    ) {}

    public function resolve(AccountRef $account): DatabaseConnectionConfig
    {
        $row = DB::connection($this->centralConnection)
            ->table('root_accounts')
            ->where('id', $account->id)
            ->select(['db_name', 'cluster_id'])
            ->first();

        if ($row === null) {
            throw new RuntimeException("Account [{$account->id}] not found in root_accounts.");
        }

        $cluster = DB::connection($this->centralConnection)
            ->table('root_clusters')
            ->where('id', $row->cluster_id)
            ->select(['pgbouncer_host', 'pgbouncer_port', 'app_db_user'])
            ->first();

        if ($cluster === null) {
            throw new RuntimeException("Cluster [{$row->cluster_id}] not found in root_clusters.");
        }

        return new DatabaseConnectionConfig(
            driver: 'pgsql',
            host: $cluster->pgbouncer_host,
            port: (int) $cluster->pgbouncer_port,
            database: $row->db_name,
            username: $cluster->app_db_user,
        );
    }
}
