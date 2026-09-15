<?php

declare(strict_types=1);

namespace CoreX\Tenancy;

use CoreX\Contracts\TenantStorage;
use CoreX\Tenancy\Bootstrappers\WorkspaceContextBootstrapper;
use CoreX\Tenancy\Commands\IdentitiesReconcileCommand;
use CoreX\Tenancy\Commands\PendingClearCommand;
use CoreX\Tenancy\Commands\PendingCreateCommand;
use CoreX\Tenancy\Commands\TenantsMigrateCommand;
use CoreX\Tenancy\Contracts\ImpersonationService;
use CoreX\Tenancy\Contracts\PrincipalWorkspaceResolver;
use CoreX\Tenancy\Contracts\TemplateIntegrityVerifier;
use CoreX\Tenancy\Contracts\TenancyManager;
use CoreX\Tenancy\Contracts\TenantContextResolver;
use CoreX\Tenancy\Contracts\TenantDatabaseLifecycle;
use CoreX\Tenancy\Database\SchemaCatalogueSerializer;
use CoreX\Tenancy\Database\TemplateIntegrityLock;
use CoreX\Tenancy\Database\TemplateIntegrityVerifier as DatabaseTemplateIntegrityVerifier;
use CoreX\Tenancy\Http\Controllers\LogoutController;
use CoreX\Tenancy\Http\Middleware\AccountStatusGate;
use CoreX\Tenancy\Http\Middleware\AllowImpersonatedWrites;
use CoreX\Tenancy\Http\Middleware\DenyImpersonatedWrites;
use CoreX\Tenancy\Http\Middleware\EnforceSessionLifetime;
use CoreX\Tenancy\Http\Middleware\ImpersonationSessionGuard;
use CoreX\Tenancy\Http\Middleware\ResolveTenantFromHost;
use CoreX\Tenancy\Http\Middleware\ResolveWorkspace;
use CoreX\Tenancy\Identity\DatabaseIdentitySyncer;
use CoreX\Tenancy\Identity\IdentitySyncer;
use CoreX\Tenancy\Impersonation\DatabaseImpersonationService;
use CoreX\Tenancy\Impersonation\ImpersonatedModelWriteGuard;
use CoreX\Tenancy\Jobs\CreateDatabaseFromTemplate;
use CoreX\Tenancy\Lifecycle\AccountLifecycle;
use CoreX\Tenancy\Lifecycle\AccountLifecycleFsm;
use CoreX\Tenancy\Listeners\RevertTenancyOnJobException;
use CoreX\Tenancy\Migrations\MigrationWaveOrchestrator;
use CoreX\Tenancy\Oidc\IdTokenVerifier;
use CoreX\Tenancy\Oidc\JwksKeySet;
use CoreX\Tenancy\Oidc\OidcClient;
use CoreX\Tenancy\Oidc\OidcDiscovery;
use CoreX\Tenancy\Provisioning\PendingPool;
use CoreX\Tenancy\Provisioning\StanclTenantDatabaseLifecycle;
use CoreX\Tenancy\Provisioning\StanclTenantDatabaseProvisioner;
use CoreX\Tenancy\Resolvers\HostTenantResolver;
use CoreX\Tenancy\Storage\ContextTenantStorage;
use CoreX\Tenancy\Workspaces\DatabaseWorkspaceResolver;
use CoreX\Tenancy\Workspaces\WorkspaceResolver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Override;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Contracts\UniqueIdentifierGenerator;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Middleware\ScopeSessions;
use Stancl\Tenancy\Tenancy as Stancl;

