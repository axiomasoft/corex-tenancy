<?php

declare(strict_types=1);

namespace CoreX\Tenancy\DomainTrust;

use CoreX\Tenancy\Contracts\DomainChallengeStore;
use CoreX\Tenancy\Contracts\DomainOwnershipProofVerifier;
use CoreX\Tenancy\Contracts\DomainTrustAuthorizer;
use CoreX\Tenancy\Contracts\DomainTrustPolicy;

final class DomainTrustCoordinator
{
    public function __construct(
        private readonly DomainTrustPolicy $policy,
        private readonly DomainChallengeStore $challengeStore,
        private readonly DomainOwnershipProofVerifier $proofVerifier,
        private readonly DomainTrustAuthorizer $authorizer,
        private readonly DomainTrustStateWriter $stateWriter,
        private readonly int $challengeTtlSeconds,
    ) {}

    public function issue(DomainTrustActor $actor, string $accountId, string $host): IssuedDomainChallenge
    {
        $normalizedHost = $this->policy->normalize($host);
        $this->authorizer->assertAllowed(
            actor: $actor,
            operation: DomainTrustOperation::Issue,
            accountId: $accountId,
            host: $normalizedHost,
        );

        $generation = $this->stateWriter->generationFor(accountId: $accountId, host: $normalizedHost);
        $expiresAt = now()->addSeconds($this->challengeTtlSeconds)->toDateTimeImmutable();

        return $this->challengeStore->issue(new DomainChallengeDraft(
            accountId: $accountId,
            host: $normalizedHost,
            generation: $generation,
            expiresAt: $expiresAt,
        ));
    }

    public function verify(DomainTrustActor $actor, string $accountId, string $host): DomainTrustTransition
    {
        $normalizedHost = $this->policy->normalize($host);
        $this->authorizer->assertAllowed(
            actor: $actor,
            operation: DomainTrustOperation::Verify,
            accountId: $accountId,
            host: $normalizedHost,
        );

        $proof = $this->proofVerifier->observe($normalizedHost);

        if (! $this->policy->accepts(host: $normalizedHost, proof: $proof)) {
            throw new DomainTrustDenied('The ownership proof did not satisfy the policy.');
        }

        return $this->stateWriter->verify(
            accountId: $accountId,
            host: $normalizedHost,
            challengeStore: $this->challengeStore,
            proof: $proof,
        );
    }

    public function revoke(DomainTrustActor $actor, string $accountId, string $host): DomainTrustTransition
    {
        $normalizedHost = $this->policy->normalize($host);
        $this->authorizer->assertAllowed(
            actor: $actor,
            operation: DomainTrustOperation::Revoke,
            accountId: $accountId,
            host: $normalizedHost,
        );

        return $this->stateWriter->revoke(accountId: $accountId, host: $normalizedHost);
    }

    public function reassign(
        DomainTrustActor $actor,
        string $fromAccountId,
        string $toAccountId,
        string $host,
    ): DomainTrustTransition {
        $normalizedHost = $this->policy->normalize($host);
        $this->authorizer->assertAllowed(
            actor: $actor,
            operation: DomainTrustOperation::Reassign,
            accountId: $fromAccountId,
            host: $normalizedHost,
        );
        $this->authorizer->assertAllowed(
            actor: $actor,
            operation: DomainTrustOperation::Reassign,
            accountId: $toAccountId,
            host: $normalizedHost,
        );

        return $this->stateWriter->reassign(
            fromAccountId: $fromAccountId,
            toAccountId: $toAccountId,
            host: $normalizedHost,
        );
    }
}
