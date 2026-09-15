<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Switcher;

use CoreX\Tenancy\Central\CentralProtocolViolation;
use CoreX\Tenancy\Membership\Internal\Transport\Value;
use DateTimeImmutable;

/** @internal Local, authoritative read result; no public DTO is introduced. */
final readonly class AccountsResult
{
    /** @param list<mixed> $accounts */
    public function __construct(
        public string $identityId,
        public string $product,
        public string $membershipVersion,
        public array $accounts,
        public DateTimeImmutable $observedAt,
    ) {
        Value::uuid($identityId);
        Value::product($product);

        if (trim($membershipVersion) === '' || $observedAt->getTimezone()->getName() !== '+00:00') {
            throw new CentralProtocolViolation('Invalid accounts result metadata.');
        }

        $accountIds = [];
        foreach ($accounts as $account) {
            if (! $account instanceof AccountListEntry || isset($accountIds[$account->accountId])) {
                throw new CentralProtocolViolation('Accounts result must be a unique typed list.');
            }

            $accountIds[$account->accountId] = true;
        }
    }

    public function isFor(AccountsQuery $query): bool
    {
        return $this->identityId === $query->identityId && $this->product === $query->product;
    }
}