final class TenancyServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/tenancy.php', 'tenancy');

        $this->app->singleton(
            StanclTenantDatabaseProvisioner::class,
            static fn (): StanclTenantDatabaseProvisioner => new StanclTenantDatabaseProvisioner(
                (string) config('tenancy.central_connection'),
            ),
        );

        $this->app->singleton(SchemaCatalogueSerializer::class);
        $this->app->singleton(TemplateIntegrityLock::class);
        $this->app->singleton(
            DatabaseTemplateIntegrityVerifier::class,
            static fn (Application $app): DatabaseTemplateIntegrityVerifier => new DatabaseTemplateIntegrityVerifier(
                centralConnection: (string) config('tenancy.central_connection'),
                serializer: $app->make(SchemaCatalogueSerializer::class),
                lock: $app->make(TemplateIntegrityLock::class),
            ),
        );
        $this->app->singleton(
            TemplateIntegrityVerifier::class,
            static fn (Application $app): DatabaseTemplateIntegrityVerifier => $app->make(DatabaseTemplateIntegrityVerifier::class),
        );

        $this->app->singleton(
            PendingPool::class,
            static fn (Application $app): PendingPool => new PendingPool(
                (string) config('tenancy.central_connection'),
                $app->make(StanclTenantDatabaseProvisioner::class),
            ),
        );

        // P2.12 — export/drop seam (D13: Stancl*/pg_dump/S3 imports stay
        // isolated to this package; corex/core only sees the contract).
        $this->app->singleton(
            StanclTenantDatabaseLifecycle::class,
            static fn (): StanclTenantDatabaseLifecycle => new StanclTenantDatabaseLifecycle(
                (string) config('tenancy.central_connection'),
            ),
        );
        $this->app->singleton(TenantDatabaseLifecycle::class, static fn (Application $app): StanclTenantDatabaseLifecycle => $app->make(StanclTenantDatabaseLifecycle::class));

        // P2.12 — the account FSM (B-11 §5.1); `root_accounts.status` MUST
        // be mutated only through this class (D151 impersonation guard is
        // resolved automatically — ImpersonationGuard/ImpersonationService
        // are both already bound, boxed default by corex/core's
        // CoreServiceProvider).
        $this->app->scoped(
            AccountLifecycle::class,
            static fn (Application $app): AccountLifecycle => new AccountLifecycle(
                centralConnection: (string) config('tenancy.central_connection'),
                pendingPool: $app->make(PendingPool::class),
                fsm: $app->make(AccountLifecycleFsm::class),
                tenantDatabaseLifecycle: $app->make(TenantDatabaseLifecycle::class),
                impersonationGuard: $app->make(ImpersonationGuard::class),
            ),
        );

        // P2.12 — synchronous migration-wave orchestrator (B-11 §5.6).
        $this->app->singleton(
            MigrationWaveOrchestrator::class,
            static fn (Application $app): MigrationWaveOrchestrator => new MigrationWaveOrchestrator(
                centralConnection: (string) config('tenancy.central_connection'),
                tenancy: $app->make(TenancyManager::class),
            ),
        );

        // Rebind the boxed defaults (CoreServiceProvider::register(),
        // registered before this provider — corex/tenancy requires corex/core)
        // to the cloud implementations (D10, P2.5): a container binding, not
        // an `if(cloud)` branch. Same pattern as ModulesServiceProvider's
        // SettingDefaultsProvider rebind (P1.25).
        $this->app->singleton(
            StanclTenancyManager::class,
            static fn (Application $app): StanclTenancyManager => new StanclTenancyManager($app->make(Stancl::class)),
        );
        $this->app->singleton(TenancyManager::class, static fn (Application $app): StanclTenancyManager => $app->make(StanclTenancyManager::class));
        $this->app->singleton(TenantContextResolver::class, TenancyManagerContextResolver::class);

        // P2.2 — tenant content never receives Stancl's mutable named disk.
        // The adapter captures its context and root for each acquired handle.
        $this->app->scoped(
            TenantStorage::class,
            static fn (Application $app): ContextTenantStorage => new ContextTenantStorage(
                contexts: $app->make(TenantContextResolver::class),
                filesystems: new FilesystemManager($app),
                durableDriver: (string) config('tenancy.storage.durable_driver'),
                durableRoot: (string) config('tenancy.storage.durable_root'),
                ephemeralRoot: (string) config('tenancy.storage.ephemeral_root'),
            ),
        );

        $this->app->singleton(
            DatabaseIdentitySyncer::class,
            static fn (Application $app): DatabaseIdentitySyncer => new DatabaseIdentitySyncer(
                (string) config('tenancy.central_connection'),
                $app->make(TenancyManager::class),
            ),
        );
        $this->app->singleton(IdentitySyncer::class, static fn (Application $app): DatabaseIdentitySyncer => $app->make(DatabaseIdentitySyncer::class));

        // P2.23 (D136) — P2.6 left this interface unbound; no config-flagged
        // choice of implementation (a second one doesn't exist — a switch
        // key without a consumer is a fabricated contract, D116/D121).
        $this->app->singleton(WorkspaceResolver::class, DatabaseWorkspaceResolver::class);
        $this->app->bindIf(PrincipalWorkspaceResolver::class, DatabaseWorkspaceResolver::class);

        // P2.22 — cloud OIDC login lane, entirely opt-in (AC-28): binding a
        // closure does not execute firebase/php-jwt, only resolving it does,
        // and nothing resolves these outside routes/oidc.php (loaded in
        // boot() under the SAME flag below). No `if (cloud)` business-logic
        // branch — a config-gated container binding (D10), same idiom as
        // the `host_identification.enabled` block further down.
        if ((bool) config('tenancy.oidc.enabled')) {
            $this->app->singleton(
                OidcDiscovery::class,
                static fn (): OidcDiscovery => new OidcDiscovery(
                    issuer: (string) config('tenancy.oidc.issuer'),
                    cacheStore: self::configStringOrNull('tenancy.oidc.discovery.cache_store'),
                    cacheTtl: (int) config('tenancy.oidc.discovery.cache_ttl', 3600),
                ),
            );

            $this->app->singleton(
                JwksKeySet::class,
                static fn (): JwksKeySet => new JwksKeySet(
                    cacheStore: self::configStringOrNull('tenancy.oidc.jwks.cache_store'),
                    cacheTtl: (int) config('tenancy.oidc.jwks.cache_ttl', 3600),
                    refetchCooldownSeconds: (int) config('tenancy.oidc.jwks.refetch_cooldown_seconds', 60),
                ),
            );

            $this->app->singleton(
                IdTokenVerifier::class,
                static fn (Application $app): IdTokenVerifier => new IdTokenVerifier(
                    jwks: $app->make(JwksKeySet::class),
                    issuer: (string) config('tenancy.oidc.issuer'),
                    clientId: (string) config('tenancy.oidc.client_id'),
                    allowedAlgs: (array) config('tenancy.oidc.allowed_algs', ['RS256']),
                    leewaySeconds: (int) config('tenancy.oidc.leeway_seconds', 30),
                ),
            );

            $this->app->singleton(
                OidcClient::class,
                static fn (Application $app): OidcClient => new OidcClient(
                    discovery: $app->make(OidcDiscovery::class),
                    clientId: (string) config('tenancy.oidc.client_id'),
                    clientSecret: self::configStringOrNull('tenancy.oidc.client_secret'),
                    redirectUri: (string) config('tenancy.oidc.redirect_uri'),
                ),
            );

            $this->app->singleton(
                LogoutController::class,
                static fn (): LogoutController => new LogoutController(
                    centralConnection: (string) config('tenancy.central_connection'),
                ),
            );

            // P2.11 (D139/D10) — rebind the boxed NullImpersonationService
            // to the cloud implementation whenever the OIDC lane itself is
            // active; DatabaseImpersonationService::start() is only ever
            // reached when `oidc.impersonation.enabled` ALSO gates it open
            // (OidcLoginController::impersonationClaim()) — binding it here
            // unconditionally within this block just means the container
            // has something to resolve for OidcLoginController's
            // constructor regardless of that inner sub-flag.
            $this->app->scoped(ImpersonationService::class, DatabaseImpersonationService::class);
        }

        // Mirror stancl's own TenancyServiceProvider::register() bootstrapper
        // singleton loop, but over OUR `bootstrapper_order` (config/tenancy.php)
        // rather than stancl's default list: ScoutPrefixBootstrapper isn't in
        // stancl's own default set (commented out there), so without this it
        // would resolve fresh on every bootstrap()/revert() call and lose its
        // stateful $originalScoutPrefix between the two. NOT re-running
        // `__constructStatic` here for bootstrappers already in stancl's own
        // default list — its own register() already called it once
        // (QueueTenancyBootstrapper registers global queue event listeners
        // there; calling it twice would double-register them).
        // WorkspaceContextBootstrapper (P2.6) is NOT part of stancl's default
        // list, so its own static hook is never reached from there — called
        // explicitly here, exactly once.
        foreach (config('tenancy.bootstrapper_order', []) as $bootstrapper) {
            if ($bootstrapper === WorkspaceContextBootstrapper::class) {
                WorkspaceContextBootstrapper::__constructStatic($this->app);
            }

            $this->app->singleton($bootstrapper);
        }
    }

    public function boot(): void
    {
        // root_* migrations are PG-only DDL (jsonb/CHECK/UNIQUE NULLS NOT
        // DISTINCT) targeting the CENTRAL connection, never a tenant/account
        // database (D14). Publish-only, not loadMigrationsFrom (mirrors
        // corex/core CoreServiceProvider D33/A19/A62): an unconditional load
        // would run root_ DDL against whatever connection a consumer's own
        // migrator defaults to. A consumer publishes then runs
        // `migrate --database=<central> --path=database/migrations/root`.
        $this->publishes([
            __DIR__.'/../database/migrations/root' => database_path('migrations/root'),
        ], 'tenancy-migrations');

        // Tenant-DB DDL (`users`, D14/D16) — same publish-only rationale as
        // root above; a consumer's golden-template build assembles these
        // (P2.12), never an ad hoc migrate on a live tenant connection.
        $this->publishes([
            __DIR__.'/../database/migrations/tenant' => database_path('migrations/tenant'),
        ], 'tenancy-migrations-tenant');

        $this->publishes([
            __DIR__.'/../config/tenancy.php' => config_path('tenancy.php'),
        ], 'tenancy-config');

        // Projected here (not in register()) so it deterministically wins
        // over stancl's own TenancyServiceProvider::register() regardless of
        // provider registration order — see config/tenancy.php comment.
        config([
            'tenancy.models.tenant' => config('tenancy.tenant_model'),
            'tenancy.database.central_connection' => config('tenancy.central_connection'),
            'tenancy.database.managers.pgsql' => config('tenancy.tenant_database_manager'),
            'tenancy.bootstrappers' => config('tenancy.bootstrapper_order'),
            'tenancy.cache.prefix' => config('tenancy.cache_prefix'),
        ]);

        $this->app->bind(UniqueIdentifierGenerator::class, config('tenancy.id_generator'));

        if ($this->app->runningInConsole()) {
            $this->commands([
                PendingCreateCommand::class,
                PendingClearCommand::class,
                IdentitiesReconcileCommand::class,
                TenantsMigrateCommand::class,
            ]);
        }

        Event::listen(
            TenantCreated::class,
            JobPipeline::make(config('tenancy.provisioning.pipeline', [CreateDatabaseFromTemplate::class]))
                ->send(static fn (TenantCreated $event): mixed => $event->tenant)
                ->shouldBeQueued(false)
                ->toListener(),
        );

        // stancl ships these two listeners but wires them only in the
        // `tenancy:install` app-level stub (assets/TenancyServiceProvider.stub.php)
        // — not automatically by its own package provider. Without this,
        // Tenancy::initialize()/end() only flip the `initialized` flag and
        // fire events; no bootstrapper ever actually runs.
        Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
        Event::listen(TenancyEnded::class, RevertToCentralContext::class);

        // F-Q1 (D76): QueueTenancyBootstrapper reverts only on
        // JobProcessed/JobFailed, never on JobExceptionOccurred (retry path)
        // — see RevertTenancyOnJobException docblock.
        Event::listen(JobExceptionOccurred::class, RevertTenancyOnJobException::class);

        // P2.23 (D131) — registered UNCONDITIONALLY, unlike the two blocks
        // below: a route tagged with this alias narrows to a workspace, a
        // route without it keeps the account-wide admin context (B-11 §5.4
        // п.4). Never added to the 'tenant' group itself — that would force
        // every tenant-scoped route through a workspace, which the spec's
        // "admin routes" mode explicitly forbids (D131 rationale,
        // research/11-p2.23-workspace-middleware-2026-07-27.md §2.1).
        // Registering an alias pushes nothing onto the application's actual
        // middleware stack by itself (D10) — same reasoning as the
        // conditional aliases further down, just without the flag.
        $this->app->make(Router::class)->aliasMiddleware('tenancy.workspace', ResolveWorkspace::class);

        // P2.10 — boxed profile (AC-28) leaves this whole block unregistered:
        // no root-DB resolver config, no middleware aliases/group. The
        // package still pushes nothing onto the consumer's global stack
        // either way (D10) — the 'tenant' group below is opt-in per route.
        if (config('tenancy.host_identification.enabled')) {
            // Same projection reasoning as `bootstrapper_order`/`cache_prefix`
            // above — own top-level key wins over stancl's own
            // TenancyServiceProvider::register() regardless of provider order.
            config([
                'tenancy.identification.resolvers.'.HostTenantResolver::class => [
                    'cache' => config('tenancy.host_identification.cache'),
                    'cache_ttl' => config('tenancy.host_identification.cache_ttl'),
                    'cache_store' => config('tenancy.host_identification.cache_store'),
                ],
                // Appended, not overwritten — an existing consumer-configured
                // middleware list (e.g. stancl's own defaults) must survive.
                'tenancy.identification.middleware' => [
                    ...config('tenancy.identification.middleware', []),
                    ResolveTenantFromHost::class,
                ],
            ]);

            $router = $this->app->make(Router::class);
            $router->aliasMiddleware('tenancy.host', ResolveTenantFromHost::class);
            $router->aliasMiddleware('tenancy.status', AccountStatusGate::class);

            // stancl's own TenancyServiceProvider::boot() unconditionally
            // pre-registers 'tenant' as an EMPTY route-context flag group
            // (DealsWithRouteContexts) — merged into, not overwritten, so
            // this survives regardless of whether that boot() already ran.
            // Reusing stancl's own group name is deliberate: a route tagged
            // `->middleware('tenant')` gets both stancl's semantic
            // "tenant-context" flag AND this package's actual pipeline in
            // one name. Testbench (and this package's own composer.json
            // `require: stancl/tenancy`) orders stancl's provider before
            // this one; this merge is the belt to that order's suspenders,
            // not a substitute — a later-booting provider that itself
            // OVERWRITES 'tenant' would still win (none does today).
            $router->middlewareGroup('tenant', [
                ...($router->getMiddlewareGroups()['tenant'] ?? []),
                ResolveTenantFromHost::class,
                AccountStatusGate::class,
                ScopeSessions::class,
            ]);
        }

        // P2.22 — same boxed-opt-out idiom as host_identification above:
        // disabled (default) registers neither routes/oidc.php nor the
        // session-lifetime middleware, so nothing in this block ever runs
        // in a boxed profile (AC-28). Placed AFTER the host_identification
        // block above so EnforceSessionLifetime lands after ScopeSessions
        // in the 'tenant' pipeline (it reads the already-tenant-scoped
        // session).
        if ((bool) config('tenancy.oidc.enabled')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/oidc.php');

            $router = $this->app->make(Router::class);
            $router->aliasMiddleware('tenancy.oidc.session_lifetime', EnforceSessionLifetime::class);

            $router->middlewareGroup('tenant', [
                ...($router->getMiddlewareGroups()['tenant'] ?? []),
                EnforceSessionLifetime::class,
            ]);

            // P2.11 (D141) — same boxed-opt-out idiom as the blocks above:
            // disabled (default) registers NEITHER the write-guard
            // middleware NOR the Eloquent listeners, so a boxed/AC-28
            // profile carries zero impersonation surface (Validation case
            // аа). `ImpersonationSessionGuard` is placed AFTER
            // `EnforceSessionLifetime` (Implementation Rule 10 — same
            // idiom, AC-15); `DenyImpersonatedWrites` last, so a session
            // already dead on TTL/revocation never reaches the write
            // check at all.
            if ((bool) config('tenancy.oidc.impersonation.enabled')) {
                $router->aliasMiddleware('tenancy.impersonation.allow', AllowImpersonatedWrites::class);

                $router->middlewareGroup('tenant', [
                    ...($router->getMiddlewareGroups()['tenant'] ?? []),
                    ImpersonationSessionGuard::class,
                    DenyImpersonatedWrites::class,
                ]);

                // Resolve per event: a captured object would retain the first
                // request's scoped impersonation service in a long-lived worker.
                Event::listen('eloquent.saving: *', [ImpersonatedModelWriteGuard::class, 'handle']);
                Event::listen('eloquent.deleting: *', [ImpersonatedModelWriteGuard::class, 'handle']);
                Event::listen('eloquent.restoring: *', [ImpersonatedModelWriteGuard::class, 'handle']);
            }
        }
    }

    /** @return non-empty-string|null */
    private static function configStringOrNull(string $key): ?string
    {
        $value = config($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
