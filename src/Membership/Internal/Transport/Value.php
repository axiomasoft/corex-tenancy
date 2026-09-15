<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Transport;

use CoreX\Tenancy\Central\CentralProtocolViolation;
use DateTimeImmutable;
use Throwable;

/** @internal Local wire profile validation, not deployed adoption. */
final class Value
{
    public static function uuid(string $value): string
    {
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) !== 1) {
            throw new CentralProtocolViolation('Expected canonical UUID.');
        }

        return $value;
    }

    public static function product(string $value): string
    {
        if (preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}$/D', $value) !== 1) {
            throw new CentralProtocolViolation('Invalid registered product identifier.');
        }

        return $value;
    }

    public static function counter(string $value): string
    {
        if (preg_match('/^(0|[1-9][0-9]{0,19})$/D', $value) !== 1
            || strlen($value) === 20 && strcmp($value, '18446744073709551615') > 0) {
            throw new CentralProtocolViolation('Invalid unsigned64 decimal counter.');
        }

        return $value;
    }

    public static function utc(string $value): DateTimeImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|\+00:00)$/D', $value) !== 1) {
            throw new CentralProtocolViolation('Expected UTC RFC3339 audit time.');
        }

        try {
            $date = new DateTimeImmutable($value);
        } catch (Throwable) {
            throw new CentralProtocolViolation('Invalid UTC audit date.');
        }

        if ($date->format('Y-m-d\TH:i:s') !== substr($value, 0, 19)) {
            throw new CentralProtocolViolation('Invalid UTC audit date.');
        }

        return $date;
    }

    /** @param array<string, mixed> $payload
     *  @param list<string> $fields */
    public static function fields(array $payload, array $fields): void
    {
        $keys = array_keys($payload);
        sort($keys);
        sort($fields);

        if ($keys !== $fields) {
            throw new CentralProtocolViolation('Missing or unnegotiated wire fields.');
        }
    }

    /** @param array<string, mixed> $payload */
    public static function string(array $payload, string $field): string
    {
        if (! is_string($payload[$field] ?? null)) {
            throw new CentralProtocolViolation('Expected wire string: '.$field);
        }

        return $payload[$field];
    }

    public static function profile(string $value): void
    {
        if ($value !== 'corex-membership/1') {
            throw new CentralProtocolViolation('Unnegotiated membership profile.');
        }
    }
}
