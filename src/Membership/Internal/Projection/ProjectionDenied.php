<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Membership\Internal\Projection;

use RuntimeException;

/** @internal The disposable reference consumer denies an unsupported or stale entrance. */
final class ProjectionDenied extends RuntimeException {}
