<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Transport;

use CoreX\Tenancy\Central\CentralProtocolViolation;
use CoreX\Tenancy\Central\CentralUnauthorized;
use CoreX\Tenancy\Central\MembershipReason;
use DateTimeImmutable;

/** @internal Bounded in-memory conflict fixture; durable inbox belongs to P4.6. */
final class TriggerParser
{
    /** @var array<string, string> */
    private array $events = [];

    public function __construct(
        private readonly JwsVerifier $verifier,
        private readonly string $issuer,
        private readonly string $audience,
        private readonly string $accountId,
        private readonly string $product,
    ) {}

    public function parse(string $compact, int $now): MembershipTrigger
    {
        $p = $this->verifier->verify(compact: $compact, type: 'corex-membership-trigger+jwt', purpose: 'trigger');
        Value::fields($p, ['iss', 'aud', 'event_id', 'jti', 'iat', 'exp', 'profile_version', 'identity_id', 'account_id', 'product', 'kind', 'commit_ref', 'reason', 'acting_identity_id', 'occurred_at']);

        if ($this->issuer === '' || $this->audience === '' || $p['iss'] !== $this->issuer || $p['aud'] !== $this->audience
            || $p['account_id'] !== $this->accountId || $p['product'] !== $this->product
            || ! is_int($p['iat']) || ! is_int($p['exp']) || $p['iat'] < 0 || $p['exp'] <= $p['iat']
            || $p['exp'] - $p['iat'] > 300 || $p['iat'] > $now + 30 || $p['exp'] <= $now - 30) {
            throw new CentralUnauthorized('Trigger context or time denied.');
        }

        if ($p['acting_identity_id'] !== null && ! is_string($p['acting_identity_id'])) {
            throw new CentralProtocolViolation('Invalid nullable audit actor.');
        }
        $kind = MembershipEventKind::tryFrom(Value::string($p, 'kind')) ?? throw new CentralProtocolViolation('Unknown trigger kind.');
        $reason = MembershipReason::tryFrom(Value::string($p, 'reason')) ?? throw new CentralProtocolViolation('Unknown trigger reason.');
        $commitRef = Value::string($p, 'commit_ref');

        if (preg_match('/^[a-zA-Z0-9._:-]{1,128}$/D', $commitRef) !== 1) {
            throw new CentralProtocolViolation('Invalid trigger commit reference.');
        }
        $trigger = new MembershipTrigger(profileVersion: Value::string($p, 'profile_version'), eventId: Value::string($p, 'event_id'), deliveryId: Value::string($p, 'jti'), identityId: Value::string($p, 'identity_id'), accountId: Value::string($p, 'account_id'), product: Value::string($p, 'product'), kind: $kind, commitRef: $commitRef, reason: $reason, actingIdentityId: $p['acting_identity_id'], occurredAt: Value::utc(Value::string($p, 'occurred_at')), issuedAt: new DateTimeImmutable('@'.$p['iat']), expiresAt: new DateTimeImmutable('@'.$p['exp']));
        $digest = $trigger->logicalDigest();

        if (isset($this->events[$trigger->eventId]) && ! hash_equals($this->events[$trigger->eventId], $digest)) {
            throw new CentralProtocolViolation('Conflicting logical event ID reuse.');
        }
        $this->events[$trigger->eventId] = $digest;

        return $trigger;
    }
}
