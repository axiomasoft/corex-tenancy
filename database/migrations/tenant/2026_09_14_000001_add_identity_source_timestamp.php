<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', static function (Blueprint $table): void {
            $table->timestampTz('identity_source_updated_at', precision: 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', static fn (Blueprint $table) => $table->dropColumn('identity_source_updated_at'));
    }
};
