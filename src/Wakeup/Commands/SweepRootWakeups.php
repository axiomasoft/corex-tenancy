<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Wakeup\Commands;

use CoreX\Tenancy\Wakeup\Internal\RootWakeupReconciler;
use Illuminate\Console\Command;

final class SweepRootWakeups extends Command
{
    protected $signature = 'corex:wakeup:root-sweep {--limit=100 : Maximum due account hints} {--reconcile : Rebuild one bounded page of advisory hints} {--cursor= : Resume after this account id}';

    protected $description = 'Run due CoreX wakeup hints through trusted tenant context switching.';

    public function handle(RootWakeupReconciler $reconciler): int
    {
        $limit = (int) $this->option('limit');

        if ($limit < 1 || $limit > 1_000) {
            $this->error('The --limit option must be between 1 and 1000.');

            return self::INVALID;
        }

        if ((bool) $this->option('reconcile')) {
            $result = $reconciler->reconcile(limit: $limit, afterAccountId: $this->option('cursor'));
            $this->info("Reconciled {$result['processed']} account wakeup hints.");

            if ($result['next_cursor'] !== null) {
                $this->line("Resume with --cursor={$result['next_cursor']}.");
            }

            return self::SUCCESS;
        }

        $this->info("Swept {$reconciler->sweep(limit: $limit)} account hints.");

        return self::SUCCESS;
    }
}
