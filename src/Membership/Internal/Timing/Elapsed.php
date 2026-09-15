<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Timing;

use LogicException;

/** @internal Conservative elapsed calculation for one controlled local domain. */
final class Elapsed
{
    public static function upperBoundSeconds(ClockReading $started, ClockReading $observed): int
    {
        if ($started->domainId !== $observed->domainId || $started->processId !== $observed->processId || $started->ticksPerSecond !== $observed->ticksPerSecond || $observed->ticks < $started->ticks) {
            throw new LogicException('Clock domain is lost, restarted, or mismatched.');
        }

        $ticks = $observed->ticks - $started->ticks + $started->errorTicks + $observed->errorTicks;

        return intdiv($ticks + $started->ticksPerSecond - 1, $started->ticksPerSecond);
    }
}
