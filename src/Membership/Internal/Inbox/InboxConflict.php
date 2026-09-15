<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Inbox;

use RuntimeException;

/** @internal A logical event ID may never acquire a different semantic digest. */
final class InboxConflict extends RuntimeException {}
