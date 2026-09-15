<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Credentials;

use CoreX\Tenancy\Membership\Internal\Persistence\PairKey;
use CoreX\Tenancy\Membership\Internal\Persistence\PairStore;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;

/** @internal Disposable reference issuer; no auth driver or runtime binding uses it. */
final readonly class FencedCredentialIssuer
{
    public function __construct(
        private ConnectionInterface $connection,
        private PairStore $pairs,
    ) {}

    /**
     * @param  array<string, scalar|null>  $metadata
     */
    public function issue(
        PairKey $key,
        string $operationId,
        string $sessionId,
        ?string $rememberToken,
        array $metadata,
    ): void {
        if ($sessionId === '' || $rememberToken === '') {
            throw new CredentialIssuanceDenied('Empty credential material is denied.');
        }

        try {
            $this->connection->transaction(function () use ($key, $operationId, $sessionId, $rememberToken, $metadata): void {
                if (! $this->pairs->commit(key: $key, operationId: $operationId)) {
                    throw new CredentialIssuanceDenied('The pair fence denied credential issuance.');
                }

                $sessionWritten = $this->connection->affectingStatement(
                    query: 'INSERT INTO membership_credential_sessions (session_id, account_id, identity_id, operation_id, metadata) VALUES (:session_id, :account_id, :identity_id, :operation_id, :metadata)',
                    bindings: [
                        'session_id' => $sessionId,
                        'account_id' => $key->accountId,
                        'identity_id' => $key->identityId,
                        'operation_id' => $operationId,
                        'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                    ],
                );

                if ($sessionWritten !== 1) {
                    throw new CredentialIssuanceDenied('Session credential write was not acknowledged.');
                }

                if ($rememberToken === null) {
                    return;
                }

                $rememberWritten = $this->connection->affectingStatement(
                    query: 'INSERT INTO membership_credential_remembers (remember_token, account_id, identity_id, operation_id) VALUES (:remember_token, :account_id, :identity_id, :operation_id)',
                    bindings: [
                        'remember_token' => $rememberToken,
                        'account_id' => $key->accountId,
                        'identity_id' => $key->identityId,
                        'operation_id' => $operationId,
                    ],
                );

                if ($rememberWritten !== 1) {
                    throw new CredentialIssuanceDenied('Remember credential write was not acknowledged.');
                }
            });
        } catch (CredentialIssuanceDenied $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            throw new CredentialIssuanceDenied(message: 'Credential transaction failed.', previous: $exception);
        }
    }
}
