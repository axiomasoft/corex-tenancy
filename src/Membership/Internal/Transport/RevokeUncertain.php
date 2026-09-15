<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Transport;

use RuntimeException;

/** @internal No authoritative local success may be inferred from this outcome. */
final class RevokeUncertain extends RuntimeException
{
    public function __construct(public readonly string $requestId)
    {
        parent::__construct('Central revoke outcome uncertain; retain original request ID and payload.');
    }
}
