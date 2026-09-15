<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Inbox;

use CoreX\Tenancy\Membership\Internal\Transport\MembershipTrigger;
use Illuminate\Database\ConnectionInterface;

/** @internal Unregistered durable trigger reference; a trigger never grants membership. */
final readonly class DurableInbox
{
    public function __construct(private ConnectionInterface $connection) {}

    public function admit(MembershipTrigger $trigger): void
    {
        $this->connection->transaction(function () use ($trigger): void {
            $existing = $this->connection->selectOne(
                query: 'SELECT logical_digest FROM membership_trigger_inbox WHERE event_id = :event_id FOR UPDATE',
                bindings: ['event_id' => $trigger->eventId],
            );

            if ($existing !== null) {
                if (! hash_equals((string) $existing->logical_digest, $trigger->logicalDigest())) {
                    throw new InboxConflict('Conflicting logical event ID reuse.');
                }

                $this->connection->affectingStatement(
                    query: 'UPDATE membership_trigger_inbox SET delivery_id = :delivery_id WHERE event_id = :event_id',
                    bindings: ['delivery_id' => $trigger->deliveryId, 'event_id' => $trigger->eventId],
                );

                return;
            }

            $this->connection->affectingStatement(
                query: 'INSERT INTO membership_trigger_inbox (event_id, logical_digest, delivery_id, status, attempts) VALUES (:event_id, :logical_digest, :delivery_id, :status, 0)',
                bindings: [
                    'event_id' => $trigger->eventId,
                    'logical_digest' => $trigger->logicalDigest(),
                    'delivery_id' => $trigger->deliveryId,
                    'status' => 'pending',
                ],
            );
        });
    }

    public function claim(string $eventId, string $owner, int $now): bool
    {
        return $this->connection->affectingStatement(
            query: 'UPDATE membership_trigger_inbox SET owner_id = :owner_id, lease_until = :lease_until, attempts = attempts + 1 WHERE event_id = :event_id AND status = :status AND (lease_until IS NULL OR lease_until <= :now)',
            bindings: [
                'owner_id' => $owner,
                'lease_until' => $now + 30,
                'event_id' => $eventId,
                'status' => 'pending',
                'now' => $now,
            ],
        ) === 1;
    }

    public function acknowledge(string $eventId, string $owner): bool
    {
        return $this->connection->affectingStatement(
            query: 'UPDATE membership_trigger_inbox SET status = :acknowledged, owner_id = NULL, lease_until = NULL WHERE event_id = :event_id AND status = :pending AND owner_id = :owner_id',
            bindings: [
                'acknowledged' => 'acknowledged',
                'pending' => 'pending',
                'event_id' => $eventId,
                'owner_id' => $owner,
            ],
        ) === 1;
    }

    public function isPending(string $eventId): bool
    {
        return $this->connection->scalar(
            query: 'SELECT status FROM membership_trigger_inbox WHERE event_id = :event_id',
            bindings: ['event_id' => $eventId],
        ) === 'pending';
    }
}
