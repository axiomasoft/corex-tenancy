<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('root_wakeup_reconciliation_state', function (Blueprint $table): void {
            $table->string('name', 64)->primary();
            $table->uuid('cursor_account_id')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('retry_at', precision: 6)->nullable();
            $table->timestampTz('created_at', precision: 6)->useCurrent();
            $table->timestampTz('updated_at', precision: 6)->useCurrent();
        });
    }
};
