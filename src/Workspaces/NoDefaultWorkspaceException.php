<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Workspaces;

use RuntimeException;

/**
 * The current user has no default-workspace membership (B-11 §5.4, D132).
 * Extends {@see RuntimeException} so the existing P2.6 test
 * (`WorkspaceResolverTest::toThrow(RuntimeException::class)`) stays green —
 * a distinct subclass lets callers (`Http\Middleware\ResolveWorkspace`)
 * translate this specific access-denial into 403 instead of the 500 a bare
 * `RuntimeException` would render.
 *
 * @internal spec: B-11 §5.4 п.4, AC-18
 */
final class NoDefaultWorkspaceException extends RuntimeException {}
