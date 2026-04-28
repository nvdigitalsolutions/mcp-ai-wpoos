<?php
/**
 * NV oOS Graphify — Remote Source Registry
 *
 * Singleton registry for remote-source drivers. Drivers are registered via
 * the 'nvoos_graphify_register_remote_sources' action hook.
 *
 * Usage:
 *   NV_oOS_Graphify_Remote_Registry::get_instance()->register_driver( 'wikidata', 'NV_oOS_Graphify_Remote_Wikidata' );
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
	 * Registered drivers: driver_id => class_name.
	 *
	 * @var array
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
	 * Register a driver class for a given driver ID.
	 *
	 * @since 0.6.0
	 *
	 * @param string $driver_id  Unique driver identifier.
	 * @param string $class_name Fully-qualified class name implementing NV_oOS_Graphify_Remote_Source_Interface.
	 * @return void
	 */
	public function register_driver( $driver_id, $class_name ) {
		$driver_id  = sanitize_key( $driver_id );
		$class_name = sanitize_text_field( $class_name );
		if ( $driver_id && $class_name ) {
			$this->drivers[ $driver_id ] = $class_name;
		}
	}

	/**
	 * Return all registered drivers (driver_id => class_name).
	 *
	 * @since 0.6.0
	 *
	 * @return array
	 */
	public function get_drivers() {
		$this->init_drivers();
		return $this->drivers;
	}

	/**
	 * Instantiate and return a driver by ID with the given config.
	 *
	 * @since 0.6.0
	 *
	 * @param string $driver_id Unique driver identifier.
	 * @param array  $config    Configuration array passed to set_config().
	 * @return NV_oOS_Graphify_Remote_Source_Interface|null Driver instance or null if not found.
	 */
	public function get_driver_instance( $driver_id, array $config = array() ) {
		$this->init_drivers();
		$driver_id = sanitize_key( $driver_id );
		if ( ! isset( $this->drivers[ $driver_id ] ) ) {
			return null;
		}
		$class = $this->drivers[ $driver_id ];
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
					// Decrypt sensitive values.
					foreach ( $raw_config as $k => $v ) {
						if ( is_string( $v ) && ( false !== strpos( $k, 'token' ) || false !== strpos( $k, 'password' ) || false !== strpos( $k, 'secret' ) || false !== strpos( $k, 'key' ) ) ) {
							$raw_config[ $k ] = NV_oOS_Graphify_Crypto::decrypt( $v );
						}
					}
					$config = $raw_config;
				}
			}
			$config['_slug']          = $row->slug;
			$config['_rate_limit']    = absint( $row->rate_limit );
			$config['_circuit_state'] = $row->circuit_state;
			$instance = $this->get_driver_instance( $row->driver, $config );
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
