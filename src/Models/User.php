<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Models;

use Carbon\CarbonImmutable;
use CoreX\Concerns\HasConfiguredId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Tenant-DB projection of `root_identities` (B-11 §2.2, full denorm D4/C6).
 * Cannot extend `CoreX\Models\BaseModel` (its own docblock carves out
 * exactly this case): `id` is external (= root_identities.id, no generated
 * default) — {@see HasConfiguredId} would fight that.
 *
 * @property string $id
 * @property string $email
 * @property string $name
 * @property ?string $phone
 * @property string $locale
 * @property ?string $password_hash
 * @property ?string $department_id
 * @property bool $is_active
 * @property array<string, mixed> $settings
 * @property ?Carbon $last_login_at
 * @property ?Carbon $synced_at
 * @property ?CarbonImmutable $identity_source_updated_at
 */
final class User extends Model
{
    use SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $hidden = [
        'password_hash',
    ];

    protected $fillable = [
        'id',
        'email',
        'name',
        'phone',
        'locale',
        'department_id',
        'is_active',
        'settings',
        'last_login_at',
        'synced_at',
        'identity_source_updated_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'settings' => 'array',
        'last_login_at' => 'datetime',
        'synced_at' => 'datetime',
        'identity_source_updated_at' => 'immutable_datetime',
    ];
}
