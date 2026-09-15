<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Tenant-DB migration (golden-template golden path, D14/D16) — NOT root_.
// `id` is external (= root_identities.id, no DEFAULT — IdentitySyncer sets
// it). `department_id` is a loose reference: the FK lands with the
// cross-package integration migration (P2.6, DB_SCHEMA C4), not here. See
// DB_SCHEMA.md §3.4 `users` for the column-by-column source; earliest
// tenant-DB timestamp (FK-target of wsp_*/aut_*, P2.6/P2.8).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email', 255);
            $table->string('name', 255)->default('');
            $table->string('phone', 32)->nullable();
            $table->string('locale', 8)->default('ru');
            $table->string('password_hash', 255)->nullable();
            $table->uuid('department_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->jsonb('settings')->default('{}');
            $table->timestampTz('last_login_at', precision: 6)->nullable();
            $table->timestampTz('synced_at', precision: 6)->nullable();
            $table->timestampTz('created_at', precision: 6)->useCurrent();
            $table->timestampTz('updated_at', precision: 6)->useCurrent();
            $table->timestampTz('deleted_at', precision: 6)->nullable();
        });

        DB::statement('CREATE UNIQUE INDEX users_email_uq ON users (lower(email)) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
