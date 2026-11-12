<?php

namespace App\Providers;

use App\Services\LaravelAssistantService;
use App\Spike\SpikeStreamingProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\AssistantServiceInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The assistant contract is host-specific (Eloquent model), so it is
        // bound here rather than in the package provider.
        $this->app->bind(AssistantServiceInterface::class, LaravelAssistantService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // The spike uses permissive Gates; production apps replace these
        // with real policy checks.
        Gate::define('read', fn ($user) => true);
        Gate::define('read_content', fn ($user, $id = null, $userId = null) => true);
        Gate::define('edit_content', fn ($user, $id = null, $userId = null) => true);
        Gate::define('delete_content', fn ($user, $id = null, $userId = null) => true);

        // Local/testing soak runs need headroom; production keeps 60/min.
        $chatLimit = app()->environment(['local', 'testing']) ? 60000 : 60;

        RateLimiter::for('chat', fn (Request $request) => Limit::perMinute($chatLimit)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
        RateLimiter::for('tools', fn (Request $request) => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()));

        $router = $this->app->make(ProviderRouter::class);

        if (! $router->has('spike')) {
            $router->register(new SpikeStreamingProvider(
                $this->app->make(SettingsStoreInterface::class),
                $this->app->make(HttpClientInterface::class),
                $this->app->make(ErrorFactoryInterface::class),
            ));
        }
    }
}
