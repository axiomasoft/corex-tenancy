<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// See DB_SCHEMA.md §3.1 root_idp_sessions for the column-by-column source.
// Authorization codes (TTL 60s) + PKCE verifiers live in Redis only — no table.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('root_idp_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('identity_id');
            $table->string('token_hash', 64)->unique();
            $table->ipAddress('ip')->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestampTz('expires_at', precision: 6);
            $table->timestampTz('revoked_at', precision: 6)->nullable();
            $table->timestampTz('created_at', precision: 6)->useCurrent();
            $table->timestampTz('updated_at', precision: 6)->useCurrent();

            $table->foreign('identity_id')->references('id')->on('root_identities')->cascadeOnDelete();
        });

        DB::statement('CREATE INDEX root_idp_sessions_identity_ix ON root_idp_sessions (identity_id) WHERE revoked_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('root_idp_sessions');
    }
};
