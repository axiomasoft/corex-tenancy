<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Workspaces;

use CoreX\Tenancy\Models\Invitation;
use RuntimeException;

/**
 * Thrown by {@see Invitation::accept()} for a
 * revoked, already-accepted, or (persisted-or-lazily) expired invitation —
 * the domain-level counterpart of the HTTP 410 in B-11 §2.2/AC-19 (mapping
 * to an actual response is the route layer's concern, out of this item's
 * scope).
 */
final class InvitationNotAcceptableException extends RuntimeException {}
