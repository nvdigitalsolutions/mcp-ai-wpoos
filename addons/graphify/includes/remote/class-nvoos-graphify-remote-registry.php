<?php
/**
 * NV oOS Graphify — Remote Source Registry
 *
 * Singleton registry for remote-source drivers. Drivers are registered via
 * the 'nvoos_graphify_register_remote_sources' action hook.
 *
 * Usage:
 *   NV_oOS_Graphify_Remote_Registry::get_instance()->register_driver( new NV_oOS_Graphify_Remote_Wikidata() );
 *   $sources = NV_oOS_Graphify_Remote_Registry::get_instance()->get_active_sources();
 *
 * @package NV_oOS_Graphify
 * @since   0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Singleton registry for remote-source drivers.
 *
 * @since 0.6.0
 */
class NV_oOS_Graphify_Remote_Registry {

	/**
	 * Singleton instance.
	 *
	 * @var NV_oOS_Graphify_Remote_Registry|null
	 */
	private static $instance = null;

	/**
	 * Registered driver instances, keyed by driver ID.
	 *
	 * @var NV_oOS_Graphify_Remote_Source_Interface[]
	 */
	private $drivers = array();

	/**
	 * Whether drivers have been initialized via action hook.
	 *
	 * @var bool
	 */
	private $initialized = false;

	/**
	 * Private constructor — use get_instance().
	 */
	private function __construct() {}

	/**
	 * Return the singleton instance.
	 *
	 * @since 0.6.0
	 *
	 * @return NV_oOS_Graphify_Remote_Registry
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register a driver instance.
	 *
	 * @since 0.6.0
	 *
	 * @param NV_oOS_Graphify_Remote_Source_Interface $driver Driver instance.
	 * @return void
	 */
	public function register_driver( NV_oOS_Graphify_Remote_Source_Interface $driver ) {
		$driver_id = sanitize_key( $driver->get_driver_id() );
		if ( $driver_id ) {
			$this->drivers[ $driver_id ] = $driver;
		}
	}

	/**
	 * Return all registered driver instances, keyed by driver ID.
	 *
	 * @since 0.6.0
	 *
	 * @return NV_oOS_Graphify_Remote_Source_Interface[]
	 */
	public function get_drivers() {
		$this->init_drivers();
		return $this->drivers;
	}

	/**
	 * Return the registered driver slugs (IDs).
	 *
	 * @since 0.6.0
	 *
	 * @return string[]
	 */
	public function get_registered_driver_slugs() {
		$this->init_drivers();
		return array_keys( $this->drivers );
	}

	/**
	 * Return a registered prototype driver instance by ID.
	 *
	 * Returns the registered instance with no per-source config applied.
	 * Use get_driver_instance() to get a configured driver.
	 *
	 * @since 0.6.0
	 *
	 * @param string $driver_id Driver identifier.
	 * @return NV_oOS_Graphify_Remote_Source_Interface|null Driver instance or null if not found.
	 */
	public function get_driver( $driver_id ) {
		$this->init_drivers();
		$driver_id = sanitize_key( $driver_id );
		return isset( $this->drivers[ $driver_id ] ) ? $this->drivers[ $driver_id ] : null;
	}

	/**
	 * Return a freshly-configured driver instance for the given driver ID.
	 *
	 * @since 0.6.0
	 *
	 * @param string $driver_id Driver identifier.
	 * @param array  $config    Configuration array passed to set_config().
	 * @return NV_oOS_Graphify_Remote_Source_Interface|null Driver instance or null if not found.
	 */
	public function get_driver_instance( $driver_id, array $config = array() ) {
		$this->init_drivers();
		$driver_id = sanitize_key( $driver_id );
		if ( ! isset( $this->drivers[ $driver_id ] ) ) {
			return null;
		}
		$class = get_class( $this->drivers[ $driver_id ] );
		if ( ! class_exists( $class ) ) {
			return null;
		}
		$instance = new $class();
		if ( ! empty( $config ) ) {
			$instance->set_config( $config );
		}
		return $instance;
	}

	/**
	 * Return instantiated driver objects for all enabled DB-configured sources.
	 *
	 * @since 0.6.0
	 *
	 * @return NV_oOS_Graphify_Remote_Source_Interface[]
	 */
	public function get_active_sources() {
		$this->init_drivers();
		$rows    = NV_oOS_Graphify_DB::get_remote_sources( array( 'enabled' => 1 ) );
		$sources = array();
		foreach ( $rows as $row ) {
			$config = array();
			if ( ! empty( $row->config_json ) ) {
				$raw_config = json_decode( $row->config_json, true );
				if ( is_array( $raw_config ) ) {
					foreach ( $raw_config as $k => $v ) {
						if ( is_string( $v ) && NV_oOS_Graphify_Crypto::is_sensitive_key( $k ) ) {
							$raw_config[ $k ] = NV_oOS_Graphify_Crypto::decrypt( $v );
						}
					}
					$config = $raw_config;
				}
			}
			$config['_slug']          = $row->slug;
			$config['_rate_limit']    = absint( $row->rate_limit );
			$config['_circuit_state'] = $row->circuit_state;
			$instance                 = $this->get_driver_instance( $row->driver, $config );
			if ( $instance ) {
				$sources[ $row->slug ] = $instance;
			}
		}
		return $sources;
	}

	/**
	 * Fire the registration action hook once to allow drivers to register themselves.
	 *
	 * @since 0.6.0
	 *
	 * @return void
	 */
	private function init_drivers() {
		if ( $this->initialized ) {
			return;
		}
		$this->initialized = true;

		/**
		 * Fires when remote source drivers should be registered.
		 *
		 * @since 0.6.0
		 *
		 * @param NV_oOS_Graphify_Remote_Registry $registry The registry instance.
		 */
		do_action( 'nvoos_graphify_register_remote_sources', $this );
	}
}
