<?php

declare(strict_types=1);

use CoreX\Tenancy\Bootstrappers\WorkspaceContextBootstrapper;
use CoreX\Tenancy\Database\TemplateClonePostgreSQLManager;
use CoreX\Tenancy\Jobs\CreateDatabaseFromTemplate;
use CoreX\Tenancy\Models\Account;
use Stancl\Tenancy\Bootstrappers;
use Stancl\Tenancy\UniqueIdentifierGenerators\UUIDv7Generator;

return [

    /*
    |--------------------------------------------------------------------------
    | Central connection (B-11 §2.1 / D14)
    |--------------------------------------------------------------------------
    | root_* migrations and models target this connection — the control-plane
    | database, never a tenant/account database. Override per-host if the
    | central DB uses a different Laravel connection name.
    */
    'central_connection' => env('TENANCY_CENTRAL_CONNECTION', 'pgsql'),

    /*
    |--------------------------------------------------------------------------
    | stancl/tenancy v4 pin (D8/D75/D76 — GO on 2026-07-18 PoC gate)
    |--------------------------------------------------------------------------
    | stancl/tenancy master carries no tagged release; the exact sha is the
    | supply-chain anchor (D25 fork-mirror is a deferred follow-up, OQ-4).
    */
    'stancl' => [
        'package' => 'stancl/tenancy',
        'pin' => 'dev-master#76e5f9655924271c6c9da54e2d4ccb82c6f71ea0 as 4.0.0',
        'jobpipeline_pin' => '2.0.0-rc7',
    ],

    /*
    |--------------------------------------------------------------------------
    | Account/provisioning wiring (P2.3)
    |--------------------------------------------------------------------------
    | Own top-level keys, not `tenancy.models.*`/`tenancy.database.*` — stancl's
    | own TenancyServiceProvider::register() also merges its `assets/config.php`
    | defaults into the SAME `tenancy` config key; a shallow array_merge at the
    | top level would silently drop the rest of stancl's `database`/`models`
    | subtree if we owned those keys here. TenancyServiceProvider::boot() (runs
    | after every provider's register()) projects these onto the real
    | `tenancy.models.tenant`/`tenancy.database.*` keys stancl reads.
    */
    'tenant_model' => Account::class,
    'id_generator' => UUIDv7Generator::class,
    'tenant_database_manager' => TemplateClonePostgreSQLManager::class,

    'provisioning' => [
        // TenantCreated ⇒ JobPipeline — no MigrateDatabase/SeedDatabase (R-11 §2.3 п.5).
        'pipeline' => [
            CreateDatabaseFromTemplate::class,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Golden-template integrity core (P2.2)
    |--------------------------------------------------------------------------
    |
    | The verifier authenticates a separately distributed, signed manifest
    | against its public signing key and a recorded schema hash. The empty
    | default intentionally fails closed. Write-path consumers are introduced
    | separately; values are deployment configuration and private signing
    | material never belongs here.
    |
    */
    'template_integrity' => [
        'trusted_keys' => [],
        'manifests' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | TenancyManager bootstrappers (P2.5, R-11 §2.4 — fixed order)
    |--------------------------------------------------------------------------
    | Own key (not `bootstrappers` — that's stancl's own top-level key under
    | the SAME `tenancy` config namespace; TenancyServiceProvider::boot()
    | projects this onto it deterministically, mirroring `tenant_model`/
    | `central_connection` above). Database → Cache(prefix-mode) →
    | DatabaseSession → Queue → Filesystem → Scout-prefix — order is an
    | Implementation Rule (P2.5), not incidental.
    */
    'bootstrapper_order' => [
        Bootstrappers\DatabaseTenancyBootstrapper::class,
        // P2.6 — narrows the workspace within the already-switched account
        // connection; must run after the account-level Database bootstrapper.
        WorkspaceContextBootstrapper::class,
        Bootstrappers\CacheTenancyBootstrapper::class,
        Bootstrappers\DatabaseSessionBootstrapper::class,
        Bootstrappers\QueueTenancyBootstrapper::class,
        Bootstrappers\FilesystemTenancyBootstrapper::class,
        Bootstrappers\Integrations\ScoutPrefixBootstrapper::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache prefix-mode (P2.5 — NOT cache tags, v4 default)
    |--------------------------------------------------------------------------
    | `%tenant%` is replaced with the tenant key by CacheTenancyBootstrapper;
    | prepended to the store's original prefix. Own key, projected onto
    | stancl's `tenancy.cache.prefix` in boot() (same reasoning as above).
    */
    'cache_prefix' => 'tenant_%tenant%_',

    /*
    |--------------------------------------------------------------------------
    | Tenant content storage (P2.2)
    |--------------------------------------------------------------------------
    |
    | These roots are owned by ContextTenantStorage, not by a mutable named
    | disk returned from Stancl's FilesystemTenancyBootstrapper. `fake-s3` is
    | a local object-prefix test backend only: it has no SDK, credentials, or
    | claim of compatibility with a real provider.
    |
    */
    'storage' => [
        'durable_driver' => env('TENANCY_STORAGE_DURABLE_DRIVER', 'local'),
        'durable_root' => env('TENANCY_STORAGE_DURABLE_ROOT', storage_path('app/corex/tenancy-durable')),
        'ephemeral_root' => env('TENANCY_STORAGE_EPHEMERAL_ROOT', storage_path('app/corex/tenancy-ephemeral')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Host identification (P2.10, B-11 §5.4 п.1–3)
    |--------------------------------------------------------------------------
    | Own top-level key, projected in TenancyServiceProvider::boot() onto
    | stancl's `tenancy.identification.resolvers.<HostTenantResolver::class>`
    | (same reasoning as `bootstrapper_order`/`cache_prefix` above) and onto
    | `tenancy.identification.middleware`. `enabled=false` (boxed profile,
    | AC-28) registers neither the resolver's config subtree nor the
    | middleware group/aliases — the package pushes nothing onto the host
    | application's global middleware stack either way (D10).
    */
    'host_identification' => [
        'enabled' => (bool) env('TENANCY_HOST_IDENTIFICATION_ENABLED', false),
        'cache' => true,
        'cache_ttl' => 300,
        'cache_store' => null, // null = default store
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom-domain trust SPI (P3.1)
    |--------------------------------------------------------------------------
    |
    | CoreX owns only the durable trusted-state transition. A consuming
    | application must bind all four contracts when it deliberately enables
    | this lane; no default DNS, HTTP, TLS, credential, or proof adapter ships
    | with the package.
    |
    */
    'domain_trust' => [
        'enabled' => (bool) env('TENANCY_DOMAIN_TRUST_ENABLED', false),
        'challenge_ttl_seconds' => (int) env('TENANCY_DOMAIN_TRUST_CHALLENGE_TTL_SECONDS', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Account status gate (P2.10, B-11 §5.1)
    |--------------------------------------------------------------------------
    | `payment_route`/`export_route` accept either a route NAME or a path
    | pattern (matched via Request::is()); null = no such route configured,
    | the owner-exception never applies. The package owns no payment/export
    | page of its own — hardcoding a route name here would be a product
    | assumption this pure primitives package must not make.
    */
    'status_gate' => [
        'payment_route' => null,
        'export_route' => null,
        'read_only_methods' => ['GET', 'HEAD', 'OPTIONS'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cloud OIDC login lane (P2.22, D123/D127/D129/D130) — default OFF
    |--------------------------------------------------------------------------
    | `enabled=false` (boxed profile, AC-28) registers NEITHER the
    | routes/oidc.php routes NOR the EnforceSessionLifetime middleware — the
    | package pushes nothing onto the host application's stack either way
    | (D10), and firebase/php-jwt code never executes. `issuer` is the ONLY
    | endpoint named here; authorize/token/JWKS endpoints always come from
    | the Discovery document (`Oidc\OidcDiscovery`), never hardcoded (D123).
    */
    'oidc' => [
        'enabled' => (bool) env('TENANCY_OIDC_ENABLED', false),

        'issuer' => env('TENANCY_OIDC_ISSUER'),
        'client_id' => env('TENANCY_OIDC_CLIENT_ID'),
        'client_secret' => env('TENANCY_OIDC_CLIENT_SECRET'),
        'redirect_uri' => env('TENANCY_OIDC_REDIRECT_URI'),

        // RS256-only allow-list (D123) — checked against the id_token
        // HEADER before JWT::decode() ever runs (Oidc\IdTokenVerifier).
        'allowed_algs' => ['RS256'],
        'leeway_seconds' => (int) env('TENANCY_OIDC_LEEWAY_SECONDS', 30),

        // The guard `Auth::loginUsingId()`/`Auth::logout()` operate on —
        // the consuming app's own guard name, this package assumes nothing
        // beyond what it is told.
        'guard' => env('TENANCY_OIDC_GUARD', 'web'),

        'discovery' => [
            'cache_store' => env('TENANCY_OIDC_DISCOVERY_CACHE_STORE'), // null = default store
            'cache_ttl' => (int) env('TENANCY_OIDC_DISCOVERY_CACHE_TTL', 3600),
        ],

        'jwks' => [
            'cache_store' => env('TENANCY_OIDC_JWKS_CACHE_STORE'), // null = default store
            'cache_ttl' => (int) env('TENANCY_OIDC_JWKS_CACHE_TTL', 3600),
            // D130: ONE forced refetch per unknown kid, gated by this cooldown.
            'refetch_cooldown_seconds' => (int) env('TENANCY_OIDC_JWKS_REFETCH_COOLDOWN_SECONDS', 60),
        ],

        // P2.11 — grant validation (`root_impersonation_grants`, D143) +
        // read-only invariant (D141). `enabled=false` (default): an `imp`
        // claim is rejected (403) and neither the write-guard middleware
        // nor the Eloquent listeners are registered (AC-28).
        //
        // `require_approved_by` (D148) — 4-eyes is a DEPLOYMENT policy
        // (B-11 §5.9 п.1 "опционально … для prod-политики"), not part of
        // the invariant itself: default `false` so dev/staging keeps
        // working, a prod profile flips it to reject any grant missing
        // `approved_by`.
        'impersonation' => [
            'enabled' => (bool) env('TENANCY_OIDC_IMPERSONATION_ENABLED', false),
            'require_approved_by' => (bool) env('TENANCY_OIDC_IMPERSONATION_REQUIRE_APPROVED_BY', false),
        ],

        // AC-15 — enforced by Http\Middleware\EnforceSessionLifetime
        // regardless of the consuming app's own `session.lifetime`.
        'max_session_lifetime_minutes' => (int) env('TENANCY_OIDC_MAX_SESSION_LIFETIME_MINUTES', 720),
    ],

    /*
    |--------------------------------------------------------------------------
    | Workspace resolution (P2.23, B-11 §5.4 п.4, AC-18)
    |--------------------------------------------------------------------------
    | Wire a route with `->middleware(['tenant', 'tenancy.workspace'])` — in
    | that order, so the workspace only narrows AFTER 'tenant' has proven the
    | session belongs to this account. A route WITHOUT the `tenancy.workspace`
    | alias keeps the account-wide admin context (B-11 §5.4 п.4) — the
    | package registers no product routes of its own; `/w/{slug}` and its
    | route-binding are the consuming application's concern (D133).
    */
    'workspace' => [
        'path_segment' => env('TENANCY_WORKSPACE_PATH_SEGMENT', 'w'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Account lifecycle FSM (P2.12, B-11 §5.1)
    |--------------------------------------------------------------------------
    | `trial_days` — window `AccountLifecycle::startTrial()` grants before
    | `trial_ends_at`. `grace_days` — window `AccountLifecycle::startGrace()`
    | grants before `grace_until`; `purge_after` is always `grace_until` plus
    | a further fixed 30 days (§7.3 п.7 retention), not itself configurable.
    */
    'trial_days' => (int) env('TENANCY_TRIAL_DAYS', 14),
    'grace_days' => (int) env('TENANCY_GRACE_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Platform domain (P2.12, AC-13)
    |--------------------------------------------------------------------------
    | Own config key rather than a hardcoded product suffix (D91 — corex/*
    | stays product-agnostic): `AccountLifecycle::startTrial()` provisions
    | the platform domain as `<slug>.<platform_domain>`. The default is only
    | a neutral placeholder — every real deployment overrides it.
    */
    'platform_domain' => env('TENANCY_PLATFORM_DOMAIN', 'example.test'),

];
