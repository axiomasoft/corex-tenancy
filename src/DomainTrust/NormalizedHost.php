<?php

declare(strict_types=1);

namespace CoreX\Tenancy\DomainTrust;

use InvalidArgumentException;

final readonly class NormalizedHost
{
    public string $value;

    public function __construct(string $host)
    {
        if ($host === '' || $host !== trim($host) || str_contains($host, ':') || str_ends_with($host, '.')) {
            throw new InvalidArgumentException('Host must be an unambiguous DNS hostname.');
        }

        $normalized = strtolower($host);

        if (! mb_check_encoding($normalized, 'ASCII') || strlen($normalized) > 253 || $normalized === 'localhost') {
            throw new InvalidArgumentException('Host must be a non-reserved ASCII hostname.');
        }

        $labels = explode('.', $normalized);

        if (count($labels) < 2 || array_any($labels, static fn (string $label): bool => preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/', $label) !== 1)) {
            throw new InvalidArgumentException('Host must contain valid DNS labels.');
        }

        $this->value = $normalized;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
