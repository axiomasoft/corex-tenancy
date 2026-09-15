<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Commands;

use CoreX\Tenancy\Provisioning\PendingPool;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Drops stale pending болванки — DROP DATABASE + DELETE root_accounts —
 * whose `template_version` no longer matches the current golden template
 * (`--template-version`) and/or whose `created_at` is older than
 * `--older-than` (e.g. `24h`, `7d`, `30m`, `45s`).
 */
final class PendingClearCommand extends Command
{
    protected $signature = 'tenants:pending-clear {--older-than=} {--template-version=}';

    protected $description = 'Delete stale pending account болванки by template version and/or age.';

    public function handle(PendingPool $pool): int
    {
        $templateVersion = $this->option('template-version') !== null
            ? (int) $this->option('template-version')
            : null;

        $olderThanOption = $this->option('older-than');
        $olderThan = $olderThanOption !== null
            ? Carbon::now()->subSeconds($this->parseDurationToSeconds($olderThanOption))
            : null;

        $cleared = $pool->clearPending($templateVersion, $olderThan);

        $this->components->info(sprintf('Cleared %d stale pending account(s).', $cleared));

        return self::SUCCESS;
    }

    private function parseDurationToSeconds(string $duration): int
    {
        if (preg_match('/^(\d+)([smhd])$/', $duration, $matches) !== 1) {
            throw new InvalidArgumentException("Invalid --older-than duration [{$duration}]; expected e.g. 30s/15m/24h/7d.");
        }

        $amount = (int) $matches[1];

        return match ($matches[2]) {
            's' => $amount,
            'm' => $amount * 60,
            'h' => $amount * 3600,
            'd' => $amount * 86400,
        };
    }
}
