<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// See DB_SCHEMA.md §3.1 root_usage_snapshots for the column-by-column source.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('root_usage_snapshots', function (Blueprint $table): void {
            $table->uuid('account_id');
            $table->string('metric', 64);
            $table->date('period');
            $table->decimal('value', 18, 3);
            $table->timestampTz('created_at', precision: 6)->useCurrent();
            $table->timestampTz('updated_at', precision: 6)->useCurrent();

            $table->primary(['account_id', 'metric', 'period']);
            $table->foreign('account_id')->references('id')->on('root_accounts')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('root_usage_snapshots');
    }
};
