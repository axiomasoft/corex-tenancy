<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Contracts;

use CoreX\Tenancy\DomainTrust\ChallengeConsumption;
use CoreX\Tenancy\DomainTrust\DomainChallengeBinding;
use CoreX\Tenancy\DomainTrust\DomainChallengeDraft;
use CoreX\Tenancy\DomainTrust\IssuedDomainChallenge;
use CoreX\Tenancy\DomainTrust\ObservedDomainProof;

interface DomainChallengeStore
{
    public function issue(DomainChallengeDraft $draft): IssuedDomainChallenge;

    public function consumeMatching(
        DomainChallengeBinding $binding,
        ObservedDomainProof $proof,
    ): ChallengeConsumption;
}
