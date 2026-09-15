<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// See DB_SCHEMA.md §3.1 root_impersonation_grants for the column-by-column
// source. target_user_id is cross-DB (tenant users.id) — no FK (D14/DB_SCHEMA
// §1). expires_at default is an explicit interval expression, not a literal
// (Implementation Rules P2.2: "интервал не литерал в контракте, задать явным
// выражением, зафиксировать").
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('root_impersonation_grants', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('staff_identity_id');
            $table->uuid('account_id');
            $table->uuid('target_user_id')->nullable();
            $table->text('reason');
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('expires_at', precision: 6)->default(DB::raw("(now() + interval '1 hour')"));
            $table->timestampTz('used_at', precision: 6)->nullable();
            $table->timestampTz('revoked_at', precision: 6)->nullable();
            $table->timestampTz('created_at', precision: 6)->useCurrent();
            $table->timestampTz('updated_at', precision: 6)->useCurrent();

            $table->foreign('staff_identity_id')->references('id')->on('root_identities')->cascadeOnDelete();
            $table->foreign('account_id')->references('id')->on('root_accounts')->cascadeOnDelete();
            $table->foreign('approved_by')->references('id')->on('root_identities')->nullOnDelete();
        });

        DB::statement('CREATE INDEX root_impersonation_acc_ix ON root_impersonation_grants (account_id, created_at)');

        DB::statement("COMMENT ON COLUMN root_impersonation_grants.target_user_id IS 'users.id тенантской БД — cross-DB, без FK (D14/DB_SCHEMA §1)'");
    }

    public function down(): void
    {
        Schema::dropIfExists('root_impersonation_grants');
    }
};
