<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Timing;

use LogicException;

/** @internal Controlled reference-clock sample, never a deployed time source. */
final readonly class ClockReading
{
    public function __construct(
        public string $domainId,
        public string $processId,
        public int $ticks,
        public int $ticksPerSecond,
        public int $errorTicks,
    ) {
        if ($domainId === '' || $processId === '' || $ticks < 0 || $ticksPerSecond < 1 || $errorTicks < 0) {
            throw new LogicException('Clock reading is not qualified.');
        }
    }
}
