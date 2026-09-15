<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Transport;

use CoreX\Tenancy\Central\CentralProtocolViolation;
use JsonException;
use stdClass;

/** @internal Bounded decoder rejecting duplicate members at every depth. */
final class StrictJson
{
    private int $offset = 0;

    private function __construct(private readonly string $wire) {}

    /** @return array<string, mixed> */
    public static function object(string $wire): array
    {
        if (strlen($wire) > 65536) {
            throw new CentralProtocolViolation('Wire object exceeds local profile limit.');
        }

        try {
            // Validate grammar/UTF-8 first; recursive scan preserves duplicate keys.
            json_decode($wire, flags: JSON_THROW_ON_ERROR, depth: 16);
            $parser = new self($wire);
            $value = $parser->value(0);
            $parser->whitespace();

            if (! $value instanceof stdClass || $parser->offset !== strlen($wire)) {
                throw new CentralProtocolViolation('Expected one wire JSON object.');
            }

            return (array) $value;
        } catch (JsonException) {
            throw new CentralProtocolViolation('Malformed wire JSON.');
        }
    }

    private function value(int $depth): mixed
    {
        if ($depth > 16) {
            throw new CentralProtocolViolation('Wire object depth exceeded.');
        }
        $this->whitespace();
        $char = $this->wire[$this->offset] ?? '';

        if ($char === '"') {
            return $this->string();
        }

        if ($char === '{' || $char === '[') {
            $object = $char === '{';
            $end = $object ? '}' : ']';
            $this->offset++;
            $this->whitespace();
            $items = [];

            if (($this->wire[$this->offset] ?? '') !== $end) {
                do {
                    if ($object) {
                        $key = $this->string();
                        $this->whitespace();
                        $this->offset++; // Colon, already grammar-validated.

                        if (array_key_exists($key, $items)) {
                            throw new CentralProtocolViolation('Duplicate wire JSON member.');
                        }
                        $items[$key] = $this->value($depth + 1);
                    } else {
                        $items[] = $this->value($depth + 1);
                    }
                    $this->whitespace();

                    if (($this->wire[$this->offset] ?? '') !== ',') {
                        break;
                    }
                    $this->offset++;
                    $this->whitespace();
                } while (true);
            }
            $this->offset++;

            return $object ? (object) $items : $items;
        }
        preg_match('/\G(?:true|false|null|-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+-]?[0-9]+)?)/', $this->wire, $match, offset: $this->offset);
        $this->offset += strlen($match[0]);

        return json_decode($match[0], flags: JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
    }

    private function string(): string
    {
        $start = $this->offset++;
        while ($this->offset < strlen($this->wire)) {
            $char = $this->wire[$this->offset++];

            if ($char === '\\') {
                $this->offset++;
            } elseif ($char === '"') {
                break;
            }
        }

        return json_decode(substr($this->wire, $start, $this->offset - $start), flags: JSON_THROW_ON_ERROR);
    }

    private function whitespace(): void
    {
        $this->offset += strspn($this->wire, " \t\r\n", $this->offset);
    }
}
