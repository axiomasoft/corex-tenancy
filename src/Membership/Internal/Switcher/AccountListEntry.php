<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Switcher;

use CoreX\Tenancy\Central\CentralProtocolViolation;
use CoreX\Tenancy\Membership\Internal\Transport\Value;

/** @internal Local, validated display data. It is never an authorization grant. */
final readonly class AccountListEntry
{
    public function __construct(
        public string $accountId,
        public string $name,
        public string $slug,
        public string $primaryHost,
        public string $status,
    ) {
        Value::uuid($accountId);

        if (trim($name) === '' || preg_match('/^[a-z0-9][a-z0-9-]{0,62}$/D', $slug) !== 1) {
            throw new CentralProtocolViolation('Invalid account display entry.');
        }

        if (filter_var($primaryHost, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false || trim($status) === '') {
            throw new CentralProtocolViolation('Invalid account display entry.');
        }
    }
}
