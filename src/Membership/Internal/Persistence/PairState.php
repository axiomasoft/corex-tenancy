<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Persistence;

use CoreX\Tenancy\Membership\Internal\Transport\Value;

/** @internal Immutable view of the disposable pair-store mapping. */
final readonly class PairState
{
    public function __construct(
        public string $revision,
        public string $revokeGeneration,
        public string $localEpoch,
        public string $operationFence,
        public ?string $applicationFence,
        public ?string $operationId,
        public ?string $appliedOperationId,
        public ?string $barrierId,
        public bool $denied,
        public ?string $snapshotDigest = null,
    ) {
        Value::counter($revision);
        Value::counter($revokeGeneration);
        Value::counter($localEpoch);
        Value::counter($operationFence);

        if ($applicationFence !== null) {
            Value::counter($applicationFence);
        }

        if ($operationId !== null) {
            Value::uuid($operationId);
        }

        if ($appliedOperationId !== null) {
            Value::uuid($appliedOperationId);
        }

        if ($barrierId !== null) {
            Value::uuid($barrierId);
        }

        if ($snapshotDigest !== null && preg_match('/^[a-f0-9]{64}$/', $snapshotDigest) !== 1) {
            throw new PairStoreViolation('Snapshot digest must be a canonical SHA-256 value.');
        }
    }
}
