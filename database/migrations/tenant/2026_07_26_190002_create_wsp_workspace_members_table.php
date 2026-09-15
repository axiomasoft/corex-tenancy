<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Tenant-DB migration (B-11 §2.2, D14/D16) — membership is a fast admission
// flag read by the resolver (403 without it); roles are NOT stored here
// (corex/auth owns aut_role_assignments, P2.8). See DB_SCHEMA.md §3.4
// wsp_workspace_members for the column-by-column source.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wsp_workspace_members', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('workspace_id');
            $table->uuid('user_id');
            $table->string('status', 16)->default('active');
            $table->boolean('is_default')->default(false);
            $table->timestampTz('joined_at', precision: 6)->useCurrent();
            $table->timestampTz('created_at', precision: 6)->useCurrent();
            $table->timestampTz('updated_at', precision: 6)->useCurrent();

            $table->foreign('workspace_id')->references('id')->on('wsp_workspaces')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['workspace_id', 'user_id']);
        });

        DB::statement("ALTER TABLE wsp_workspace_members ADD CONSTRAINT wsp_members_status_ck CHECK (status IN ('active','suspended'))");

        DB::statement('CREATE INDEX wsp_members_user_ix ON wsp_workspace_members (user_id)');
        DB::statement('CREATE UNIQUE INDEX wsp_members_default_uq ON wsp_workspace_members (user_id) WHERE is_default');

        DB::statement("COMMENT ON TABLE wsp_workspace_members IS 'Membership = fast admission flag for the resolver (403 without it); PERMISSIONS live only in corex/auth (roles) — role not stored in this pivot'");
    }

    public function down(): void
    {
        Schema::dropIfExists('wsp_workspace_members');
    }
};
