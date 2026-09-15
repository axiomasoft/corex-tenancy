<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Models;

use Carbon\CarbonImmutable;
use CoreX\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * `wsp_workspaces` — hierarchy of companies/branches/partners within an
 * account (B-11 §2.2). `path` (ltree) supports hierarchical predicates
 * (`path <@ :subtree`); `type=partner` membership does not grant visibility
 * of the parent (B-11 §5.4).
 *
 * @property string $id
 * @property ?string $parent_id
 * @property string $path
 * @property string $type
 * @property string $name
 * @property string $slug
 * @property bool $is_default
 * @property array<string, mixed> $settings
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property ?CarbonImmutable $deleted_at
 */
final class Workspace extends BaseModel
{
    protected $table = 'wsp_workspaces';

    /** @var list<string> */
    protected $fillable = ['parent_id', 'path', 'type', 'name', 'slug', 'is_default', 'settings'];

    /** @return BelongsTo<Workspace, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /** @return HasMany<Workspace, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /** @return HasMany<WorkspaceMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    /** @return HasMany<Invitation, $this> */
    public function invitations(): HasMany
    {
        return $this->hasMany(Invitation::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'settings' => 'array',
        ];
    }
}
