<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// See DB_SCHEMA.md §3.1 root_oauth_clients for the column-by-column source.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('root_oauth_clients', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('client_id', 64)->unique();
            $table->string('secret_hash', 255)->nullable();
            $table->string('name', 128);
            $table->string('product', 32)->nullable();
            $table->jsonb('redirect_patterns');
            $table->boolean('is_first_party')->default(true);
            $table->timestampTz('created_at', precision: 6)->useCurrent();
            $table->timestampTz('updated_at', precision: 6)->useCurrent();
            $table->timestampTz('deleted_at', precision: 6)->nullable();
        });

        DB::statement("COMMENT ON COLUMN root_oauth_clients.redirect_patterns IS 'wildcard покрывает платформенные поддомены; custom-домены валидируются точным матчем по root_domains(verified)'");
    }

    public function down(): void
    {
        Schema::dropIfExists('root_oauth_clients');
    }
};
