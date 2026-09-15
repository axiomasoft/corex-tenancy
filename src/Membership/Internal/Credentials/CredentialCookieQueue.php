<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Credentials;

/** @internal Reference response seam; it is deliberately not a Laravel auth guard. */
final class CredentialCookieQueue
{
    /** @var list<array{name: string, value: string}> */
    private array $cookies = [];

    private bool $denied = false;

    public function queueSession(string $value): void
    {
        $this->queue(name: 'session', value: $value);
    }

    public function queueRemember(string $value): void
    {
        $this->queue(name: 'remember', value: $value);
    }

    public function deny(): void
    {
        $this->denied = true;
        $this->cookies = [];
    }

    /** @return list<array{name: string, value: string}> */
    public function release(): array
    {
        return $this->denied ? [] : $this->cookies;
    }

    private function queue(string $name, string $value): void
    {
        if ($this->denied || $value === '') {
            return;
        }

        $this->cookies[] = ['name' => $name, 'value' => $value];
    }
}
