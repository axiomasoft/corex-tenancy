<?php

declare(strict_types=1);

use CoreX\Tenancy\Models\Domain;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table((new Domain)->getTable(), function (Blueprint $table): void {
            $table->unsignedBigInteger('verification_generation')->default(0);
        });
    }
};
