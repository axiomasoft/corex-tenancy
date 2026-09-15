<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// `sessions` is a Laravel-framework table, not a `corex/*`-owned domain
// table — it carries no `root_/sys_/mod_/wsp_/aut_` prefix and is
// deliberately absent from DB_SCHEMA.md (D122, P2.10): physical isolation
// of the session (B-11 §7.3 п.5) requires it to live in the tenant DB
// alongside `users`, not the central connection. Canonical Laravel column
// shape; `user_id` matches `users.id`'s uuid type rather than the
// framework default bigint.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
