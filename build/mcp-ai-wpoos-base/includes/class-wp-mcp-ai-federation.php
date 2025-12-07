<?php
/**
 * Federation system bootstrap.
 *
 * Coordinates all federation components and conditionally loads them based on settings.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main bootstrap class for the federation system.
 */
class WP_MCP_AI_Federation {

	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $registry;

	/**
	 * Federation settings handler.
	 *
	 * @var WP_MCP_AI_Federation_Settings
	 */
	protected $settings_handler;

	/**
	 * Well-known endpoints handler.
	 *
	 * @var WP_MCP_AI_Federation_WellKnown
	 */
	protected $wellknown_handler;

	/**
	 * AI Peer CPT handler.
	 *
	 * @var WP_MCP_AI_AI_Peer_CPT
	 */
	protected $peer_cpt_handler;

	/**
	 * Directory REST API handler.
	 *
	 * @var WP_MCP_AI_Federation_Directory_REST
	 */
	protected $directory_rest_handler;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_Tool_Registry $registry Tool registry instance.
	 */
	public function __construct( WP_MCP_AI_Tool_Registry $registry ) {
		$this->registry = $registry;

		// Always load settings handler (so users can enable/disable features).
		$this->settings_handler = new WP_MCP_AI_Federation_Settings();

		// Load federation components conditionally based on settings.
		add_action( 'init', array( $this, 'maybe_load_federation_features' ), 5 );

		// Schedule health check cron.
		add_action( 'wp_mcp_ai_verify_peers', array( 'WP_MCP_AI_Federation_Peer_Verifier', 'verify_all_peers' ) );

		// Register activation/deactivation hooks.
		register_activation_hook( WP_MCP_AI_PATH . 'mcp-ai-wpoos.php', array( $this, 'on_activation' ) );
		register_deactivation_hook( WP_MCP_AI_PATH . 'mcp-ai-wpoos.php', array( $this, 'on_deactivation' ) );
	}

	/**
	 * Conditionally load federation features based on settings.
	 */
	public function maybe_load_federation_features() {
		$is_federation_enabled = WP_MCP_AI_Federation_Settings::is_federation_enabled();
		$is_directory_enabled  = WP_MCP_AI_Federation_Settings::is_directory_enabled();

		// Load well-known endpoints if federation is enabled.
		if ( $is_federation_enabled ) {
			$this->wellknown_handler = new WP_MCP_AI_Federation_WellKnown( $this->registry );
		}

		// Load directory features if directory service is enabled.
		if ( $is_directory_enabled ) {
			$this->peer_cpt_handler       = new WP_MCP_AI_AI_Peer_CPT();
			$this->directory_rest_handler = new WP_MCP_AI_Federation_Directory_REST();

			// Schedule peer verification cron if not already scheduled.
			if ( ! wp_next_scheduled( 'wp_mcp_ai_verify_peers' ) ) {
				wp_schedule_event( time(), 'hourly', 'wp_mcp_ai_verify_peers' );
			}
		} else {
			// Unschedule cron if directory is disabled.
			$timestamp = wp_next_scheduled( 'wp_mcp_ai_verify_peers' );
			if ( $timestamp ) {
				wp_unschedule_event( $timestamp, 'wp_mcp_ai_verify_peers' );
			}
		}
	}

	/**
	 * Handle plugin activation.
	 */
	public function on_activation() {
		// Flush rewrite rules for well-known endpoints.
		if ( WP_MCP_AI_Federation_Settings::is_federation_enabled() ) {
			WP_MCP_AI_Federation_WellKnown::activate();
		}

		// Schedule peer verification cron.
		if ( WP_MCP_AI_Federation_Settings::is_directory_enabled() ) {
			if ( ! wp_next_scheduled( 'wp_mcp_ai_verify_peers' ) ) {
				wp_schedule_event( time(), 'hourly', 'wp_mcp_ai_verify_peers' );
			}
		}
	}

	/**
	 * Handle plugin deactivation.
	 */
	public function on_deactivation() {
		// Flush rewrite rules.
		flush_rewrite_rules();

		// Clear scheduled cron events.
		$timestamp = wp_next_scheduled( 'wp_mcp_ai_verify_peers' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'wp_mcp_ai_verify_peers' );
		}
	}

	/**
	 * Check if federation features are available.
	 *
	 * @return bool True if federation is enabled.
	 */
	public static function is_enabled() {
		return WP_MCP_AI_Federation_Settings::is_federation_enabled();
	}

	/**
	 * Check if directory service is available.
	 *
	 * @return bool True if directory service is enabled.
	 */
	public static function is_directory_enabled() {
		return WP_MCP_AI_Federation_Settings::is_directory_enabled();
	}
}
