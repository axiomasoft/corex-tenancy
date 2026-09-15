<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Models;

use Carbon\CarbonImmutable;
use CoreX\Concerns\HasConfiguredId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * `wsp_workspace_members` — fast admission flag for the resolver (403
 * without an active row); roles are NOT stored here (corex/auth owns
 * aut_role_assignments, P2.8). No soft deletes — the table carries no
 * `deleted_at` (B-11 §2.2).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $user_id
 * @property string $status
 * @property bool $is_default
 * @property CarbonImmutable $joined_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class WorkspaceMember extends Model
{
    use HasConfiguredId;

    protected $table = 'wsp_workspace_members';

    /** @var list<string> */
    protected $fillable = ['workspace_id', 'user_id', 'status', 'is_default', 'joined_at'];

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'joined_at' => 'datetime',
        ];
    }
}
