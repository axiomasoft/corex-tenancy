<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// See DB_SCHEMA.md §3.1 root_templates for the column-by-column source.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('root_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->integer('version')->unique();
            $table->string('db_name', 63);
            $table->string('status', 16)->default('building');
            $table->string('embedding_model', 64);
            $table->smallInteger('embedding_dim');
            $table->string('git_ref', 64)->nullable();
            $table->timestampTz('built_at', precision: 6)->nullable();
            $table->timestampTz('created_at', precision: 6)->useCurrent();
            $table->timestampTz('updated_at', precision: 6)->useCurrent();
        });

        DB::statement("ALTER TABLE root_templates ADD CONSTRAINT root_templates_status_ck CHECK (status IN ('building','ready','retired'))");

        DB::statement("COMMENT ON TABLE root_templates IS 'Golden-шаблон = артефакт релиза: CI катает миграции на версионированную шаблонную БД (имя приходит данными — db_name), помечает IS_TEMPLATE; приложение к нему никогда не коннектится'");
    }

    public function down(): void
    {
        Schema::dropIfExists('root_templates');
    }
};
