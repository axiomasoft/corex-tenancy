<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const string PairTable = 'tnt_membership_pairs';

    private const string InboxTable = 'tnt_membership_inbox';

    public function up(): void
    {
        Schema::create(self::PairTable, static function (Blueprint $table): void {
            $table->uuid('account_id');
            $table->uuid('identity_id');
            $table->decimal('revision', total: 20, places: 0)->default(0);
            $table->decimal('revoke_generation', total: 20, places: 0)->default(0);
            $table->decimal('local_epoch', total: 20, places: 0)->default(0);
            $table->decimal('operation_fence', total: 20, places: 0)->default(0);
            $table->decimal('application_fence', total: 20, places: 0)->nullable();
            $table->uuid('operation_id')->nullable();
            $table->uuid('applied_operation_id')->nullable();
            $table->uuid('barrier_id')->nullable();
            $table->char('snapshot_digest', 64)->nullable();
            $table->boolean('denied')->default(true);
            $table->primary(['account_id', 'identity_id']);
        });

        Schema::create(self::InboxTable, static function (Blueprint $table): void {
            $table->uuid('event_id')->primary();
            $table->char('logical_digest', 64);
            $table->uuid('delivery_id');
            $table->string('status', 12);
            $table->unsignedBigInteger('attempts')->default(0);
            $table->uuid('owner_id')->nullable();
            $table->bigInteger('lease_until')->nullable();
        });

        DB::statement('ALTER TABLE '.self::PairTable.' ADD CONSTRAINT tnt_membership_pairs_revision_range CHECK (revision BETWEEN 0 AND 18446744073709551615)');
        DB::statement('ALTER TABLE '.self::PairTable.' ADD CONSTRAINT tnt_membership_pairs_revoke_generation_range CHECK (revoke_generation BETWEEN 0 AND 18446744073709551615)');
        DB::statement('ALTER TABLE '.self::PairTable.' ADD CONSTRAINT tnt_membership_pairs_local_epoch_range CHECK (local_epoch BETWEEN 0 AND 18446744073709551615)');
        DB::statement('ALTER TABLE '.self::PairTable.' ADD CONSTRAINT tnt_membership_pairs_operation_fence_range CHECK (operation_fence BETWEEN 0 AND 18446744073709551615)');
        DB::statement('ALTER TABLE '.self::PairTable.' ADD CONSTRAINT tnt_membership_pairs_application_fence_range CHECK (application_fence IS NULL OR application_fence BETWEEN 0 AND 18446744073709551615)');
        DB::statement('ALTER TABLE '.self::PairTable." ADD CONSTRAINT tnt_membership_pairs_snapshot_digest_format CHECK (snapshot_digest IS NULL OR snapshot_digest ~ '^[a-f0-9]{64}$')");
        DB::statement('ALTER TABLE '.self::InboxTable." ADD CONSTRAINT tnt_membership_inbox_status CHECK (status IN ('pending', 'acknowledged'))");
        DB::statement('CREATE INDEX tnt_membership_inbox_pending_idx ON '.self::InboxTable.' (lease_until, event_id) WHERE status = \'pending\'');
    }

    public function down(): void
    {
        if (Schema::hasTable(self::PairTable) && DB::table(self::PairTable)->exists()) {
            throw new RuntimeException('Refusing to drop populated membership pair mapping.');
        }

        if (Schema::hasTable(self::InboxTable) && DB::table(self::InboxTable)->exists()) {
            throw new RuntimeException('Refusing to drop populated membership inbox mapping.');
        }

        Schema::dropIfExists(self::InboxTable);
        Schema::dropIfExists(self::PairTable);
    }
};
