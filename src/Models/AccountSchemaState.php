<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `root_account_schema_state` — single source of truth for "which tenant
 * schema version is this account on" (central connection, B-11 §5.6). PK is
 * `account_id`, not `id` (one row per account).
 *
 * @property string $account_id
 * @property int $current_version
 * @property int $target_version
 * @property string $status
 * @property ?string $batch_id
 * @property ?CarbonImmutable $last_migrated_at
 * @property ?string $last_error
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class AccountSchemaState extends Model
{
    protected $table = 'root_account_schema_state';

    protected $primaryKey = 'account_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_migrated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /**
     * @return BelongsTo<MigrationBatch, $this>
     */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(MigrationBatch::class, 'batch_id');
    }
}
