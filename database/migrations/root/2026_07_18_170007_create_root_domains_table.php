<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// See DB_SCHEMA.md §3.1 root_domains for the column-by-column source.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('root_domains', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->string('host', 255);
            $table->string('type', 16);
            $table->boolean('is_primary')->default(false);
            $table->string('verification_token', 64)->nullable();
            $table->timestampTz('verified_at', precision: 6)->nullable();
            $table->string('tls_status', 16)->default('none');
            $table->string('ca_used', 32)->nullable();
            $table->timestampTz('created_at', precision: 6)->useCurrent();
            $table->timestampTz('updated_at', precision: 6)->useCurrent();
            $table->timestampTz('deleted_at', precision: 6)->nullable();

            $table->foreign('account_id')->references('id')->on('root_accounts')->cascadeOnDelete();
        });

        DB::statement("ALTER TABLE root_domains ADD CONSTRAINT root_domains_type_ck CHECK (type IN ('platform','custom'))");
        DB::statement("ALTER TABLE root_domains ADD CONSTRAINT root_domains_tls_status_ck CHECK (tls_status IN ('none','pending','issued','failed'))");

        DB::statement('CREATE UNIQUE INDEX root_domains_host_uq ON root_domains (lower(host)) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX root_domains_primary_uq ON root_domains (account_id) WHERE is_primary AND deleted_at IS NULL');

        DB::statement("COMMENT ON TABLE root_domains IS 'platform-домен (поддомен базового домена платформы вида slug.<base-domain>) создаётся автоматически при активации, verified_at=now(), tls_status=issued (wildcard). Читается ask-endpoint Caddy on-demand TLS: 200 если verified_at IS NOT NULL и account.status в (trial,active)'");
    }

    public function down(): void
    {
        Schema::dropIfExists('root_domains');
    }
};
