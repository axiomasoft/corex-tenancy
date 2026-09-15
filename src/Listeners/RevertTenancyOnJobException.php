<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Listeners;

use Illuminate\Queue\Events\JobExceptionOccurred;
use Stancl\Tenancy\Tenancy;

/**
 * F-Q1 (D76 PoC finding): stancl's `QueueTenancyBootstrapper` reverts
 * tenancy on `JobProcessed`/`JobFailed` only — a job that throws and is
 * released for retry fires `JobExceptionOccurred` instead, so tenancy is
 * never reverted and the next job picked up by the same long-lived worker
 * inherits the leaked tenant context. This listener ends tenancy
 * unconditionally on that event; a retried job re-initializes from its own
 * queue payload (`QueueTenancyBootstrapper::getPayload()`), so ending here
 * loses nothing. Registered by `TenancyServiceProvider` only — boxed
 * installs run no queues under stancl, so this listener has nothing to do
 * there.
 */
final class RevertTenancyOnJobException
{
    public function __construct(
        private readonly Tenancy $tenancy,
    ) {}

    public function handle(JobExceptionOccurred $event): void
    {
        $this->tenancy->end();
    }
}
