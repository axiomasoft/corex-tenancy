<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// root_* — control-plane DDL on the CENTRAL connection, never a tenant DB
// (D14). Run via `migrate --database=<central> --path=.../migrations/root`,
// same pattern as packages/core's sys_* pg-only migrations (D33/A19/A62).
// See DB_SCHEMA.md §3.1 root_clusters for the column-by-column source.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('root_clusters', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 64)->unique();
            $table->string('region', 32)->default('ru-central');
            $table->string('status', 16)->default('active');
            $table->string('pg_host_writer', 255);
            $table->string('pg_host_reader', 255)->nullable();
            $table->integer('pg_port')->default(5432);
            $table->string('pgbouncer_host', 255);
            $table->integer('pgbouncer_port')->default(6432);
            $table->string('app_db_user', 64);
            $table->string('tei_endpoint', 255)->nullable();
            $table->string('backup_repo', 255)->nullable();
            $table->integer('db_count')->default(0);
            $table->integer('db_soft_limit')->default(700);
            $table->integer('pending_pool_size')->default(20);
            $table->integer('template_version')->default(0);
            $table->jsonb('meta')->default('{}');
            $table->timestampTz('created_at', precision: 6)->useCurrent();
            $table->timestampTz('updated_at', precision: 6)->useCurrent();
        });

        DB::statement("ALTER TABLE root_clusters ADD CONSTRAINT root_clusters_status_ck CHECK (status IN ('provisioning','active','draining','readonly','retired'))");

        DB::statement("COMMENT ON TABLE root_clusters IS 'PG-ячейки (шарды) парка — capacity/routing control plane (B-11 §2.1)'");
        DB::statement("COMMENT ON COLUMN root_clusters.app_db_user IS 'Один PG-юзер на ячейку: пулы PgBouncer = кол-ву БД, не БД×юзеров (R-15)'");
    }

    public function down(): void
    {
        Schema::dropIfExists('root_clusters');
    }
};
