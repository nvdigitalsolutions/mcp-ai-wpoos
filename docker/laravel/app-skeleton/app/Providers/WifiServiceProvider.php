<?php

namespace App\Providers;

use App\Wifi\CacheKpiSink;
use App\Wifi\Clock;
use App\Wifi\IdGenerator;
use App\Wifi\VenueRepository;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Support\ServiceProvider;
use NvoosWifi\Infrastructure\Providers\Class_Mock_Controller_Adapter;
use NvoosWifi\Interfaces\Interface_WiFi_Clock;
use NvoosWifi\Interfaces\Interface_WiFi_Controller_Adapter;
use NvoosWifi\Interfaces\Interface_WiFi_Id_Generator;
use NvoosWifi\Interfaces\Interface_WiFi_Kpi_Sink;
use NvoosWifi\Services\Class_Kpi_Service;

/**
 * Composition root for the Laravel WiFi host (Proposal 150, T-0.10).
 *
 * This is the mirror image of `addons/nvoos-wifi/includes/Plugin.php`: the same
 * four ports, bound to different implementations, with the *same* domain and
 * service classes underneath.
 *
 * Note what is reused rather than reimplemented:
 *
 *  - `Class_Mock_Controller_Adapter` — the addon's mock is pure PHP with no
 *    WordPress dependency, so the Laravel host gets identical deterministic
 *    telemetry. That is what makes the parity fixture meaningful.
 *  - `Class_Kpi_Service`, all `Domain\*` value objects, all `Interfaces\*`.
 *
 * What is host-specific: the clock, the id generator, the sink, and the venue
 * lookup. That is the whole list, and it should stay that short.
 *
 * ⚠️ Nothing here may reference `NvoosWifi\Infrastructure\Wp\*`,
 * `NvoosWifi\REST\*` or `WP_MCP_AI_Tool_*`. Those files carry an `ABSPATH` guard
 * that `exit`s, so touching one from Laravel terminates the request silently.
 * The package's `exclude-from-classmap` keeps them out of an optimised classmap,
 * but PSR-4 can still resolve them — this comment is the real guard.
 */
final class WifiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Interface_WiFi_Clock::class, Clock::class);
        $this->app->singleton(Interface_WiFi_Id_Generator::class, IdGenerator::class);
        $this->app->singleton(VenueRepository::class);

        $this->app->singleton(
            Interface_WiFi_Kpi_Sink::class,
            static fn ($app): Interface_WiFi_Kpi_Sink => new CacheKpiSink(
                $app->make(CacheRepository::class),
            ),
        );

        // Phase 0 always binds the mock. Phase 2 introduces adapter selection
        // here, with the mock remaining the fallback so a fresh deployment is
        // never broken.
        $this->app->singleton(
            Interface_WiFi_Controller_Adapter::class,
            static fn (): Interface_WiFi_Controller_Adapter => new Class_Mock_Controller_Adapter(
                (string) config('wifi.mock_seed', 'mock-default'),
            ),
        );

        // Lazy closure, not an eagerly built instance: a test (or a later host)
        // must be able to rebind `Interface_WiFi_Clock` before the service is
        // first resolved, which is how the parity test freezes time.
        $this->app->singleton(
            Class_Kpi_Service::class,
            static fn ($app): Class_Kpi_Service => new Class_Kpi_Service(
                $app->make(Interface_WiFi_Controller_Adapter::class),
                $app->make(Interface_WiFi_Kpi_Sink::class),
                $app->make(Interface_WiFi_Clock::class),
            ),
        );
    }
}
