<?php
/**
 * Embedded Backend Registry
 *
 * Central registry for LLM inference backends following the WordPress 7.0
 * Connectors API pattern: register(), unregister(), has(), get(), get_all().
 *
 * @package NV_oOS_Embedded
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry singleton for embedded backends.
 *
 * @since 0.2.0
 */
class NV_oOS_Embedded_Backend_Registry {

	/**
	 * Singleton instance.
	 *
	 * @since 0.2.0
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Registered LLM backends, keyed by slug.
	 *
	 * @since 0.2.0
	 *
	 * @var array<string, NV_oOS_Embedded_LLM_Backend>
	 */
	private $llm_backends = array();

	/**
	 * Get singleton instance.
	 *
	 * @since 0.2.0
	 *
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Private constructor — use get_instance().
	 *
	 * @since 0.2.0
	 */
	private function __construct() {}

	// ── LLM Backend Methods ──────────────────────────────────────────

	/**
	 * Register an LLM backend.
	 *
	 * @since 0.2.0
	 *
	 * @param NV_oOS_Embedded_LLM_Backend $backend Backend instance.
	 * @return bool True on success, false if slug already registered.
	 */
	public function register_llm_backend( NV_oOS_Embedded_LLM_Backend $backend ) {
		$slug = $backend->get_slug();
		if ( isset( $this->llm_backends[ $slug ] ) ) {
			return false;
		}
		$this->llm_backends[ $slug ] = $backend;
		return true;
	}

	/**
	 * Unregister an LLM backend.
	 *
	 * @since 0.2.0
	 *
	 * @param string $slug Backend slug.
	 * @return NV_oOS_Embedded_LLM_Backend|null Removed backend or null if not found.
	 */
	public function unregister_llm_backend( $slug ) {
		if ( ! isset( $this->llm_backends[ $slug ] ) ) {
			return null;
		}
		$backend = $this->llm_backends[ $slug ];
		unset( $this->llm_backends[ $slug ] );
		return $backend;
	}

	/**
	 * Check if an LLM backend is registered.
	 *
	 * @since 0.2.0
	 *
	 * @param string $slug Backend slug.
	 * @return bool
	 */
	public function has_llm_backend( $slug ) {
		return isset( $this->llm_backends[ $slug ] );
	}

	/**
	 * Get a specific LLM backend.
	 *
	 * @since 0.2.0
	 *
	 * @param string $slug Backend slug.
	 * @return NV_oOS_Embedded_LLM_Backend|null
	 */
	public function get_llm_backend( $slug ) {
		return isset( $this->llm_backends[ $slug ] ) ? $this->llm_backends[ $slug ] : null;
	}

	/**
	 * Get all registered LLM backends.
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, NV_oOS_Embedded_LLM_Backend>
	 */
	public function get_all_llm_backends() {
		return $this->llm_backends;
	}

	/**
	 * Get available LLM backends (filtered by is_available).
	 *
	 * @since 0.2.0
	 *
	 * @return array<string, NV_oOS_Embedded_LLM_Backend>
	 */
	public function get_available_llm_backends() {
		return array_filter(
			$this->llm_backends,
			function ( $backend ) {
				return $backend->is_available();
			}
		);
	}

	/**
	 * Get the active LLM backend based on settings.
	 *
	 * Resolves the 'inference_backend' setting:
	 * - 'auto': prefers server-side if available, falls back to client-side.
	 * - 'client_side': returns the client-side backend if registered.
	 * - 'server_side': returns the server-side backend if registered and available.
	 *
	 * @since 0.2.0
	 *
	 * @return NV_oOS_Embedded_LLM_Backend|null Active backend, or null if none available.
	 */
	public function get_active_llm_backend() {
		$settings  = get_option( 'nvoos_embedded_settings', array() );
		$preferred = isset( $settings['inference_backend'] ) ? $settings['inference_backend'] : 'auto';

		// Explicit selection.
		if ( 'auto' !== $preferred && $this->has_llm_backend( $preferred ) ) {
			$backend = $this->get_llm_backend( $preferred );
			if ( $backend && $backend->is_available() ) {
				return $backend;
			}
		}

		// Auto mode: prefer server-side if available, fall back to client-side.
		$server = $this->get_llm_backend( 'server_side' );
		if ( $server && $server->is_available() ) {
			return $server;
		}

		return $this->get_llm_backend( 'client_side' );
	}
}
