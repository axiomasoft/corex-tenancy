<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stancl\Tenancy\Database\Concerns\InvalidatesTenantsResolverCache;

/**
 * `root_domains` — per-account host registry (central connection, D14).
 * Not a stancl `Contracts\Domain` implementation: {@see
 * \CoreX\Tenancy\Resolvers\HostTenantResolver} owns host resolution
 * directly against this table, stancl's own `Domain` machinery is unused
 * here (D119).
 *
 * @property string $id
 * @property string $account_id
 * @property string $host
 * @property string $type
 * @property bool $is_primary
 * @property ?string $verification_token
 * @property ?CarbonImmutable $verified_at
 * @property string $tls_status
 * @property ?string $ca_used
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property ?CarbonImmutable $deleted_at
 */
final class Domain extends Model
{
    use InvalidatesTenantsResolverCache;

    protected $table = 'root_domains';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
            'verified_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
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
     * Alias required by {@see InvalidatesTenantsResolverCache}'s boot hook
     * (`$model->tenant`) — the trait is vendor-owned and reads exactly that
     * accessor name; `account()` above is this item's own naming (D14).
     *
     * @return BelongsTo<Account, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->account();
    }
}
