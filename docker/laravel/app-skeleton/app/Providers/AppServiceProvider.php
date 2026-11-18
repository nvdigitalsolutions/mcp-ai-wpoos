<?php

namespace App\Providers;

use App\Models\Assistant;
use App\Models\ModelConfig;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use App\Observers\AssistantObserver;
use App\Observers\ModelConfigObserver;
use App\Observers\TeamObserver;
use App\Observers\TenantObserver;
use App\Policies\AssistantPolicy;
use App\Policies\TeamPolicy;
use App\Policies\TenantPolicy;
use App\Services\LaravelAssistantService;
use App\Services\LaravelTeamService;
use App\Support\AdminThemeGenerator;
use App\Support\CapabilityMap;
use App\Support\CredentialStore;
use App\Support\Models\CatalogueModelFallbacks;
use App\Support\Pro\ToolkitEngine;
use App\Support\Pro\ToolkitManifestLoader;
use App\Support\RemoteSites\RemoteSiteStore;
use App\Support\Security\SecuritySnapshotStore;
use App\Support\Settings\SettingsService;
use App\Support\TenantContext;
use App\Support\TenantEntitlement;
use App\Spike\SpikeStreamingProvider;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Table;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\AssistantServiceInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\MemoryStoreInterface;
use Nvoos\Core\Domain\Contract\ProEntitlementInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\TeamServiceInterface;
use Nvoos\Core\Domain\Contract\TranscriptStoreInterface;
use Nvoos\Laravel\Adapter\MemoryStore;
use Nvoos\Laravel\Adapter\SettingsStore;
use Nvoos\Laravel\Adapter\TranscriptStore;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The assistant and team contracts are host-specific (Eloquent
        // models), so they are bound here rather than in the package provider.
        $this->app->bind(AssistantServiceInterface::class, LaravelAssistantService::class);
        $this->app->bind(TeamServiceInterface::class, LaravelTeamService::class);

        // The admin palette generator reads a token tree that lives outside the
        // application (the storefront's DTCG sources), so its location is
        // configuration, not a class constant (Proposal 149 T-0.6).
        $this->app->bind(
            AdminThemeGenerator::class,
            static fn ($app): AdminThemeGenerator => new AdminThemeGenerator(
                (string) $app['config']->get('oos.admin_theme.tokens_path', '')
            )
        );

        // Entitlement is per estate (Proposal 149 T-0.8). The package binds a
        // scoped install-level contract; this rebinding wraps it so an estate
        // with its own licence resolves against the licence server. SCOPED for
        // the same reason: the memo must die with the request, not with the
        // Octane worker.
        $this->app->scoped(
            ProEntitlementInterface::class,
            static fn ($app): TenantEntitlement => new TenantEntitlement(
                (array) $app['config']->get('oos.pro_entitlement', array()),
                (string) $app['config']->get('app.url', '')
            )
        );

        // Settings acquire an estate dimension here (Proposal 149 §16). The
        // package binds one global store; these two rebindings give the app a
        // store per layer plus the service that routes a field to the right
        // one.
        //
        // The estate store takes a RESOLVER, not a tenant id. ProviderRouter is
        // a singleton that constructs twelve provider clients — and injects
        // this store into each — before any tenant middleware has run, so a
        // fixed id would pin every provider call to whichever estate was
        // current at boot. BIND, not scoped: resolution happens per call rather
        // than per request for the same reason.
        $this->app->bind(
            SettingsStoreInterface::class,
            static fn (): SettingsStore => new SettingsStore(
                'nvoos_settings',
                static fn (): string => TenantContext::current(),
                'nvoos_tenant_settings'
            )
        );

        // The platform layer: no estate closure, so reads and writes touch
        // `nvoos_settings` only. `/ops` writes operator-scope fields here.
        $this->app->bind(
            'nvoos.settings.platform',
            static fn (): SettingsStore => new SettingsStore(
                'nvoos_settings',
                null,
                'nvoos_tenant_settings'
            )
        );

        $this->app->bind(
            SettingsService::class,
            static fn ($app): SettingsService => new SettingsService(
                $app->make(SettingsStoreInterface::class),
                $app->make('nvoos.settings.platform')
            )
        );

        // Remote sites are estate data (149 §27). The resolver mirrors the
        // settings store: `RemoteSiteStore` may be resolved before
        // `ResolveApiTenant` has run, so the estate is read per query rather
        // than captured at construction.
        $this->app->bind(
            RemoteSiteStore::class,
            static fn ($app): RemoteSiteStore => new RemoteSiteStore(
                $app->make(CredentialStore::class),
                static fn (): string => TenantContext::current()
            )
        );

        // Toolkit records are estate data as well (149 §47). `ToolkitsController`
        // resolved an estate on every `/pro/toolkits` request and then ignored it,
        // so one estate listed and deleted another's records, and the global
        // unique key refused two estates the same SKU. The engine now starts
        // every statement at one estate-scoped query; the resolver is per-query
        // for the same reason as the store above.
        $this->app->bind(
            ToolkitEngine::class,
            static fn ($app): ToolkitEngine => new ToolkitEngine(
                $app->make(ToolkitManifestLoader::class),
                $app->make(SettingsStoreInterface::class),
                static fn (): string => TenantContext::current()
            )
        );

        // Security snapshots hold an estate's settings payload (149 §51). The table
        // had no estate column, so `GET /security/snapshots` listed every estate's
        // labels and `POST /security/restore` accepted any id — copying another
        // estate's configuration into the caller's settings. BIND, not scoped, for
        // the same reason as the stores above.
        $this->app->bind(
            SecuritySnapshotStore::class,
            static fn (): SecuritySnapshotStore => new SecuritySnapshotStore(
                static fn (): string => TenantContext::current()
            )
        );

        // Transcripts are estate data too (149 §39). The package binds the store
        // with no resolver, which leaves it unscoped; this rebinding gives it the
        // same per-query estate seam as settings and remote sites. BIND, not
        // scoped: the store is constructed before tenant middleware runs.
        $this->app->bind(
            TranscriptStoreInterface::class,
            static fn (): TranscriptStore => new TranscriptStore(
                static fn (): string => TenantContext::current()
            )
        );

        // Agent memory is estate data as well (149 §40), rebound for the same
        // reason: an unscoped `search()` reached every estate's memory.
        $this->app->bind(
            MemoryStoreInterface::class,
            static fn (): MemoryStore => new MemoryStore(
                static fn (): string => TenantContext::current()
            )
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ── Authorization (090-g Phase 5) ────────────────────────────
        //
        // Site roles are WordPress's: subscriber → administrator. `before`
        // answers from the role and returns null (not false) for anything it
        // does not grant, so anything else — host policies included — still
        // decides for itself.
        Gate::before(
            static fn ($user, string $ability): ?bool => CapabilityMap::has($user, $ability)
                ? true
                : null
        );

        // Content abilities: capability first, then ownership. The content
        // store passes [id, userId] instead of relying on the session, because
        // a tool may act for a different user than the request.
        Gate::define(
            'read_content',
            static function ($user, $id = null, $userId = null): bool {
                $target = self::contentUser($user, $userId);

                return $target !== null && CapabilityMap::has($target, 'read');
            }
        );
        Gate::define(
            'edit_content',
            static function ($user, $id = null, $userId = null): bool {
                $target = self::contentUser($user, $userId);

                if ($target === null || ! CapabilityMap::has($target, 'edit_posts')) {
                    return false;
                }

                if (CapabilityMap::has($target, 'edit_others_posts')) {
                    return true;
                }

                $record = self::contentRecord($id);

                return $record !== null
                    && (int) ($record->author_id ?? 0) === (int) $target->getAuthIdentifier();
            }
        );
        Gate::define(
            'delete_content',
            static function ($user, $id = null, $userId = null): bool {
                $target = self::contentUser($user, $userId);

                if ($target === null || ! CapabilityMap::has($target, 'delete_posts')) {
                    return false;
                }

                if (CapabilityMap::has($target, 'delete_others_posts')) {
                    return true;
                }

                $record = self::contentRecord($id);

                return $record !== null
                    && (int) ($record->author_id ?? 0) === (int) $target->getAuthIdentifier();
            }
        );

        // ── Admin resources (Proposal 149, Wave 2) ──────────────────
        //
        // An estate is an authorization boundary, so its policy is registered
        // explicitly rather than left to convention: renaming either class
        // would otherwise silently drop the rule and fall back to "anyone who
        // reached the panel may do anything".
        Gate::policy(Tenant::class, TenantPolicy::class);
        Gate::policy(Team::class, TeamPolicy::class);
        Gate::policy(Assistant::class, AssistantPolicy::class);

        // Record-level audit — the half Wave 0 deferred until a resource
        // existed. The middleware writes the request row; the observers write
        // what a write changed.
        Tenant::observe(TenantObserver::class);
        Team::observe(TeamObserver::class);
        Assistant::observe(AssistantObserver::class);
        ModelConfig::observe(ModelConfigObserver::class);

        // Local/testing soak runs need headroom; production keeps 60/min.
        $chatLimit = app()->environment(['local', 'testing']) ? 60000 : 60;

        // Console CRUD is not tool execution: a page load fans out into several
        // reads, so the 60/min tool budget would starve the SPA.
        $consoleLimit = app()->environment(['local', 'testing'])
            ? 60000
            : (int) config('oos.console_rate_limit', 600);

        RateLimiter::for('chat', fn (Request $request) => Limit::perMinute($chatLimit)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('tools', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('console', fn (Request $request) => Limit::perMinute($consoleLimit)->by($request->user()?->id ?: $request->ip()));

        $router = $this->app->make(ProviderRouter::class);

        // Model-level retry before a provider hop (Proposal 149 §69). The
        // catalogue is optional and fails closed: no table or no row means the
        // router behaves exactly as it did before.
        $router->setModelCatalogue($this->app->make(CatalogueModelFallbacks::class));

        if (! $router->has('spike')) {
            $router->register(new SpikeStreamingProvider(
                $this->app->make(SettingsStoreInterface::class),
                $this->app->make(HttpClientInterface::class),
                $this->app->make(ErrorFactoryInterface::class),
            ));
        }

        // ── Tenant lifecycle (Proposal 149 T-0.2) ────────────────────
        //
        // TenantContext is process-wide static. Octane requests/tasks/ticks are
        // reset in config/octane.php; queue workers are NOT Octane and fire no
        // Octane events, so the oos-laravel-worker container — a long-lived
        // `queue:work redis` process — needs its own reset or a job that pins
        // a tenant hands it to the next job on the same worker.
        //
        // Clear on both outcomes: a job that throws must not leave the pin
        // behind for the retry or for the next job.
        Event::listen(JobProcessed::class, static fn () => TenantContext::forget());
        Event::listen(JobExceptionOccurred::class, static fn () => TenantContext::forget());

        // ── Table defaults (Proposal 149 §55.5, step 5) ────────────
        //
        // One place for the decisions every resource table shares, so a
        // resource's `table()` stays about *its* columns. The visible one is
        // pagination: Filament's defaults are `[5, 10, 25, 50]` with a page
        // size of 10, and a 69-row catalogue reads best at 25.
        //
        // `filtersLayout` is already Filament's default — naming it pins the
        // behaviour against a framework default change rather than expressing
        // a preference. `loadingSkeleton()` only renders with `deferLoading()`,
        // which no table enables yet; it is set here so the first deferred
        // table inherits a skeleton instead of flashing an empty body.
        // `persistInSession()` keeps sort, search and column toggles across a
        // navigation, which is what the console does.
        Table::configureUsing(
            static fn (Table $table): Table => $table
                ->persistInSession()
                ->loadingSkeleton()
                ->paginated(array(10, 25, 50))
                ->defaultPaginationPageOption(25)
                ->filtersLayout(FiltersLayout::AboveContentCollapsible)
        );
    }

    /**
     * Resolve the user a content ability is being checked for.
     *
     * The console passes the acting user id alongside the record id; a null
     * or zero id means "the session user".
     */
    private static function contentUser(mixed $user, mixed $userId): ?Authenticatable
    {
        if (null === $userId || 0 === (int) $userId) {
            return $user instanceof Authenticatable ? $user : null;
        }

        $found = User::query()->find((int) $userId);

        return $found instanceof Authenticatable ? $found : null;
    }

    /**
     * Load a content record through the configured content model.
     */
    private static function contentRecord(mixed $id): ?object
    {
        if (null === $id || (int) $id <= 0) {
            return null;
        }

        $model = (string) config('oos.content_model', \Nvoos\Laravel\Models\NvoosPost::class);

        if (! class_exists($model)) {
            return null;
        }

        return $model::query()->find((int) $id);
    }
}
