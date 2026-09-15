<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Models;

use Carbon\CarbonImmutable;
use CoreX\Tenancy\Impersonation\DatabaseImpersonationService;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * `root_impersonation_grants` — thin Eloquent read model on the CENTRAL
 * connection (D14; overridden here rather than relying on the ambient
 * default connection, because callers run this from ALREADY tenant-scoped
 * request context — P2.10 initializes tenancy before the OIDC lane runs).
 * The one-time atomic claim itself is a query-builder `UPDATE` in
 * {@see DatabaseImpersonationService} (D143),
 * not an Eloquent write — this model exists for the L2 exempt-list
 * (`ImpersonatedModelWriteGuard`) and read access after the claim.
 *
 * @property string $id
 * @property string $staff_identity_id
 * @property string $account_id
 * @property ?string $target_user_id
 * @property string $reason
 * @property ?string $approved_by
 * @property CarbonImmutable $expires_at
 * @property ?CarbonImmutable $used_at
 * @property ?CarbonImmutable $revoked_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class ImpersonationGrant extends Model
{
    protected $table = 'root_impersonation_grants';

    protected $guarded = [];

    #[Override]
    public function getConnectionName(): string
    {
        return (string) config('tenancy.central_connection');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }
}
