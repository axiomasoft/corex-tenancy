<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// See DB_SCHEMA.md §3.1 root_account_schema_state for the column-by-column
// source — "единственная истина «кто на какой версии»".
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('root_account_schema_state', function (Blueprint $table): void {
            $table->uuid('account_id')->primary();
            $table->integer('current_version');
            $table->integer('target_version');
            $table->string('status', 16)->default('ok');
            $table->uuid('batch_id')->nullable();
            $table->timestampTz('last_migrated_at', precision: 6)->nullable();
            $table->text('last_error')->nullable();
            $table->timestampTz('created_at', precision: 6)->useCurrent();
            $table->timestampTz('updated_at', precision: 6)->useCurrent();

            $table->foreign('account_id')->references('id')->on('root_accounts')->cascadeOnDelete();
            $table->foreign('batch_id')->references('id')->on('root_migration_batches')->nullOnDelete();
        });

        DB::statement("ALTER TABLE root_account_schema_state ADD CONSTRAINT root_account_schema_state_status_ck CHECK (status IN ('ok','migrating','failed','blocked'))");

        DB::statement("CREATE INDEX root_schema_state_bad_ix ON root_account_schema_state (status) WHERE status <> 'ok'");

        DB::statement("COMMENT ON COLUMN root_account_schema_state.status IS 'migrating = сигнал для B-15: DDL-воркер кастомных полей аккаунта откладывает ops (R-13 §3.5); failed→blocked = аккаунт исключён из волн до разбора, код обслуживает совместимой версией'");
    }

    public function down(): void
    {
        Schema::dropIfExists('root_account_schema_state');
    }
};
