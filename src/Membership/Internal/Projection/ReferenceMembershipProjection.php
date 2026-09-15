<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Projection;

use Closure;
use CoreX\Tenancy\Central\MembershipSnapshot;
use CoreX\Tenancy\Central\MembershipState;
use CoreX\Tenancy\Membership\Internal\Credentials\CredentialCookieQueue;
use CoreX\Tenancy\Membership\Internal\Credentials\CredentialIssuanceDenied;
use CoreX\Tenancy\Membership\Internal\Credentials\FencedCredentialIssuer;
use CoreX\Tenancy\Membership\Internal\Expiry\ExpiryScheduler;
use CoreX\Tenancy\Membership\Internal\Inbox\DurableInbox;
use CoreX\Tenancy\Membership\Internal\Persistence\PairKey;
use CoreX\Tenancy\Membership\Internal\Persistence\PairState;
use CoreX\Tenancy\Membership\Internal\Persistence\PairStore;
use CoreX\Tenancy\Membership\Internal\Persistence\PairWitness;
use CoreX\Tenancy\Membership\Internal\Refresh\RefreshCoordinator;
use CoreX\Tenancy\Membership\Internal\Timing\Lease;
use CoreX\Tenancy\Membership\Internal\Transport\MembershipTrigger;
use Illuminate\Database\ConnectionInterface;

/** @internal Unregistered local composition; it is neither an auth guard nor a runtime projection. */
final readonly class ReferenceMembershipProjection
{
    public function __construct(
        private ConnectionInterface $connection,
        private PairStore $pairs,
        private FencedCredentialIssuer $credentials,
        private ExpiryScheduler $expiry,
        private DurableInbox $inbox,
    ) {}

    /** @param array<string, scalar|null> $metadata */
    public function grant(
        PairKey $key,
        MembershipSnapshot $snapshot,
        string $operationId,
        string $sessionId,
        ?string $rememberToken,
        array $metadata,
        int $elapsedUpperSeconds,
        int $remainingCommitUpperSeconds,
        CredentialCookieQueue $cookies,
    ): PairState {
        if ($snapshot->state !== MembershipState::Active
            || $snapshot->identityId !== $key->identityId
            || $snapshot->accountId !== $key->accountId
            || ! Lease::canCommit(
                elapsedUpperSeconds: $elapsedUpperSeconds,
                remainingCommitUpperSeconds: $remainingCommitUpperSeconds,
            )) {
            $cookies->deny();

            throw new ProjectionDenied('A current active authority snapshot and bounded lease are required.');
        }

        try {
            $this->pairs->admit(key: $key, operationId: $operationId);
            $this->pairs->apply(
                key: $key,
                operationId: $operationId,
                revision: $snapshot->revision,
                revokeGeneration: $snapshot->revokeGeneration,
            );
            $state = $this->pairs->regrant(key: $key, operationId: $operationId);
            $this->credentials->issue(
                key: $key,
                operationId: $operationId,
                sessionId: $sessionId,
                rememberToken: $rememberToken,
                metadata: $metadata,
            );
        } catch (CredentialIssuanceDenied|ProjectionDenied $exception) {
            $cookies->deny();

            throw $exception;
        }

        $cookies->queueSession(value: $sessionId);

        if ($rememberToken !== null) {
            $cookies->queueRemember(value: $rememberToken);
        }

        return $state;
    }

    public function revoke(PairKey $key, string $barrierId, CredentialCookieQueue $cookies): PairState
    {
        $state = $this->expiry->expire(key: $key, barrierId: $barrierId);
        $cookies->deny();

        $this->connection->transaction(function () use ($key): void {
            $this->connection->delete(
                query: 'DELETE FROM membership_credential_remembers WHERE account_id = :account_id AND identity_id = :identity_id',
                bindings: ['account_id' => $key->accountId, 'identity_id' => $key->identityId],
            );
            $this->connection->delete(
                query: 'DELETE FROM membership_credential_sessions WHERE account_id = :account_id AND identity_id = :identity_id',
                bindings: ['account_id' => $key->accountId, 'identity_id' => $key->identityId],
            );
        });

        return $state;
    }

    public function restore(PairKey $key, PairWitness $witness, CredentialCookieQueue $cookies): PairState
    {
        $cookies->deny();

        return $this->pairs->restore(key: $key, witness: $witness);
    }

    public function admitTrigger(MembershipTrigger $trigger): void
    {
        $this->inbox->admit(trigger: $trigger);
    }

    /** @param Closure(): void $pull */
    public function refresh(string $eventId, string $owner, int $now, Closure $pull): bool
    {
        return (new RefreshCoordinator(inbox: $this->inbox, refresh: $pull))->refresh(
            eventId: $eventId,
            owner: $owner,
            now: $now,
        );
    }
}
