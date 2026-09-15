<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Provisioning;

use Closure;
use CoreX\Tenancy\AccountRef;
use CoreX\Tenancy\Contracts\TenantDatabaseLifecycle;
use CoreX\Tenancy\Events\AccountPurged;
use CoreX\Tenancy\Events\ExportReady;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * `export()`/`drop()` on the physical account database (B-11 §5.1/§7.3,
 * D13/AC-7, P2.12). `drop()` mirrors the `DROP DATABASE IF EXISTS` pattern
 * already used by {@see PendingPool::clearPending()}.
 *
 * The host supplies a writer that returns a durable archive path and size.
 * Remote writers must also supply a verifier for their storage backend.
 */
final class StanclTenantDatabaseLifecycle implements TenantDatabaseLifecycle
{
    /**
     * @param  (Closure(AccountRef, string): array{storage_path: string, size_bytes: int})|null  $writer
     * @param  (Closure(string, int): bool)|null  $verifier
     */
    public function __construct(
        private readonly string $centralConnection,
        private readonly ?Closure $writer = null,
        private readonly ?Closure $verifier = null,
    ) {}

    public function export(AccountRef $account, string $kind): string
    {
        if ($this->writer === null) {
            throw new RuntimeException('No tenant export writer configured; refusing to create a ready export.');
        }

        $exportId = (string) Str::uuid7();

        DB::connection($this->centralConnection)->table('root_account_exports')->insert([
            'id' => $exportId,
            'account_id' => $account->id,
            'kind' => $kind,
            'status' => 'pending',
            'formats' => json_encode(['pg_dump', 'csv'], JSON_THROW_ON_ERROR),
            'purge_after' => now()->addDays(30),
            'url_expires_at' => now()->addDays(14),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection($this->centralConnection)->table('root_account_exports')
            ->where('id', $exportId)
            ->update(['status' => 'running', 'updated_at' => now()]);

        $writer = $this->writer;
        $result = $writer($account, $exportId);
        $this->verifyArchive($result['storage_path'], $result['size_bytes']);

        DB::connection($this->centralConnection)->table('root_account_exports')
            ->where('id', $exportId)
            ->update([
                'status' => 'ready',
                'storage_path' => $result['storage_path'],
                'size_bytes' => $result['size_bytes'],
                'updated_at' => now(),
            ]);

        event(new ExportReady(account: $account, exportId: $exportId));

        return $exportId;
    }

    public function drop(AccountRef $account): void
    {
        $latestExport = DB::connection($this->centralConnection)->table('root_account_exports')
            ->where('account_id', $account->id)
            ->orderByDesc('created_at')
            ->first();

        if ($latestExport === null || $latestExport->status !== 'ready') {
            throw new RuntimeException(
                "Refusing to drop account [{$account->id}]'s database: no ready export on record (export-before-DROP invariant, D13/AC-7).",
            );
        }

        $this->verifyArchive((string) $latestExport->storage_path, (int) $latestExport->size_bytes);

        $dbName = DB::connection($this->centralConnection)->table('root_accounts')
            ->where('id', $account->id)
            ->value('db_name');

        if (! is_string($dbName) || ! preg_match('/\Aacc_[a-zA-Z0-9_]+\z/D', $dbName)) {
            throw new RuntimeException('Invalid account database name; refusing DROP.');
        }

        DB::connection($this->centralConnection)->statement('DROP DATABASE IF EXISTS "'.$dbName.'"');

        event(new AccountPurged($account));
    }

    private function verifyArchive(string $path, int $size): void
    {
        clearstatcache(true, $path);
        $verified = $path !== '' && $size > 0 && ($this->verifier !== null
            ? ($this->verifier)($path, $size)
            : is_file($path) && is_readable($path) && filesize($path) === $size);

        if (! $verified) {
            throw new RuntimeException('Tenant export archive is unavailable or has an invalid size; refusing ready/DROP.');
        }
    }
}
