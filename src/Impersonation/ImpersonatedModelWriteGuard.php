<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Impersonation;

use Closure;
use CoreX\Exceptions\ImpersonationRestrictedException;
use CoreX\Models\AuditLog;
use CoreX\Tenancy\Contracts\ImpersonationService;
use CoreX\Tenancy\Models\ImpersonationGrant;
use Illuminate\Database\Eloquent\Model;

/**
 * L2 — Eloquent layer of the read-only invariant (D141/D142): global
 * wildcard listeners on `eloquent.saving`/`eloquent.deleting`/
 * `eloquent.restoring` reject a write on EVERY model — including ones that
 * do not exist in the tree yet — while an impersonation is active, except
 * the two models a compliance session cannot function without. NOT a
 * hermetic gate (D142, RAG:✅ Laravel 13 `eloquent.md`): mass-update/delete
 * and the `DB` facade never dispatch these events at all; L1 (web) and L3
 * (privileged core services) cover what L2 structurally cannot.
 *
 * @internal spec: B-11 §5.9, D141/D142
 */
final class ImpersonatedModelWriteGuard
{
    /**
     * CLOSED literal, not a config key (D116/D121 — a config-gated exempt
     * list would let the gate be switched off by settings): `AuditLog` so
     * the compliance record itself can be written while `queue=sync` runs
     * it inline; `ImpersonationGrant` because the grant row is the carrier
     * of the session fact itself (its own `used_at`/`revoked_at` writes).
     *
     * @var list<class-string>
     */
    private const EXEMPT = [
        AuditLog::class,
        ImpersonationGrant::class,
    ];

    private static bool $allowing = false;

    public function __construct(
        private readonly ImpersonationService $impersonation,
    ) {}

    /** @param array<int, mixed> $payload */
    public function handle(string $eventName, array $payload): void
    {
        if (self::$allowing || $this->impersonation->state() === null) {
            return;
        }

        $model = $payload[0] ?? null;

        if (! $model instanceof Model || in_array($model::class, self::EXEMPT, true)) {
            return;
        }

        throw ImpersonationRestrictedException::forModelWrite($model::class);
    }

    /**
     * Scoped escape hatch for an intentional write under impersonation
     * (premortem A8/A9) — the flag is reset in `finally` even if `$callback`
     * throws, so a failed intentional write never leaves the guard
     * permanently open for the rest of the request.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function allowing(Closure $callback): mixed
    {
        self::$allowing = true;

        try {
            return $callback();
        } finally {
            self::$allowing = false;
        }
    }
}
