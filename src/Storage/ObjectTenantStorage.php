<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Storage;

use Aws\S3\S3Client;
use CoreX\Contracts\TenantStorage;
use CoreX\Storage\ExportArchiveRef;
use CoreX\Storage\ScopedFilesystem;
use CoreX\Storage\StorageCapabilities;
use CoreX\Storage\StorageCapabilityUnavailable;
use CoreX\Storage\StorageOperationFailed;
use CoreX\Tenancy\Contracts\TenantContextResolver;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;

/** Explicitly constructed with trusted server configuration; never auto-bound. */
final class ObjectTenantStorage implements TenantStorage
{
    /** @var array<string, mixed> */
    private readonly array $durableConfig;

    /** @param array<string, mixed> $durableConfig */
    public function __construct(
        private readonly TenantContextResolver $contexts,
        private readonly FilesystemManager $filesystems,
        array $durableConfig,
        private readonly string $ephemeralRoot,
    ) {
        if (! class_exists(AwsS3V3Adapter::class) || ! class_exists(S3Client::class)) {
            throw new StorageCapabilityUnavailable('The optional native S3 adapter is not installed.');
        }

        $allowed = ['driver', 'key', 'secret', 'token', 'region', 'bucket', 'endpoint', 'use_path_style_endpoint', 'version', 'http'];

        if (($durableConfig['driver'] ?? null) !== 's3' || array_diff(array_keys($durableConfig), $allowed) !== []) {
            throw new StorageOperationFailed('Object storage configuration is invalid.');
        }
        foreach (['bucket', 'region'] as $required) {
            if (! is_string($durableConfig[$required] ?? null) || $durableConfig[$required] === '') {
                throw new StorageOperationFailed('Object storage configuration is incomplete.');
            }
        }

        $this->durableConfig = $durableConfig;
    }

    public function disk(): Filesystem
    {
        $accountId = $this->accountId();
        $prefix = 'tenants/'.$accountId;

        return new ScopedFilesystem(
            filesystem: $this->filesystems->build(config: array_merge($this->durableConfig, [
                'root' => $prefix,
                'visibility' => 'private',
                'directory_visibility' => 'private',
                'throw' => true,
            ])),
            contexts: $this->contexts,
            accountId: $accountId,
            root: $prefix,
            localPaths: false,
        );
    }

    public function ephemeralDisk(): Filesystem
    {
        $accountId = $this->accountId();
        $root = rtrim($this->ephemeralRoot, DIRECTORY_SEPARATOR).'/tenants/'.$accountId;

        return new ScopedFilesystem(
            filesystem: $this->filesystems->build(config: [
                'driver' => 'local', 'root' => $root, 'visibility' => 'private', 'links' => 'disallow', 'throw' => true,
            ]),
            contexts: $this->contexts,
            accountId: $accountId,
            root: $root,
        );
    }

    public function capabilities(): StorageCapabilities
    {
        return new StorageCapabilities(usage: false, export: false);
    }

    public function usageBytes(): int
    {
        throw new StorageCapabilityUnavailable('Object storage usage requires a qualified provider implementation.');
    }

    public function exportArchive(): ExportArchiveRef
    {
        throw new StorageCapabilityUnavailable('Object storage export requires a qualified archive writer.');
    }

    private function accountId(): string
    {
        $accountId = $this->contexts->current()->account->id;

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $accountId) !== 1) {
            throw new StorageOperationFailed('Tenant account identifier must be a canonical UUID.');
        }

        return $accountId;
    }
}
