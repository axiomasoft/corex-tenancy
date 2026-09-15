<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Persistence;

use LogicException;

/** @internal A fail-closed reference-store transition violation. */
final class PairStoreViolation extends LogicException {}
