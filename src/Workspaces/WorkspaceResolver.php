<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Workspaces;

use CoreX\Tenancy\WorkspaceRef;

/**
 * Resolves `/w/{slug}` to a workspace, checking the current user's
 * membership (B-11 §3.2). A null slug resolves to the user's default
 * workspace.
 *
 * @internal spec: B-11 §3.2, AC-17…AC-20
 */
interface WorkspaceResolver
{
    /**
     * Null-slug → default workspace of the current user. No active
     * membership on the target workspace → 403.
     */
    public function resolve(?string $slug): WorkspaceRef;
}
