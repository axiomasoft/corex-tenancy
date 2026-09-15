<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * `root_migration_batches` — a wave of tenant-schema migrations targeting
 * `to_version` (central connection, B-11 §5.6/P2.12).
 *
 * @property string $id
 * @property int $from_version
 * @property int $to_version
 * @property string $wave
 * @property string $status
 * @property array<string, mixed> $stats
 * @property ?CarbonImmutable $started_at
 * @property ?CarbonImmutable $finished_at
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class MigrationBatch extends Model
{
    protected $table = 'root_migration_batches';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stats' => 'array',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }
}
