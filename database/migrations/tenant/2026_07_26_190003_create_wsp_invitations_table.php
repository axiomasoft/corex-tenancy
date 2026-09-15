<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Tenant-DB migration (B-11 §2.2, D14/D16) — workspace/account invitations.
// `role_id` is a SOFT-REF with NO FK (D21, audit F16): aut_roles is created
// later by corex/auth (P2.8), a cross-package ordering constraint of the
// same class as C4; acceptance applies the SET NULL semantics in code (role
// not found → invitation accepted without a role). See DB_SCHEMA.md §3.4
// wsp_invitations for the column-by-column source.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wsp_invitations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('email', 255);
            $table->uuid('workspace_id')->nullable();
            $table->uuid('role_id')->nullable();
            $table->uuid('invited_by');
            $table->string('token_hash', 64)->unique();
            $table->string('status', 16)->default('pending');
            $table->timestampTz('expires_at', precision: 6);
            $table->timestampTz('accepted_at', precision: 6)->nullable();
            $table->timestampTz('created_at', precision: 6)->useCurrent();
            $table->timestampTz('updated_at', precision: 6)->useCurrent();

            $table->foreign('workspace_id')->references('id')->on('wsp_workspaces')->cascadeOnDelete();
            $table->foreign('invited_by')->references('id')->on('users')->restrictOnDelete();
        });

        DB::statement("ALTER TABLE wsp_invitations ADD CONSTRAINT wsp_invitations_status_ck CHECK (status IN ('pending','accepted','revoked','expired'))");
        DB::statement("ALTER TABLE wsp_invitations ALTER COLUMN expires_at SET DEFAULT (now() + interval '7 days')");

        DB::statement('CREATE INDEX wsp_invitations_email_ix ON wsp_invitations (lower(email)) WHERE status = \'pending\'');

        DB::statement("COMMENT ON COLUMN wsp_invitations.role_id IS 'Soft-ref, NO FK (D21): aut_roles lands later with corex/auth (P2.8); cleanup on accept is application-level, not ON DELETE SET NULL'");
    }

    public function down(): void
    {
        Schema::dropIfExists('wsp_invitations');
    }
};
