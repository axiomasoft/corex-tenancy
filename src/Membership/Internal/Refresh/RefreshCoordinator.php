<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Refresh;

use Closure;
use CoreX\Tenancy\Membership\Internal\Inbox\DurableInbox;
use Throwable;

/** @internal Fetches outside a transaction; only a completed pull acknowledges an inbox row. */
final readonly class RefreshCoordinator
{
    /** @param Closure(): void $refresh */
    public function __construct(private DurableInbox $inbox, private Closure $refresh) {}

    public function refresh(string $eventId, string $owner, int $now): bool
    {
        if (! $this->inbox->claim(eventId: $eventId, owner: $owner, now: $now)) {
            return false;
        }

        try {
            ($this->refresh)();
        } catch (Throwable) {
            return false;
        }

        return $this->inbox->acknowledge(eventId: $eventId, owner: $owner, now: $now);
    }
}
