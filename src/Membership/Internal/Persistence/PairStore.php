<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Persistence;

use CoreX\Tenancy\Membership\Internal\Transport\Value;
use Illuminate\Database\ConnectionInterface;
use stdClass;

/** @internal Disposable PostgreSQL reference store; it is deliberately unregistered. */
final readonly class PairStore
{
    private const string ReferenceTable = 'membership_pair_references';

    private const string PhysicalTable = 'tnt_membership_pairs';

    public function __construct(
        private ConnectionInterface $connection,
        private string $table = self::ReferenceTable,
    ) {
        if (! in_array($table, [self::ReferenceTable, self::PhysicalTable], true)) {
            throw new PairStoreViolation('Pair store table is not an approved mapping.');
        }
    }

    public function isFor(ConnectionInterface $connection): bool
    {
        return $this->connection === $connection;
    }

    public function read(PairKey $key): PairState
    {
        return $this->connection->transaction(function () use ($key): PairState {
            $this->ensureExists($key);

            return $this->lockedState($key);
        });
    }

    public function admit(PairKey $key, string $operationId): PairState
    {
        Value::uuid($operationId);

        return $this->connection->transaction(function () use ($key, $operationId): PairState {
            $this->ensureExists($key);
            $current = $this->lockedState($key);

            if ($current->operationId === $operationId) {
                return $current;
            }

            $this->connection->affectingStatement(
                query: 'UPDATE '.$this->table.' SET operation_fence = operation_fence + 1, operation_id = :operation_id WHERE account_id = :account_id AND identity_id = :identity_id',
                bindings: [
                    'operation_id' => $operationId,
                    'account_id' => $key->accountId,
                    'identity_id' => $key->identityId,
                ],
            );

            return $this->lockedState($key);
        });
    }

    public function apply(PairKey $key, string $operationId, string $revision, string $revokeGeneration, ?string $snapshotDigest = null): PairState
    {
        Value::uuid($operationId);
        Value::counter($revision);
        Value::counter($revokeGeneration);
        $this->assertSnapshotDigest($snapshotDigest);

        return $this->connection->transaction(function () use ($key, $operationId, $revision, $revokeGeneration, $snapshotDigest): PairState {
            $current = $this->assertCurrentOperation($key, $operationId);

            if (self::compare($revision, $current->revision) < 0 || self::compare($revokeGeneration, $current->revokeGeneration) < 0) {
                throw new PairStoreViolation('Revision or revoke generation regression is denied.');
            }

            if (self::compare($revision, $current->revision) === 0
                && $current->snapshotDigest !== null
                && (! hash_equals($current->snapshotDigest, (string) $snapshotDigest)
                    || $current->revokeGeneration !== $revokeGeneration)) {
                throw new PairStoreViolation('Equal revision with a conflicting authority snapshot is denied.');
            }

            $bindings = [
                'revision' => $revision,
                'revoke_generation' => $revokeGeneration,
                'account_id' => $key->accountId,
                'identity_id' => $key->identityId,
                'operation_id' => $operationId,
            ];

            if ($this->physical()) {
                $bindings['snapshot_digest'] = $snapshotDigest;
            }

            $this->connection->affectingStatement(
                query: 'UPDATE '.$this->table.' SET revision = :revision, revoke_generation = :revoke_generation, applied_operation_id = :operation_id'.$this->snapshotUpdateClause().' WHERE account_id = :account_id AND identity_id = :identity_id AND operation_id = :operation_id',
                bindings: $bindings,
            );

            return $this->lockedState($key);
        });
    }

    public function commit(PairKey $key, string $operationId): bool
    {
        Value::uuid($operationId);

        return $this->connection->transaction(function () use ($key, $operationId): bool {
            $this->ensureExists($key);
            $current = $this->lockedState($key);

            if ($current->denied) {
                return false;
            }

            if ($current->operationId !== $operationId) {
                throw new PairStoreViolation('Stale operation fence is denied.');
            }

            return $this->connection->affectingStatement(
                query: 'UPDATE '.$this->table.' SET application_fence = operation_fence WHERE account_id = :account_id AND identity_id = :identity_id AND operation_id = :operation_id AND denied = false',
                bindings: [
                    'account_id' => $key->accountId,
                    'identity_id' => $key->identityId,
                    'operation_id' => $operationId,
                ],
            ) === 1;
        });
    }

    /**
     * Re-opens a pair only after the current operation applied a fresh authority snapshot.
     *
     * This remains a disposable reference-store transition: it creates no runtime grant.
     */
    public function regrant(PairKey $key, string $operationId): PairState
    {
        Value::uuid($operationId);

        return $this->connection->transaction(function () use ($key, $operationId): PairState {
            $current = $this->assertCurrentOperation($key, $operationId);

            if ($current->appliedOperationId !== $operationId) {
                throw new PairStoreViolation('Regrant is denied until the current authority snapshot is applied.');
            }

            $this->connection->affectingStatement(
                query: 'UPDATE '.$this->table.' SET denied = false, application_fence = NULL WHERE account_id = :account_id AND identity_id = :identity_id AND operation_id = :operation_id',
                bindings: [
                    'account_id' => $key->accountId,
                    'identity_id' => $key->identityId,
                    'operation_id' => $operationId,
                ],
            );

            return $this->lockedState($key);
        });
    }

    public function invalidate(PairKey $key, string $barrierId): PairState
    {
        Value::uuid($barrierId);

        return $this->connection->transaction(function () use ($key, $barrierId): PairState {
            $this->ensureExists($key);
            $current = $this->lockedState($key);

            if ($current->barrierId === $barrierId) {
                return $current;
            }

            $this->connection->affectingStatement(
                query: 'UPDATE '.$this->table.' SET local_epoch = local_epoch + 1, barrier_id = :barrier_id, denied = true, application_fence = NULL, operation_id = NULL, applied_operation_id = NULL WHERE account_id = :account_id AND identity_id = :identity_id',
                bindings: [
                    'barrier_id' => $barrierId,
                    'account_id' => $key->accountId,
                    'identity_id' => $key->identityId,
                ],
            );

            return $this->lockedState($key);
        });
    }

    public function restore(PairKey $key, ?PairWitness $witness): PairState
    {
        if ($witness === null) {
            throw new PairStoreViolation('Restore is denied without an independent witness.');
        }

        return $this->connection->transaction(function () use ($key, $witness): PairState {
            $this->ensureExists($key);
            $current = $this->lockedState($key);

            if (self::compare($witness->revision, $current->revision) < 0
                || self::compare($witness->revokeGeneration, $current->revokeGeneration) < 0
                || self::compare($witness->localEpoch, $current->localEpoch) < 0
                || self::compare($witness->operationFence, $current->operationFence) < 0) {
                throw new PairStoreViolation('Restore witness is behind durable pair state.');
            }

            $this->connection->affectingStatement(
                query: 'UPDATE '.$this->table.' SET revision = :revision, revoke_generation = :revoke_generation, local_epoch = :local_epoch, operation_fence = :operation_fence, application_fence = NULL, denied = true, operation_id = NULL, applied_operation_id = NULL, barrier_id = NULL WHERE account_id = :account_id AND identity_id = :identity_id',
                bindings: [
                    'revision' => $witness->revision,
                    'revoke_generation' => $witness->revokeGeneration,
                    'local_epoch' => $witness->localEpoch,
                    'operation_fence' => $witness->operationFence,
                    'account_id' => $key->accountId,
                    'identity_id' => $key->identityId,
                ],
            );

            return $this->lockedState($key);
        });
    }

    private function assertCurrentOperation(PairKey $key, string $operationId): PairState
    {
        $this->ensureExists($key);
        $current = $this->lockedState($key);

        if ($current->operationId !== $operationId) {
            throw new PairStoreViolation('Stale operation fence is denied.');
        }

        return $current;
    }

    private function ensureExists(PairKey $key): void
    {
        $this->connection->affectingStatement(
            query: 'INSERT INTO '.$this->table.' (account_id, identity_id, revision, revoke_generation, local_epoch, operation_fence, denied) VALUES (:account_id, :identity_id, 0, 0, 0, 0, :denied) ON CONFLICT (account_id, identity_id) DO NOTHING',
            bindings: [
                'account_id' => $key->accountId,
                'identity_id' => $key->identityId,
                'denied' => $this->physical(),
            ],
        );
    }

    private function lockedState(PairKey $key): PairState
    {
        /** @var stdClass $row */
        $row = $this->connection->selectOne(
            query: 'SELECT revision, revoke_generation, local_epoch, operation_fence, application_fence, operation_id, applied_operation_id, barrier_id, denied'.$this->snapshotSelectClause().' FROM '.$this->table.' WHERE account_id = :account_id AND identity_id = :identity_id FOR UPDATE',
            bindings: [
                'account_id' => $key->accountId,
                'identity_id' => $key->identityId,
            ],
        );

        return new PairState(
            revision: (string) $row->revision,
            revokeGeneration: (string) $row->revoke_generation,
            localEpoch: (string) $row->local_epoch,
            operationFence: (string) $row->operation_fence,
            applicationFence: $row->application_fence === null ? null : (string) $row->application_fence,
            operationId: $row->operation_id === null ? null : (string) $row->operation_id,
            appliedOperationId: $row->applied_operation_id === null ? null : (string) $row->applied_operation_id,
            barrierId: $row->barrier_id === null ? null : (string) $row->barrier_id,
            denied: (bool) $row->denied,
            snapshotDigest: $this->physical() && $row->snapshot_digest !== null ? (string) $row->snapshot_digest : null,
        );
    }

    private function physical(): bool
    {
        return $this->table === self::PhysicalTable;
    }

    private function assertSnapshotDigest(?string $snapshotDigest): void
    {
        if ($this->physical() && $snapshotDigest === null) {
            throw new PairStoreViolation('Production pair mapping requires a snapshot digest.');
        }

        if ($snapshotDigest !== null && preg_match('/^[a-f0-9]{64}$/', $snapshotDigest) !== 1) {
            throw new PairStoreViolation('Snapshot digest must be a canonical SHA-256 value.');
        }
    }

    private function snapshotSelectClause(): string
    {
        return $this->physical() ? ', snapshot_digest' : '';
    }

    private function snapshotUpdateClause(): string
    {
        return $this->physical() ? ', snapshot_digest = :snapshot_digest' : '';
    }

    private static function compare(string $left, string $right): int
    {
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }
}
