<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// See DB_SCHEMA.md §3.1 root_oauth_refresh_tokens for the column-by-column source.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('root_oauth_refresh_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('session_id');
            $table->string('client_id', 64);
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('expires_at', precision: 6);
            $table->timestampTz('revoked_at', precision: 6)->nullable();
            $table->timestampTz('created_at', precision: 6)->useCurrent();
            $table->timestampTz('updated_at', precision: 6)->useCurrent();

            $table->foreign('session_id')->references('id')->on('root_idp_sessions')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('root_oauth_refresh_tokens');
    }
};
