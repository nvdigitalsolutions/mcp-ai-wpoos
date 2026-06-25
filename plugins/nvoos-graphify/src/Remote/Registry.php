<?php
declare(strict_types=1);

namespace NvoosGraphify\Remote;

use NvoosGraphify\Contracts\RemoteSource;
use NvoosGraphify\Graph\Db;

/**
 * Registry for remote-source driver instances.
 *
 * Drivers are registered via {@see registerDriver()} during
 * the `nvoos_graphify/register_remote_sources` action and
 * looked up by their unique slug.
 *
 * @since 1.0.0
 */
final class Registry {

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
	public function registerDriver( RemoteSource $driver ): void {
		$this->drivers[ $driver->getDriverId() ] = $driver;
	}

	/**
	 * Retrieve a driver by its slug.
	 *
	 * @param string $slug The driver identifier.
	 * @return RemoteSource|null
	 */
	public function getDriver( string $slug ): ?RemoteSource {
		return $this->drivers[ $slug ] ?? null;
	}

	/**
	 * Return all registered drivers.
	 *
	 * @return array<string,RemoteSource>
	 */
	public function allDrivers(): array {
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
	public function listDrivers(): array {
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

	/**
	 * Return a freshly-configured driver instance for the given driver ID.
	 *
	 * Clones the registered prototype and applies the given config, so
	 * that each DB-configured source gets its own independent instance.
	 *
	 * @param string              $driverId Driver identifier.
	 * @param array<string,mixed> $config   Configuration array.
	 * @return RemoteSource|null Driver instance or null if not found.
	 */
	public function getDriverInstance( string $driverId, array $config = array() ): ?RemoteSource {
		if ( ! isset( $this->drivers[ $driverId ] ) ) {
			return null;
		}
		$class = \get_class( $this->drivers[ $driverId ] );
		if ( ! \class_exists( $class ) ) {
			return null;
		}
		$instance = new $class();
		if ( ! empty( $config ) ) {
			$instance->setConfig( $config );
		}
		return $instance;
	}

	/**
	 * Return instantiated driver objects for all enabled DB-configured sources.
	 *
	 * @return array<string,RemoteSource>
	 */
	public function getActiveSources(): array {
		$rows    = Db::listRemoteSources( array( 'enabled' => 1 ) );
		$sources = array();
		foreach ( $rows as $row ) {
			$config = array();
			if ( ! empty( $row->config_json ) ) {
				$rawConfig = json_decode( $row->config_json, true );
				if ( is_array( $rawConfig ) ) {
					foreach ( $rawConfig as $k => $v ) {
						if ( is_string( $v ) && Crypto::isSensitiveKey( $k ) ) {
							$rawConfig[ $k ] = Crypto::decrypt( $v );
						}
					}
					$config = $rawConfig;
				}
			}
			$config['_slug']       = $row->slug;
			$config['_rate_limit'] = absint( $row->rate_limit ?? 0 );
			$instance              = $this->getDriverInstance( $row->driver, $config );
			if ( $instance ) {
				$sources[ $row->slug ] = $instance;
			}
		}
		return $sources;
	}
}
