<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Database;

use Illuminate\Database\Connection;

final class TemplateIntegrityLock
{
    public function acquire(Connection $connection, string $templateId): void
    {
        $connection->select(
            query: 'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))',
            bindings: ['corex-template-integrity:'.$templateId],
        );
    }
}
