<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('root_wakeup_hints', function (Blueprint $table): void {
            // root_accounts uses UUID today. This intentionally has no FK:
            // root hints are advisory and cannot become tenant authority.
            $table->uuid('account_id')->primary();
            $table->timestampTz('next_fire_at', precision: 6);
            $table->unsignedBigInteger('hint_revision');
            $table->timestampTz('leased_until', precision: 6)->nullable();
            $table->unsignedBigInteger('claim_epoch');
            $table->timestampTz('created_at', precision: 6)->useCurrent();
            $table->timestampTz('updated_at', precision: 6)->useCurrent();
            $table->index(['next_fire_at', 'leased_until'], 'root_wakeup_hints_scan_idx');
        });
    }
};
