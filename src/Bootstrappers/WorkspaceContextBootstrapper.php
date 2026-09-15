<?php

declare(strict_types=1);

namespace CoreX\Tenancy\Bootstrappers;

use CoreX\Tenancy\Contracts\TenancyManager;
use CoreX\Tenancy\Models\Workspace;
use CoreX\Tenancy\WorkspaceRef;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobRetryRequested;
use Illuminate\Queue\QueueManager;
use Illuminate\Support\Testing\Fakes\QueueFake;
use Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper;
use Stancl\Tenancy\Contracts\TenancyBootstrapper;
use Stancl\Tenancy\Contracts\Tenant;

/**
 * Second tier over stancl's `DatabaseTenancyBootstrapper` (B-11 §5.4):
 * narrowing the workspace within an already-initialized account is
 * `TenancyManager::setWorkspace()`, not a stancl concept, so it needs its
 * own restore path across queued jobs. Mirrors
 * {@see QueueTenancyBootstrapper} exactly:
 * `bootstrap()`/`revert()` (fired via TenancyInitialized/TenancyEnded, once
 * per account-level init/end) only clear the workspace on revert; the
 * actual per-job restore happens via a static `JobProcessing`/
 * `JobRetryRequested` listener reading `workspace_id` from the job payload
 * — added to that payload by a `createPayloadUsing()` hook run while the
 * workspace narrowing is still live at dispatch time. Registering this
 * requires an explicit `__constructStatic` call from
 * `TenancyServiceProvider::register()` (this class is not part of stancl's
 * own default bootstrapper list, so stancl's own provider never calls it).
 */
final class WorkspaceContextBootstrapper implements TenancyBootstrapper
{
    public function __construct(
        private readonly TenancyManager $manager,
        QueueManager $queue,
    ) {
        // Deferred to instance construction (mirrors stancl's own
        // QueueTenancyBootstrapper::__construct/setUpPayloadGenerator, NOT
        // its __constructStatic): this class is registered as a lazy
        // singleton (TenancyServiceProvider::register()), first resolved
        // when tenancy actually bootstraps for the first time — well after
        // every provider's register() phase, including the queue service
        // provider's. Doing this eagerly in __constructStatic (called
        // synchronously from register()) fails in Testbench, where 'queue'
        // is not yet bound at that point.
        if (! $queue instanceof QueueFake) {
            $queue->createPayloadUsing(function (): array {
                $workspace = $this->manager->context()?->workspace;

                return $workspace === null ? [] : ['workspace_id' => $workspace->id];
            });
        }
    }

    public static function __constructStatic(Application $app): void
    {
        self::registerJobListener($app);
    }

    public function bootstrap(Tenant $tenant): void {}

    public function revert(): void
    {
        $this->manager->setWorkspace(null);
    }

    private static function registerJobListener(Application $app): void
    {
        /** @var Dispatcher $dispatcher */
        $dispatcher = $app->make(Dispatcher::class);

        $dispatcher->listen(JobProcessing::class, static function ($event) use ($app): void {
            self::restore($app, $event->job->payload()['workspace_id'] ?? null);
        });

        $dispatcher->listen(JobRetryRequested::class, static function ($event) use ($app): void {
            self::restore($app, $event->payload()['workspace_id'] ?? null);
        });
    }

    private static function restore(Application $app, ?string $workspaceId): void
    {
        if ($workspaceId === null) {
            return;
        }

        /** @var TenancyManager $manager */
        $manager = $app->make(TenancyManager::class);

        if (! $manager->initialized()) {
            return;
        }

        /** @var ?Workspace $row */
        $row = Workspace::query()->find($workspaceId);

        if ($row === null) {
            return;
        }

        $manager->setWorkspace(new WorkspaceRef(
            id: $row->id,
            slug: $row->slug,
            type: $row->type,
            path: $row->path,
            parentId: $row->parent_id,
        ));
    }
}
