<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Jobs;

use CoreX\Tenancy\Identity\IdentitySyncer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * One fan-out unit ({@see IdentitySyncer::fanOut()}, AC-16 г) — each account
 * gets its own queued job, so one failing sync sets that account's
 * `sync_status=failed` without blocking the others (they are independent
 * queue entries, not a single loop).
 */
final class SyncIdentityToAccount implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $identityId,
        public readonly string $accountId,
    ) {}

    public function handle(IdentitySyncer $syncer): void
    {
        $syncer->syncToAccount($this->identityId, $this->accountId);
    }
}
