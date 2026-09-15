<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Storage;

use CoreX\Contracts\TenantStorage;
use CoreX\Storage\ExportArchiveRef;
use CoreX\Storage\ScopedFilesystem;
use CoreX\Storage\StorageCapabilities;
use CoreX\Storage\StorageCapabilityUnavailable;
use CoreX\Storage\StorageOperationFailed;
use CoreX\Tenancy\Contracts\TenantContextResolver;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Tenant-owned storage boundary for the Stancl profile.
 *
 * The fake-s3 driver is deliberately a local test backend with object-prefix
 * semantics; it never represents a configured cloud provider.
 */
final class ContextTenantStorage implements TenantStorage
{
    public function __construct(
        private readonly TenantContextResolver $contexts,
        private readonly FilesystemManager $filesystems,
        private readonly string $durableDriver,
        private readonly string $durableRoot,
        private readonly string $ephemeralRoot,
    ) {}

    public function disk(): Filesystem
    {
        return $this->scopedDisk(root: $this->durableRoot);
    }

    public function ephemeralDisk(): Filesystem
    {
        return $this->scopedDisk(root: $this->ephemeralRoot);
    }

    public function capabilities(): StorageCapabilities
    {
        return new StorageCapabilities(usage: true, export: false);
    }

    public function usageBytes(): int
    {
        $root = $this->tenantRoot(root: $this->durableRoot);

        if (! is_dir($root)) {
            return 0;
        }

        $bytes = 0;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            directory: $root,
            flags: RecursiveDirectoryIterator::SKIP_DOTS,
        ));

        foreach ($iterator as $file) {
            if ($file->isLink()) {
                throw new StorageOperationFailed('Storage root contains a symbolic link.');
            }

            if ($file->isFile()) {
                $bytes += $file->getSize();
            }
        }

        return $bytes;
    }

    public function exportArchive(): ExportArchiveRef
    {
        throw new StorageCapabilityUnavailable('Storage export is unavailable for the tenancy storage drivers.');
    }

    private function scopedDisk(string $root): ScopedFilesystem
    {
        $context = $this->contexts->current();
        $tenantRoot = $this->tenantRoot(root: $root, accountId: $context->account->id);

        return new ScopedFilesystem(
            filesystem: $this->filesystems->build(config: [
                // Both approved modes use Laravel's local adapter. `fake-s3`
                // exists solely to exercise object-prefix parity without a
                // provider SDK, credentials, or mutable named disk.
                'driver' => 'local',
                'root' => $tenantRoot,
                'visibility' => 'private',
                'links' => 'disallow',
            ]),
            contexts: $this->contexts,
            accountId: $context->account->id,
            root: $tenantRoot,
        );
    }

    private function tenantRoot(string $root, ?string $accountId = null): string
    {
        $accountId ??= $this->contexts->current()->account->id;

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $accountId) !== 1) {
            throw new StorageOperationFailed('Tenant account identifier is invalid.');
        }

        if (! in_array($this->durableDriver, ['local', 'fake-s3'], strict: true)) {
            throw new StorageOperationFailed('Tenant storage driver is not supported.');
        }

        return rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'tenants'.DIRECTORY_SEPARATOR.$accountId;
    }
}
