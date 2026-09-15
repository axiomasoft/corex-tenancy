<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Transport;

use Closure;
use CoreX\Tenancy\Central\CentralProtocolViolation;
use CoreX\Tenancy\Central\CentralUnauthorized;
use CoreX\Tenancy\Central\CentralUnavailable;
use CoreX\Tenancy\Central\MembershipQuery;
use CoreX\Tenancy\Central\MembershipSnapshot;
use CoreX\Tenancy\Central\RevokeMembershipReceipt;
use CoreX\Tenancy\Central\RevokeMembershipRequest;
use CoreX\Tenancy\Contracts\CentralApiClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** @internal Unregistered local transport, no default endpoints or authority writer. */
final class StrictTransport implements CentralApiClient
{
    /** @var array<string, true> */
    private array $readRequests = [];

    /** @var array<string, string> */
    private array $revokeRequests = [];

    /** @var array<string, MembershipSnapshot> */
    private array $snapshots = [];

    /** @param Closure(): int $clock
     *  @param Closure(): (array{accountId: string, product: string}|null) $context
     *  @param Closure(RevokeMembershipRequest): bool $removalPermission */
    public function __construct(
        private readonly ?ChannelBinding $binding,
        private readonly string $accessToken,
        private readonly AccessTokenValidator $tokenValidator,
        private readonly Closure $clock,
        private readonly Closure $context,
        private readonly Closure $removalPermission,
    ) {}

    public function membershipFor(MembershipQuery $query): MembershipSnapshot
    {
        $http = $this->request(accountId: $query->accountId, product: $query->product, capability: 'membership.read');

        if (isset($this->readRequests[$query->requestId])) {
            throw new CentralProtocolViolation('Read correlation ID must be fresh.');
        }
        $this->readRequests[$query->requestId] = true;

        try {
            // Explicit URI bytes avoid framework query/body ambiguity.
            $response = $http->get($this->binding->readUri.'?'.$query->wireQuery());
        } catch (ConnectionException) {
            throw new CentralUnavailable('Central membership read unavailable.');
        }
        $this->assertResponse(response: $response, revokeId: null);
        $snapshot = MembershipSnapshot::fromPayload(StrictJson::object($response->body()));
        $this->assertEcho(requestId: $query->requestId, identityId: $query->identityId, accountId: $query->accountId, product: $query->product, response: $snapshot);
        $key = $query->accountId.':'.$query->identityId.':'.$query->product;
        $previous = $this->snapshots[$key] ?? null;

        if ($previous !== null && (self::compare($snapshot->revision, $previous->revision) < 0
            || self::compare($snapshot->revokeGeneration, $previous->revokeGeneration) < 0
            || $snapshot->revision === $previous->revision && ($snapshot->state !== $previous->state
                || $snapshot->membershipId !== $previous->membershipId || $snapshot->revokeGeneration !== $previous->revokeGeneration))) {
            throw new CentralProtocolViolation('Regressed or conflicting snapshot history.');
        }
        $this->snapshots[$key] = $snapshot;

        return $snapshot;
    }

    public function revokeMembership(RevokeMembershipRequest $request): RevokeMembershipReceipt
    {
        // Actor is audit-only. This injected consumer permission decision is
        // required even when an acting identity or a service capability is present.
        if (! ($this->removalPermission)($request)) {
            throw new CentralUnauthorized('Consumer removal permission denied.');
        }
        $http = $this->request(accountId: $request->accountId, product: $request->product, capability: 'membership.revoke');

        if (! $this->binding->deduplicationQualified) {
            throw new CentralUnavailable('Revoke idempotency profile is unqualified.');
        }
        $payload = $request->payload();
        $digest = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        if (isset($this->revokeRequests[$request->requestId]) && $this->revokeRequests[$request->requestId] !== $digest) {
            throw new CentralProtocolViolation('Conflicting local revoke request ID reuse.');
        }
        $this->revokeRequests[$request->requestId] = $digest;

        try {
            $response = $http->post($this->binding->revokeUri, $payload);
        } catch (ConnectionException) {
            throw new RevokeUncertain($request->requestId);
        }
        $this->assertResponse(response: $response, revokeId: $request->requestId);
        $receipt = RevokeMembershipReceipt::fromPayload(StrictJson::object($response->body()));
        $this->assertEcho(requestId: $request->requestId, identityId: $request->identityId, accountId: $request->accountId, product: $request->product, response: $receipt);

        return $receipt;
    }

    private function request(string $accountId, string $product, string $capability): PendingRequest
    {
        $context = ($this->context)();

        if ($context === null || $context['accountId'] !== $accountId || $context['product'] !== $product) {
            throw new CentralUnauthorized('Trusted tenant context mismatch.');
        }

        if ($this->binding === null || $this->accessToken === '') {
            throw new CentralUnavailable('Supplied channel profile is absent.');
        }
        $now = ($this->clock)();
        $this->binding->assertUsable(accountId: $accountId, product: $product, now: $now);
        $this->tokenValidator->validate(token: $this->accessToken, binding: $this->binding, capability: $capability, now: $now);

        return Http::acceptJson()->asJson()->withToken($this->accessToken)
            ->timeout(5)->connectTimeout(5)
            ->withOptions(['cert' => $this->binding->certificatePath, 'ssl_key' => $this->binding->keyPath, 'verify' => $this->binding->caPath, 'allow_redirects' => false])
            ->withHeaders(['Cache-Control' => 'no-store', 'X-CoreX-Membership-Profile' => 'corex-membership/1']);
    }

    private function assertResponse(Response $response, ?string $revokeId): void
    {
        $status = $response->status();

        if ($status === 401 || $status === 403) {
            throw new CentralUnauthorized('Central denied membership request.');
        }

        if ($status >= 500 || $status === 429) {
            if ($revokeId !== null) {
                throw new RevokeUncertain($revokeId);
            }

            throw new CentralUnavailable('Central read unavailable.');
        }

        if ($status !== 200 || $response->header('Cache-Control') !== 'no-store'
            || $response->header('X-CoreX-Membership-Profile') !== 'corex-membership/1'
            || strtolower(trim(explode(';', $response->header('Content-Type'))[0])) !== 'application/json'
            || $response->header('Age') !== '' || $response->header('ETag') !== '' || $response->header('Last-Modified') !== '') {
            throw new CentralProtocolViolation('Unexpected or cached membership response.');
        }
    }

    private function assertEcho(string $requestId, string $identityId, string $accountId, string $product, MembershipSnapshot|RevokeMembershipReceipt $response): void
    {
        if ($response->requestId !== $requestId || $response->identityId !== $identityId || $response->accountId !== $accountId || $response->product !== $product) {
            throw new CentralProtocolViolation('Membership response correlation/context mismatch.');
        }
    }

    private static function compare(string $left, string $right): int
    {
        return strlen($left) <=> strlen($right) ?: strcmp($left, $right);
    }
}
