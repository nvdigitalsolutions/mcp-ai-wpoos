<?php

namespace App\Providers;

use App\Models\User;
use App\Services\LaravelAssistantService;
use App\Services\LaravelTeamService;
use App\Support\CapabilityMap;
use App\Spike\SpikeStreamingProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\AssistantServiceInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\TeamServiceInterface;

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

        if (! $router->has('spike')) {
            $router->register(new SpikeStreamingProvider(
                $this->app->make(SettingsStoreInterface::class),
                $this->app->make(HttpClientInterface::class),
                $this->app->make(ErrorFactoryInterface::class),
            ));
        }
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
