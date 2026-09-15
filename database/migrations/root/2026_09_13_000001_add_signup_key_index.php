<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("CREATE UNIQUE INDEX root_accounts_signup_key_uq ON root_accounts ((data->>'signup_key')) WHERE data->>'signup_key' IS NOT NULL AND deleted_at IS NULL;");
    }
};
