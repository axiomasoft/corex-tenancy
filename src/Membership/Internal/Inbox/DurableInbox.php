<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Inbox;

use CoreX\Tenancy\Membership\Internal\Transport\MembershipTrigger;
use Illuminate\Database\ConnectionInterface;

/** @internal Unregistered durable trigger reference; a trigger never grants membership. */
final readonly class DurableInbox
{
    private const string ReferenceTable = 'membership_trigger_inbox';

    private const string PhysicalTable = 'tnt_membership_inbox';

    public function __construct(
        private ConnectionInterface $connection,
        private string $table = self::ReferenceTable,
    ) {
        if (! in_array($table, [self::ReferenceTable, self::PhysicalTable], true)) {
            throw new InboxConflict('Inbox table is not an approved mapping.');
        }
    }

    public function admit(MembershipTrigger $trigger): void
    {
        $this->connection->transaction(function () use ($trigger): void {
            $this->connection->affectingStatement(
                query: 'INSERT INTO '.$this->table.' (event_id, logical_digest, delivery_id, status, attempts) VALUES (:event_id, :logical_digest, :delivery_id, :status, 0) ON CONFLICT (event_id) DO NOTHING',
                bindings: [
                    'event_id' => $trigger->eventId,
                    'logical_digest' => $trigger->logicalDigest(),
                    'delivery_id' => $trigger->deliveryId,
                    'status' => 'pending',
                ],
            );

            $existing = $this->connection->selectOne(
                query: 'SELECT logical_digest FROM '.$this->table.' WHERE event_id = :event_id FOR UPDATE',
                bindings: ['event_id' => $trigger->eventId],
            );

            if (! hash_equals((string) $existing->logical_digest, $trigger->logicalDigest())) {
                throw new InboxConflict('Conflicting logical event ID reuse.');
            }

            $this->connection->affectingStatement(
                query: 'UPDATE '.$this->table.' SET delivery_id = :delivery_id WHERE event_id = :event_id',
                bindings: [
                    'delivery_id' => $trigger->deliveryId,
                    'event_id' => $trigger->eventId,
                ],
            );
        });
    }

    public function claim(string $eventId, string $owner, int $now): bool
    {
        $this->assertOwner($owner);

        return $this->connection->affectingStatement(
            query: 'UPDATE '.$this->table.' SET owner_id = :owner_id, lease_until = :lease_until, attempts = attempts + 1 WHERE event_id = :event_id AND status = :status AND (lease_until IS NULL OR lease_until <= :now)',
            bindings: [
                'owner_id' => $owner,
                'lease_until' => $now + 30,
                'event_id' => $eventId,
                'status' => 'pending',
                'now' => $now,
            ],
        ) === 1;
    }

    public function acknowledge(string $eventId, string $owner, int $now): bool
    {
        $this->assertOwner($owner);

        return $this->connection->affectingStatement(
            query: 'UPDATE '.$this->table.' SET status = :acknowledged, owner_id = NULL, lease_until = NULL WHERE event_id = :event_id AND status = :pending AND owner_id = :owner_id AND lease_until > :now',
            bindings: [
                'acknowledged' => 'acknowledged',
                'pending' => 'pending',
                'event_id' => $eventId,
                'owner_id' => $owner,
                'now' => $now,
            ],
        ) === 1;
    }

    public function isPending(string $eventId): bool
    {
        return $this->connection->scalar(
            query: 'SELECT status FROM '.$this->table.' WHERE event_id = :event_id',
            bindings: ['event_id' => $eventId],
        ) === 'pending';
    }

    private function assertOwner(string $owner): void
    {
        if ($this->table === self::PhysicalTable && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $owner) !== 1) {
            throw new InboxConflict('Physical inbox owner must be a canonical UUID.');
        }
    }
}
