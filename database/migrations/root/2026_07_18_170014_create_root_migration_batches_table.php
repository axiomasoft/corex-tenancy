<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// See DB_SCHEMA.md §3.1 root_migration_batches for the column-by-column source.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('root_migration_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->integer('from_version');
            $table->integer('to_version');
            $table->string('wave', 16);
            $table->string('status', 16)->default('created');
            $table->jsonb('stats')->default('{}');
            $table->timestampTz('started_at', precision: 6)->nullable();
            $table->timestampTz('finished_at', precision: 6)->nullable();
            $table->timestampTz('created_at', precision: 6)->useCurrent();
            $table->timestampTz('updated_at', precision: 6)->useCurrent();
        });

        DB::statement("ALTER TABLE root_migration_batches ADD CONSTRAINT root_migration_batches_wave_ck CHECK (wave IN ('internal','canary','p10','p50','all'))");
        DB::statement("ALTER TABLE root_migration_batches ADD CONSTRAINT root_migration_batches_status_ck CHECK (status IN ('created','running','paused','done','failed'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('root_migration_batches');
    }
};
