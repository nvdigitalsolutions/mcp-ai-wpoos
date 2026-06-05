<?php
/**
 * Remote source driver registry.
 *
 * Singleton registry for remote-source drivers. Drivers register via the
 * `nvoos_graphify_register_remote_sources` action hook.
 *
 * @since   1.0.0
 * @package NvoosGraphify
 */

declare(strict_types=1);

namespace NvoosGraphify\Remote;

use NvoosGraphify\Contracts\RemoteSourceDriver;
use NvoosGraphify\Graph\Db;
use NvoosGraphify\Remote\Crypto;

/**
 * Singleton registry for remote-source drivers.
 *
 * @since 1.0.0
 */
final class Registry
{
    /**
     * Singleton instance.
     *
     * @var self|null
     */
    private static ?self $instance = null;

    /**
     * Registered driver instances, keyed by driver ID.
     *
     * @var array<string,RemoteSourceDriver>
     */
    private array $drivers = array();

    /**
     * Whether drivers have been initialized via action hook.
     *
     * @var bool
     */
    private bool $initialized = false;

    /**
     * Private constructor — use Registry::instance().
     */
    private function __construct() {}

    /**
     * Return the singleton instance.
     *
     * @since 1.0.0
     * @return self
     */
    public static function instance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Register a driver instance.
     *
     * @since 1.0.0
     * @param RemoteSourceDriver $driver Driver instance.
     * @return void
     */
    public function registerDriver(RemoteSourceDriver $driver): void
    {
        $driverId = sanitize_key($driver->getDriverId());
        if ($driverId) {
            $this->drivers[$driverId] = $driver;
        }
    }

    /**
     * Return all registered driver instances.
     *
     * @since 1.0.0
     * @return array<string,RemoteSourceDriver>
     */
    public function getDrivers(): array
    {
        $this->initDrivers();
        return $this->drivers;
    }

    /**
     * Return the registered driver slugs (IDs).
     *
     * @since 1.0.0
     * @return string[]
     */
    public function getRegisteredDriverSlugs(): array
    {
        $this->initDrivers();
        return array_keys($this->drivers);
    }

    /**
     * Return a registered prototype driver instance by ID.
     *
     * @since 1.0.0
     * @param string $driverId Driver identifier.
     * @return RemoteSourceDriver|null Driver instance or null if not found.
     */
    public function getDriver(string $driverId): ?RemoteSourceDriver
    {
        $this->initDrivers();
        $driverId = sanitize_key($driverId);
        return $this->drivers[$driverId] ?? null;
    }

    /**
     * Return a freshly-configured driver instance for the given driver ID.
     *
     * @since 1.0.0
     * @param string               $driverId Driver identifier.
     * @param array<string,mixed>  $config   Configuration array.
     * @return RemoteSourceDriver|null
     */
    public function getDriverInstance(string $driverId, array $config = array()): ?RemoteSourceDriver
    {
        $this->initDrivers();
        $driverId = sanitize_key($driverId);
        if (! isset($this->drivers[$driverId])) {
            return null;
        }
        $class = get_class($this->drivers[$driverId]);
        if (! class_exists($class)) {
            return null;
        }
        /** @var RemoteSourceDriver $instance */
        $instance = new $class();
        if (! empty($config)) {
            $instance->setConfig($config);
        }
        return $instance;
    }

    /**
     * Return instantiated driver objects for all enabled DB-configured sources.
     *
     * @since 1.0.0
     * @return array<string,RemoteSourceDriver>
     */
    public function getActiveSources(): array
    {
        $this->initDrivers();
        $rows    = Db::listRemoteSources(array('enabled' => 1));
        $sources = array();
        foreach ($rows as $row) {
            $config = array();
            if (! empty($row->config_json)) {
                $rawConfig = json_decode($row->config_json, true);
                if (is_array($rawConfig)) {
                    foreach ($rawConfig as $k => $v) {
                        if (is_string($v) && Crypto::isSensitiveKey($k)) {
                            $rawConfig[$k] = Crypto::decrypt($v);
                        }
                    }
                    $config = $rawConfig;
                }
            }
            $config['_slug']          = $row->slug;
            $config['_rate_limit']    = absint($row->rate_limit);
            $config['_circuit_state'] = $row->circuit_state;
            $instance                 = $this->getDriverInstance($row->driver, $config);
            if ($instance) {
                $sources[$row->slug] = $instance;
            }
        }
        return $sources;
    }

    /**
     * Fire the registration action hook once to allow drivers to register themselves.
     *
     * @since 1.0.0
     * @return void
     */
    private function initDrivers(): void
    {
        if ($this->initialized) {
            return;
        }
        $this->initialized = true;

        /**
         * Fires when remote source drivers should be registered.
         *
         * @since 0.6.0
         * @param Registry $registry The registry instance.
         */
        do_action('nvoos_graphify_register_remote_sources', $this);
    }
}
