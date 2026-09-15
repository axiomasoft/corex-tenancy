<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Scopes;

use CoreX\Tenancy\Contracts\TenancyManager;
use CoreX\Tenancy\StanclTenancyManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Global scope for workspace_scoped entities (B-11 §5.4, AC-20):
 * `workspace_id = context.workspace.id OR workspace_id IS NULL` (NULL =
 * account-wide record, visible regardless of the narrowed workspace).
 * No workspace narrowed in the current context (account-wide/admin routes,
 * P2.9 ScopeResolver's concern) → no-op, the model is left unfiltered.
 *
 * @implements Scope<Model>
 */
final class WorkspaceScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $workspace = $this->manager()->context()?->workspace;

        if ($workspace === null) {
            return;
        }

        $column = $model->qualifyColumn('workspace_id');

        $builder->where(static function (Builder $query) use ($column, $workspace): void {
            $query->where($column, $workspace->id)->orWhereNull($column);
        });
    }

    /**
     * Declared return type pins the interface for static analysis — the
     * container's currently-scanned binding (boxed `NullTenancyManager`,
     * whose `context()` narrows to non-null) is otherwise what Larastan
     * infers from a direct `app(TenancyManager::class)` call, which would
     * misreport the nullsafe access below as dead code even though the
     * cloud binding ({@see StanclTenancyManager}) genuinely
     * returns null outside any tenant.
     */
    private function manager(): TenancyManager
    {
        return app(TenancyManager::class);
    }
}
