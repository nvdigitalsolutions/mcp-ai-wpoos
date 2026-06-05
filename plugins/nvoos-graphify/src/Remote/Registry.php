<?php
declare(strict_types=1);

namespace NvoosGraphify\Remote;

use NvoosGraphify\Contracts\RemoteSource;

/**
 * Registry for remote-source driver instances.
 *
 * Drivers are registered via {@see registerDriver()} during
 * the `nvoos_graphify/register_remote_sources` action and
 * looked up by their unique slug.
 *
 * @since 1.0.0
 */
final class Registry
{
    /**
     * Registered drivers, keyed by slug.
     *
     * @var array<string,RemoteSource>
     */
    private array $drivers = array();

    /**
     * Register a remote-source driver.
     *
     * @param RemoteSource $driver The driver instance.
     * @return void
     */
    public function registerDriver( RemoteSource $driver ): void
    {
        $this->drivers[ $driver->getDriverId() ] = $driver;
    }

    /**
     * Retrieve a driver by its slug.
     *
     * @param string $slug The driver identifier.
     * @return RemoteSource|null
     */
    public function getDriver( string $slug ): ?RemoteSource
    {
        return $this->drivers[ $slug ] ?? null;
    }

    /**
     * Return all registered drivers.
     *
     * @return array<string,RemoteSource>
     */
    public function allDrivers(): array
    {
        return $this->drivers;
    }

    /**
     * Return driver metadata suitable for the REST API and admin UI.
     *
     * Returns an array of arrays, each containing 'slug', 'label',
     * 'capabilities', and 'config_schema'.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listDrivers(): array
    {
        $list = array();
        foreach ( $this->drivers as $slug => $driver ) {
            $list[] = array(
                'slug'          => $slug,
                'label'         => $driver->getDriverLabel(),
                'capabilities'  => $driver->getCapabilities(),
                'config_schema' => $driver->getConfigSchema(),
            );
        }
        return $list;
    }
}
