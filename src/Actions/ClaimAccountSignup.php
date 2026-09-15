<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Actions;

use CoreX\Tenancy\Contracts\ProvisioningClaim;
use CoreX\Tenancy\Contracts\TemplateIntegrityVerifier;
use CoreX\Tenancy\Provisioning\ClaimConflict;
use CoreX\Tenancy\Provisioning\ClaimRequest;
use CoreX\Tenancy\Provisioning\ClaimResult;
use CoreX\Tenancy\Provisioning\ClaimUnavailable;
use CoreX\Tenancy\Provisioning\QualifiedTemplate;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class ClaimAccountSignup implements ProvisioningClaim
{
    private const string SignupKeyIndex = 'root_accounts_signup_key_uq';

    private const int MaxTransactionAttempts = 3;

    public function __construct(
        private readonly string $centralConnection,
        private readonly TemplateIntegrityVerifier $templateIntegrity,
    ) {}

    public function claim(ClaimRequest $request): ClaimResult
    {
        $this->validateRequest($request);

        for ($attempt = 1; $attempt <= self::MaxTransactionAttempts; $attempt++) {
            try {
                return $this->connection()->transaction(
                    callback: fn (): ClaimResult => $this->claimInTransaction($request),
                    attempts: 1,
                );
            } catch (QueryException $exception) {
                if ($this->isNamedSignupKeyViolation($exception)) {
                    continue;
                }

                if (! $this->isRetryableTransactionFailure($exception) || $attempt === self::MaxTransactionAttempts) {
                    throw $exception;
                }
            }
        }

        throw new ClaimUnavailable('The signup claim could not be reconciled.');
    }

    private function claimInTransaction(ClaimRequest $request): ClaimResult
    {
        $connection = $this->connection();

        $connection->select(
            query: 'SELECT pg_advisory_xact_lock(CAST(? AS bigint))',
            bindings: [$this->advisoryLockId($request->requestKey)],
        );

        $bindings = $connection->table('root_accounts')
            ->select(['id', 'status', 'deleted_at', 'data'])
            ->whereRaw("data->>'signup_key' = ?", [$request->requestKey])
            ->orderBy('id')
            ->lock('FOR UPDATE')
            ->get();

        if ($bindings->count() > 1) {
            throw new ClaimConflict('The signup key has ambiguous account bindings.');
        }

        if ($bindings->count() === 1) {
            return $this->replayResult($bindings->first(), $request);
        }

        $qualifiedTemplate = $this->templateIntegrity->qualify($request);
        $this->validateQualifiedTemplate($qualifiedTemplate, $request);
        $this->lockAndVerifyTemplate($connection, $qualifiedTemplate);

        $candidate = $connection->table('root_accounts')
            ->select(['id', 'cluster_id', 'template_version', 'embedding_model', 'embedding_dim', 'data'])
            ->where('cluster_id', $request->clusterId)
            ->where('template_version', $qualifiedTemplate->templateVersion)
            ->where('status', 'pending')
            ->whereNull('deleted_at')
            ->whereJsonDoesntContainKey('data->signup_key')
            ->whereJsonDoesntContainKey('data->signup_request_digest')
            ->whereJsonDoesntContainKey('data->signup_cluster_id')
            ->whereJsonDoesntContainKey('data->signup_template_id')
            ->orderBy('id')
            ->lock('FOR UPDATE SKIP LOCKED')
            ->first();

        if ($candidate === null) {
            throw new ClaimUnavailable('No qualified pending account is available.');
        }

        $payload = json_encode([
            'signup_key' => $request->requestKey,
            'signup_request_digest' => $request->requestDigest,
            'signup_cluster_id' => $request->clusterId,
            'signup_template_id' => $request->templateId,
        ], JSON_THROW_ON_ERROR);

        $updated = $connection->update(
            query: "UPDATE root_accounts
                SET status = ?, data = data || ?::jsonb, updated_at = CURRENT_TIMESTAMP
                WHERE id = ?
                  AND status = 'pending'
                  AND deleted_at IS NULL
                  AND NOT jsonb_exists(data, 'signup_key')
                  AND NOT jsonb_exists(data, 'signup_request_digest')
                  AND NOT jsonb_exists(data, 'signup_cluster_id')
                  AND NOT jsonb_exists(data, 'signup_template_id')",
            bindings: ['provisioning', $payload, $candidate->id],
        );

        if ($updated !== 1) {
            throw new ClaimUnavailable('The pending account changed before reservation.');
        }

        return new ClaimResult(accountId: $candidate->id, replayed: false);
    }

    private function replayResult(object $binding, ClaimRequest $request): ClaimResult
    {
        $data = json_decode($binding->data, true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)
            || $binding->deleted_at !== null
            || $binding->status === 'deleted'
            || ($data['signup_key'] ?? null) !== $request->requestKey
            || ($data['signup_request_digest'] ?? null) !== $request->requestDigest
            || ($data['signup_cluster_id'] ?? null) !== $request->clusterId
            || ($data['signup_template_id'] ?? null) !== $request->templateId) {
            throw new ClaimConflict('The signup key is already bound to a different request.');
        }

        return new ClaimResult(accountId: $binding->id, replayed: true);
    }

    private function lockAndVerifyTemplate(Connection $connection, QualifiedTemplate $qualifiedTemplate): void
    {
        $cluster = $connection->table('root_clusters')
            ->where('id', $qualifiedTemplate->clusterId)
            ->where('status', 'active')
            ->where('template_version', $qualifiedTemplate->templateVersion)
            ->lock('FOR UPDATE')
            ->first(['id']);

        $template = $connection->table('root_templates')
            ->where('id', $qualifiedTemplate->templateId)
            ->where('version', $qualifiedTemplate->templateVersion)
            ->where('status', 'ready')
            ->lock('FOR UPDATE')
            ->first(['id']);

        if ($cluster === null || $template === null) {
            throw new ClaimUnavailable('The qualified template is no longer current.');
        }
    }

    private function validateRequest(ClaimRequest $request): void
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $request->requestKey) !== 1) {
            throw new ClaimConflict('The signup key is invalid.');
        }

        if (! $this->isCanonicalUuid($request->clusterId) || ! $this->isCanonicalUuid($request->templateId)) {
            throw new ClaimConflict('The cluster and template identifiers must be canonical UUIDs.');
        }

        if (preg_match('/^[a-f0-9]{64}$/D', $request->requestDigest) !== 1) {
            throw new ClaimConflict('The request digest is invalid.');
        }
    }

    private function validateQualifiedTemplate(QualifiedTemplate $qualifiedTemplate, ClaimRequest $request): void
    {
        if ($qualifiedTemplate->clusterId !== $request->clusterId
            || $qualifiedTemplate->templateId !== $request->templateId
            || $qualifiedTemplate->templateVersion < 1
            || preg_match('/^[a-f0-9]{64}$/D', $qualifiedTemplate->artifactDigest) !== 1) {
            throw new ClaimUnavailable('The template proof does not match the request.');
        }
    }

    private function advisoryLockId(string $requestKey): string
    {
        $bytes = substr(hash('sha256', "corex:signup:v1:{$requestKey}", binary: true), 0, 8);

        return (string) unpack('q', strrev($bytes))[1];
    }

    private function isCanonicalUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value) === 1;
    }

    private function isNamedSignupKeyViolation(QueryException $exception): bool
    {
        return $this->sqlState($exception) === '23505'
            && str_contains($exception->getMessage(), self::SignupKeyIndex);
    }

    private function isRetryableTransactionFailure(QueryException $exception): bool
    {
        return in_array($this->sqlState($exception), ['40001', '40P01'], strict: true);
    }

    private function sqlState(QueryException $exception): ?string
    {
        $previous = $exception->getPrevious();

        return $previous?->getCode() ?: null;
    }

    private function connection(): Connection
    {
        return DB::connection($this->centralConnection);
    }
}
