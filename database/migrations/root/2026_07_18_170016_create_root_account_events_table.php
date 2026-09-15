<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// See DB_SCHEMA.md §3.1 root_account_events for the column-by-column source.
// actor jsonb is an ACTOR snapshot {type: identity|system|support, id}
// (DB_SCHEMA §1 ACTOR convention).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('root_account_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('account_id');
            $table->string('event', 64);
            $table->jsonb('actor')->default('{}');
            $table->jsonb('payload')->default('{}');
            $table->timestampTz('created_at', precision: 6)->useCurrent();
            $table->timestampTz('updated_at', precision: 6)->useCurrent();

            $table->foreign('account_id')->references('id')->on('root_accounts')->cascadeOnDelete();
        });

        DB::statement('CREATE INDEX root_account_events_ix ON root_account_events (account_id, created_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('root_account_events');
    }
};
