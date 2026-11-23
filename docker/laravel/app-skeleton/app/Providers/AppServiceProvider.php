<?php

namespace App\Providers;

use App\Auth\Auth0Guard;
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
use App\Support\Approvals\DatabaseApprovalTicket;
use App\Support\Auth\Auth0TokenVerifier;
use App\Support\Auth\JitProvisioner;
use App\Support\CapabilityMap;
use App\Support\CredentialStore;
use App\Support\Diagnostics\RoutingMetricStore;
use App\Support\Models\ModelCatalogueLookup;
use App\Support\Orchestration\AssistantAgentRunner;
use App\Support\Orchestration\TenantContextResolver;
use App\Support\Pro\ToolkitEngine;
use App\Support\Professions\ProfessionOverlayStore;
use App\Support\Pro\ToolkitManifestLoader;
use App\Support\RemoteSites\RemoteSiteStore;
use App\Support\Security\RootKey;
use App\Support\Security\SecuritySnapshotStore;
use App\Support\Settings\SettingsService;
use App\Support\Token\TiktokenService;
use App\Support\ToolFlags\ToolFlagOverrideStore;
use App\Support\ToolQuota\ToolQuotaGuard;
use App\Support\ToolQuota\ToolQuotaRecorder;
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
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\AgentRunnerInterface;
use Nvoos\Core\Domain\Contract\ApprovalTicketInterface;
use Nvoos\Core\Domain\Contract\AssistantServiceInterface;
use Nvoos\Core\Domain\Contract\CapabilityFlagOverridesInterface;
use Nvoos\Core\Domain\Contract\EventDispatcherInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\MemoryStoreInterface;
use Nvoos\Core\Domain\Contract\ModelLimitCatalogueInterface;
use Nvoos\Core\Domain\Contract\ModelRoutingCatalogueInterface;
use Nvoos\Core\Domain\Contract\ProEntitlementInterface;
use Nvoos\Core\Domain\Contract\ProfessionOverlayInterface;
use Nvoos\Core\Domain\Contract\ProfessionWriteInterface;
use Nvoos\Core\Domain\Contract\RoutingMetricsInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\TeamServiceInterface;
use Nvoos\Core\Domain\Contract\TenantResolverInterface;
use Nvoos\Core\Domain\Contract\TiktokenServiceInterface;
use Nvoos\Core\Domain\Contract\TranscriptStoreInterface;
use Nvoos\Core\Domain\Event\AfterToolExecution;
use Nvoos\Core\Domain\ValueObject\TurnBudget;
use Nvoos\Laravel\Adapter\EventDispatcher;
use Nvoos\Laravel\Adapter\MemoryStore;
use Nvoos\Laravel\Adapter\ProfessionRepository;
use Nvoos\Laravel\Adapter\SettingsStore;
use Nvoos\Laravel\Adapter\TranscriptStore;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // ── The tool dispatcher must be shared (149 §176) ──────────
        //
        // `ToolRegistry` captures the instance it is constructed with, so a
        // dispatcher rebuilt per resolution means a listener registered anywhere
        // else is silently dropped — it lands on an object nobody dispatches
        // through. That is exactly what a `bind` on either side caused
        // (149 §179.4).
        //
        // An event dispatcher is a shared hub, and one-instance-per-resolution
        // makes listener registration a lottery. Sharing it is what lets the
        // per-tool quota waterfall reach the registry's own `tools/pre_execute`.
        //
        // The package shares it now too; this is restated so the app never
        // depends on the package's default.
        //
        // To hear the after hook, register on Laravel's dispatcher instead: this
        // class's `listen()` registers with both maps and `dispatch()` fires
        // both, which would double-charge every tool.
        $this->app->singleton(EventDispatcherInterface::class, EventDispatcher::class);

        $this->registerToolQuotaListeners();

        // ── Per-tool capability-flag overrides (149 §177) ─────────
        //
        // The guards take this as an optional core port (addition-only), so a
        // host that never binds it keeps the behaviour it had before the port.
        // Binding here is what makes the estate's overrides reach the read-only
        // profile, the chat profile and the necessity gate at once.
        $this->app->bind(CapabilityFlagOverridesInterface::class, ToolFlagOverrideStore::class);

        // ── The turn's token ceilings (149 §179) ──────────────────
        //
        // Read from the estate's settings, and SCOPED rather than singleton: the
        // value is per-estate, and an Octane worker must not pin one estate's
        // ceilings for the next request (the T-0.8 rule). Zero in both dimensions
        // is off, so an estate that never sets them behaves as before.
        $this->app->scoped(TurnBudget::class, static function ($app): TurnBudget {
            $settings = $app->make(SettingsService::class);

            return TurnBudget::fromArray(array(
                'per_call_tokens' => (int) ($settings->value('turn_max_call_tokens') ?? 0),
                'per_turn_tokens' => (int) ($settings->value('turn_max_turn_tokens') ?? 0),
                'warn_at' => (float) ($settings->value('turn_warn_at') ?? 0.8),
            ));
        });

        // The assistant and team contracts are host-specific (Eloquent
        // models), so they are bound here rather than in the package provider.
        $this->app->bind(AssistantServiceInterface::class, LaravelAssistantService::class);
        $this->app->bind(TeamServiceInterface::class, LaravelTeamService::class);

        // The profession catalogue's tenant tier (Proposal 149 §170). The
        // package binds a JSON overlay and a merge decorator around it; this
        // points both at the estate-scoped table instead. `Profession`'s global
        // scope supplies the estate, so the base repository passed for
        // copy-on-write seeding is the BASE tier, never the decorator — asking
        // the decorator for a slug would come straight back here.
        //
        // SCOPED, not singleton: `overlayVersion()` is memoised so the cache key
        // is cheap, and that memo has to die with the request rather than with
        // the Octane worker that served it (the T-0.8 rule).
        $this->app->scoped(
            ProfessionOverlayStore::class,
            static fn ($app): ProfessionOverlayStore => new ProfessionOverlayStore(
                new ProfessionRepository($app['config'])
            )
        );
        $this->app->alias(ProfessionOverlayStore::class, ProfessionOverlayInterface::class);
        $this->app->alias(ProfessionOverlayStore::class, ProfessionWriteInterface::class);

        // The model catalogue answers routing and limit questions for two
        // consumers: ProviderRouter (fallback + hop eligibility) and the
        // token budget (context window / output budget). Both contracts are
        // bound to one reader, so a single query path and a single set of
        // guards serve both (Proposal 149 §69-§70).
        $this->app->bind(ModelRoutingCatalogueInterface::class, ModelCatalogueLookup::class);
        $this->app->bind(ModelLimitCatalogueInterface::class, ModelCatalogueLookup::class);

        // Token counting (Proposal 149 §71). The adapter prefers real tiktoken
        // when the optional package is installed and reports which mode is
        // live through isAvailable(), so pre-flight accuracy is observable.
        $this->app->bind(TiktokenServiceInterface::class, TiktokenService::class);

        // Model-fallback counters (Proposal 149 §71). One row per attempt;
        // the diagnostics page aggregates it.
        $this->app->bind(RoutingMetricsInterface::class, RoutingMetricStore::class);

        // Approval tickets (Proposal 149 §157). The necessity gate records a
        // ticket when a call needs a human and spends it on the retry. Kept out
        // of the package because it needs the app's tenant context and the
        // Approval model's estate scope.
        $this->app->bind(ApprovalTicketInterface::class, DatabaseApprovalTicket::class);

        // Orchestration's two host seams (Proposal 149 §163). The adapter itself
        // is bound by the package; these make it functional here: a runner that
        // reuses the workflow node's bounded turn, and the estate resolver the
        // adapter namespaces team records with.
        $this->app->bind(AgentRunnerInterface::class, AssistantAgentRunner::class);
        $this->app->bind(TenantResolverInterface::class, TenantContextResolver::class);

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

        // 149 §188 — the root security key lives in the operator layer, so it must
        // be built against the platform store explicitly. Letting the container
        // auto-resolve `SettingsStoreInterface` would hand it the estate layer and
        // the key would be read from, and written to, the wrong table.
        $this->app->bind(
            RootKey::class,
            static fn ($app): RootKey => new RootKey($app->make('nvoos.settings.platform'))
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
     * Wire per-tool daily quotas (149 §176).
     *
     * **A waterfall listener, not a monotonic guard.** `tools/pre_execute` is
     * the registry's allow/deny seam, so a refusal surfaces as `tool_denied`
     * carrying the quota's own message — the model can read it and route around
     * the tool instead of retrying. A guard would produce the same refusal with
     * a generic reason.
     *
     * **Registered in `register()`, not `boot()`.** `ToolRegistry` is a singleton
     * built with whichever dispatcher instance it is handed, and it may be
     * resolved during another provider's boot. Waiting for `afterResolving` would
     * be too late: a singleton already in the container is returned without
     * firing resolving callbacks. This is also why the dispatcher is bound as a
     * singleton above.
     *
     * **The recorder goes on Laravel's dispatcher, not this one.**
     * `EventDispatcher::listen()` registers the callable in its own PSR-14 map
     * *and* with Laravel, and `dispatch()` fires both — for a counter that means
     * charging every tool twice. Laravel's dispatcher alone is reached exactly
     * once, from `dispatch()`'s framework pass, whichever instance dispatched.
     *
     * Both halves re-read `oos.tool_quotas.enabled` per call rather than
     * snapshotting it here, so the master switch also works when it is flipped
     * after boot (tests, tinker) and not only on a fresh deploy.
     */
    private function registerToolQuotaListeners(): void
    {
        // A **monotonic guard**, not a `tools/pre_execute` waterfall listener.
        //
        // The waterfall was the first attempt and it is the wrong seam for this
        // host: a listener has to be registered on the exact `EventDispatcher`
        // instance the registry captured, and a host that binds that interface as
        // a singleton can still lose the race if the registry is resolved first.
        // The listener then never fires and the quota silently never refuses —
        // which is exactly what happened. `addGuard()` is held by the registry
        // itself, so there is no instance to match and no ordering to get wrong.
        //
        // The denial surfaces as `tool_guarded` instead of `tool_denied`. The
        // message is unchanged and still names the tool, the ceiling and the
        // reset, which is what the model needs to route around it.
        $this->app->afterResolving(ToolRegistry::class, static function (ToolRegistry $registry, $app): void {
            $quotas = (array) $app['config']->get('oos.tool_quotas', array());

            if (! (bool) ($quotas['enabled'] ?? true)) {
                return;
            }

            $registry->addGuard($app->make(ToolQuotaGuard::class));
        });

        // The recorder stays on **Laravel's** dispatcher, not this package's:
        // `EventDispatcher::listen()` registers a callable in its own PSR-14 map
        // *and* with Laravel, and `dispatch()` fires both — for a counter that
        // means charging every tool twice. Laravel's dispatcher alone is reached
        // exactly once, from `dispatch()`'s framework pass, whichever instance
        // dispatched.
        Event::listen(
            AfterToolExecution::class,
            static function (AfterToolExecution $event): void {
                app(ToolQuotaRecorder::class)->record(
                    $event->toolSlug,
                    $event->context,
                    $event->result
                );
            }
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ── The Auth0 bearer guard (149 §102) ────────────────────────
        //
        // A driver rather than a middleware so `auth:sanctum,auth0` works the
        // way Laravel's guard list is meant to: Sanctum answers every request it
        // can, and this one is consulted only when Sanctum cannot. Mounting it
        // as middleware would have had to reproduce that ordering by hand and
        // would have run on requests that already had a principal.
        Auth::extend(
            'auth0',
            static fn ($app): Auth0Guard => new Auth0Guard(
                $app['request'],
                $app->make(Auth0TokenVerifier::class),
                $app->make(SettingsService::class),
                $app->make(JitProvisioner::class),
            )
        );

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

        // File-level access (Proposal 162). `FileStore` has always named this
        // ability as its extension point, but nothing defined it, so
        // `userCanAccess()` returned false for every caller and a file was
        // reachable only by its owner. The store passes [userId, fileId] rather
        // than trusting the session, because a tool may act for a different user
        // than the request — the same reason the content abilities take an
        // explicit user.
        //
        // Ownership is decided by the store before this runs. What is left is
        // "somebody else's file", which needs an administrative capability: an
        // estate admin reviewing an upload should not have to be its author.
        Gate::define(
            'oos-file.access',
            static function ($user, $userId = null, $fileId = null): bool {
                $target = self::contentUser($user, $userId);

                return $target !== null && CapabilityMap::has($target, 'manage_options');
            }
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

        // Magic-link sign-in (149 §103). Two dimensions on the request endpoint —
        // per identifier and per address — because either alone is bypassable:
        // an IP limit lets one attacker spray one inbox from many hosts, and an
        // identifier limit lets one host spray many inboxes. The values mirror
        // the WordPress controller (3 per identifier per 15 min, 10 per IP per
        // hour, 10 confirms per IP per 15 min).
        RateLimiter::for('magic-login-request', fn (Request $request) => array(
            Limit::perMinutes(15, 3)->by('id:'.strtolower(trim((string) $request->input('email')))),
            Limit::perHour(10)->by('ip:'.$request->ip()),
        ));

        // Confirm is limited on the address only: the token is already a secret
        // the caller either has or does not, and keying on it would let an
        // attacker fill the cache with junk keys.
        RateLimiter::for('magic-login-confirm', fn (Request $request) => Limit::perMinutes(15, 10)->by('ip:'.$request->ip()));

        $router = $this->app->make(ProviderRouter::class);

        // Model-level retry before a provider hop (Proposal 149 §69). The
        // catalogue is optional and fails closed: no table or no row means the
        // router behaves exactly as it did before.
        $router->setModelCatalogue($this->app->make(ModelRoutingCatalogueInterface::class));

        // Fallback counters (Proposal 149 §71). A write never affects routing.
        $router->setMetrics($this->app->make(RoutingMetricsInterface::class));

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
