<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// See DB_SCHEMA.md §3.1 root_identities for the column-by-column source.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('root_identities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email', 255);
            $table->string('phone', 32)->nullable();
            $table->string('name', 255)->default('');
            $table->string('password_hash', 255)->nullable();
            $table->timestampTz('email_verified_at', precision: 6)->nullable();
            $table->text('mfa_totp_secret')->nullable();
            $table->timestampTz('mfa_enabled_at', precision: 6)->nullable();
            $table->string('locale', 8)->default('ru');
            $table->boolean('is_staff')->default(false);
            $table->timestampTz('last_login_at', precision: 6)->nullable();
            $table->timestampTz('created_at', precision: 6)->useCurrent();
            $table->timestampTz('updated_at', precision: 6)->useCurrent();
            $table->timestampTz('deleted_at', precision: 6)->nullable();
        });

        DB::statement('CREATE UNIQUE INDEX root_identities_email_uq ON root_identities (lower(email)) WHERE deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('root_identities');
    }
};
