<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `root_account_exports` — offboarding/pre-purge/manual data-export
 * bookkeeping (central connection, B-11 §7.3). {@see
 * \CoreX\Tenancy\Provisioning\StanclTenantDatabaseLifecycle::drop()} refuses
 * to run unless the account's most recent row here is `status=ready`
 * (D13/AC-7).
 *
 * @property string $id
 * @property string $account_id
 * @property string $kind
 * @property string $status
 * @property array<int, string> $formats
 * @property ?string $storage_path
 * @property ?CarbonImmutable $url_expires_at
 * @property ?int $size_bytes
 * @property ?CarbonImmutable $purge_after
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class AccountExport extends Model
{
    protected $table = 'root_account_exports';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'formats' => 'array',
            'url_expires_at' => 'immutable_datetime',
            'purge_after' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }
}
