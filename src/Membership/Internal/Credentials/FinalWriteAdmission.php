<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Credentials;

use Closure;
use CoreX\Tenancy\Membership\Internal\Persistence\PairKey;
use CoreX\Tenancy\Membership\Internal\Persistence\PairState;
use CoreX\Tenancy\Membership\Internal\Persistence\PairStore;
use CoreX\Tenancy\Membership\Internal\Timing\ClockReading;
use CoreX\Tenancy\Membership\Internal\Timing\Elapsed;
use CoreX\Tenancy\Membership\Internal\Timing\Lease;
use CoreX\Tenancy\Membership\Internal\Transport\Value;
use Illuminate\Database\ConnectionInterface;
use Throwable;

/** @internal Final SQL-write boundary for an unregistered membership consumer. */
final readonly class FinalWriteAdmission
{
    /**
     * @param  Closure(): ClockReading  $clock
     */
    public function __construct(
        private ConnectionInterface $connection,
        private PairStore $pairs,
        private Closure $clock,
        private int $remainingCommitUpperSeconds,
    ) {
        if (! $pairs->isFor($connection)) {
            throw new CredentialIssuanceDenied('The pair store must use the credential connection.');
        }
    }

    /**
     * @param  Closure(ConnectionInterface): void  $write
     */
    public function write(
        PairKey $key,
        string $operationId,
        ClockReading $readStarted,
        string $revokeGeneration,
        string $localEpoch,
        Closure $write,
    ): void {
        Value::uuid($operationId);
        Value::counter($revokeGeneration);
        Value::counter($localEpoch);

        try {
            $this->connection->transaction(function () use ($key, $operationId, $readStarted, $revokeGeneration, $localEpoch, $write): void {
                $state = $this->pairs->read(key: $key);
                $this->assertCurrentAdmission(
                    state: $state,
                    operationId: $operationId,
                    revokeGeneration: $revokeGeneration,
                    localEpoch: $localEpoch,
                );
                $this->assertLease(readStarted: $readStarted, observed: ($this->clock)());

                $write($this->connection);

                $this->assertLease(readStarted: $readStarted, observed: ($this->clock)());

                if (! $this->pairs->commit(key: $key, operationId: $operationId)) {
                    throw new CredentialIssuanceDenied('The pair fence denied the final credential write.');
                }
            });
        } catch (CredentialIssuanceDenied $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new CredentialIssuanceDenied(message: 'Final credential write was denied.', previous: $exception);
        }
    }

    private function assertCurrentAdmission(PairState $state, string $operationId, string $revokeGeneration, string $localEpoch): void
    {
        if ($state->denied
            || $state->operationId !== $operationId
            || $state->appliedOperationId !== $operationId
            || $state->revokeGeneration !== $revokeGeneration
            || $state->localEpoch !== $localEpoch
            || $state->applicationFence !== null) {
            throw new CredentialIssuanceDenied('The pair state is not admitted for this final write.');
        }
    }

    private function assertLease(ClockReading $readStarted, ClockReading $observed): void
    {
        if (! Lease::canCommit(
            elapsedUpperSeconds: Elapsed::upperBoundSeconds(started: $readStarted, observed: $observed),
            remainingCommitUpperSeconds: $this->remainingCommitUpperSeconds,
        )) {
            throw new CredentialIssuanceDenied('The final credential write lease has expired.');
        }
    }
}
