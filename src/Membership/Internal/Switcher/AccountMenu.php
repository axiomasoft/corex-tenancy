<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Switcher;

/** @internal An unavailable read deliberately exposes an empty menu. */
final readonly class AccountMenu
{
    private function __construct(public bool $available, public ?AccountsResult $result) {}

    public static function available(AccountsResult $result): self
    {
        return new self(available: true, result: $result);
    }

    public static function unavailable(): self
    {
        return new self(available: false, result: null);
    }
}
