<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Database;

use CoreX\Tenancy\Contracts\TemplateIntegrityVerifier as TemplateIntegrityVerifierContract;
use CoreX\Tenancy\Provisioning\ClaimRequest;
use CoreX\Tenancy\Provisioning\QualifiedTemplate;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Support\Facades\DB;

final class TemplateIntegrityVerifier implements TemplateIntegrityVerifierContract
{
    public function __construct(
        private readonly string $centralConnection,
        private readonly SchemaCatalogueSerializer $serializer,
        private readonly TemplateIntegrityLock $lock,
    ) {}

    public function qualify(ClaimRequest $request): QualifiedTemplate
    {
        $template = $this->verify(templateId: $request->templateId, version: null, clusterId: $request->clusterId);

        return new QualifiedTemplate(
            clusterId: $request->clusterId,
            templateId: (string) $template->id,
            templateVersion: (int) $template->version,
            artifactDigest: $this->manifestFor((string) $template->id)->digest($this->serializer),
        );
    }

    public function assertCurrent(int $version, ?string $clusterId = null): object
    {
        return $this->verify(templateId: null, version: $version, clusterId: $clusterId);
    }

    public function assertAccount(object $account): object
    {
        $template = $this->assertCurrent(
            version: (int) $account->template_version,
            clusterId: (string) $account->cluster_id,
        );
        $actualHash = $this->actualHashForDatabase((string) $account->db_name);

        if ($actualHash !== $template->schema_hash) {
            throw new TemplateIntegrityException('The account database no longer matches its trusted template schema.');
        }

        return $template;
    }

    private function verify(?string $templateId, ?int $version, ?string $clusterId): object
    {
        $connection = DB::connection($this->centralConnection);

        return $connection->transaction(function () use ($connection, $templateId, $version, $clusterId): object {
            $query = $connection->table('root_templates')->where('status', 'ready')->lockForUpdate();

            if ($templateId !== null) {
                $query->where('id', $templateId);
            }

            if ($version !== null) {
                $query->where('version', $version);
            }
            $template = $query->first();

            if ($template === null || ! is_string($template->schema_hash)) {
                throw new TemplateIntegrityException('The requested template has no trusted schema hash.');
            }

            $this->lock->acquire($connection, (string) $template->id);

            if ($clusterId !== null && ! $connection->table('root_clusters')
                ->where('id', $clusterId)->where('status', 'active')->where('template_version', $template->version)->exists()) {
                throw new TemplateIntegrityException('The cluster does not currently admit this template.');
            }

            $manifest = $this->manifestFor((string) $template->id);
            $manifest->assertTrusted(
                trustedKeys: (array) config('tenancy.template_integrity.trusted_keys', []),
                serializer: $this->serializer,
            );
            $actualHash = $this->actualHash($template);
            $manifest->assertMatches(template: $template, actualHash: $actualHash);

            return $template;
        });
    }

    private function actualHash(object $template): string
    {
        return $this->actualHashForDatabase((string) $template->db_name);
    }

    private function actualHashForDatabase(string $database): string
    {
        $central = DB::connection($this->centralConnection);
        $configuration = $central->getConfig();
        $configuration['database'] = $database;
        $connection = (new ConnectionFactory(app()))->make($configuration, 'corex-template-catalogue');

        try {
            return $this->serializer->hash($connection);
        } finally {
            $connection->disconnect();
        }
    }

    private function manifestFor(string $templateId): TemplateManifest
    {
        $manifests = (array) config('tenancy.template_integrity.manifests', []);
        $manifest = $manifests[$templateId] ?? null;

        if (! is_array($manifest)) {
            throw new TemplateIntegrityException('No trusted manifest is configured for the template.');
        }

        return TemplateManifest::fromConfig($manifest);
    }
}
