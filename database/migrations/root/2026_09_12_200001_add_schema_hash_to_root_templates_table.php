<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE root_templates ADD COLUMN schema_hash varchar(64) NULL');
        DB::statement("ALTER TABLE root_templates ADD CONSTRAINT root_templates_schema_hash_ck CHECK (schema_hash IS NULL OR schema_hash COLLATE \"C\" ~ '^[0-9a-f]{64}$')");
    }
};
