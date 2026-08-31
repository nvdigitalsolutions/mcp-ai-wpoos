<?php
/**
 * Federation system bootstrap.
 *
 * Coordinates all federation components and conditionally loads them based on settings.
 *
 * @package NvoosContentGraphAiPlatform
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 * @since     2.0.0 Ported from mcp-ai-wpoos/includes/class-wp-mcp-ai-federation.php.
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Federation;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main bootstrap class for the federation system.
 *
 * Wired only in standalone mode (base plugin absent) via FederationService.
 * Mesh networking is not yet ported — the mesh bootstrap is intentionally
 * absent here and tracked in MIGRATION-GAPS.md.
 */
class Federation {

	/**
	 * Tool registry instance.
	 *
	 * @var object|null
	 */
	protected $registry;

	/**
	 * Well-known endpoints handler.
	 *
	 * @var WellKnown|null
	 */
	protected $wellknown_handler;

	/**
	 * AI Peer CPT handler.
	 *
	 * @var PeerCpt|null
	 */
	protected $peer_cpt_handler;

	/**
	 * Directory REST API handler.
	 *
	 * @var DirectoryRest|null
	 */
	protected $directory_rest_handler;

	/**
	 * A2A well-known handler.
	 *
	 * @var \NvoosContentGraphAiPlatform\A2A\WellKnown|null
	 */
	protected $a2a_wellknown_handler;

	/**
	 * Constructor.
	 *
	 * @param object|null $registry Tool registry instance (base plugin or Content Graph core).
	 */
	public function __construct( $registry = null ) {
		$this->registry = $registry;

		// Load federation components conditionally based on settings.
		add_action( 'init', array( $this, 'maybe_load_federation_features' ), 5 );

		// Load A2A well-known endpoint conditionally.
		add_action( 'init', array( $this, 'maybe_load_a2a_features' ), 5 );

		// Schedule health check cron.
		add_action( 'wp_mcp_ai_verify_peers', array( PeerVerifier::class, 'verify_all_peers' ) );

		// Register activation/deactivation hooks on this plugin's own file.
		if ( defined( 'NVOOS_CONTENT_GRAPH_AI_PLATFORM_FILE' ) ) {
			register_activation_hook( NVOOS_CONTENT_GRAPH_AI_PLATFORM_FILE, array( $this, 'on_activation' ) );
			register_deactivation_hook( NVOOS_CONTENT_GRAPH_AI_PLATFORM_FILE, array( $this, 'on_deactivation' ) );
		}
	}

	/**
	 * Conditionally load federation features based on settings.
	 */
	public function maybe_load_federation_features() {
		$is_federation_enabled = Settings::is_federation_enabled();
		$is_directory_enabled  = Settings::is_directory_enabled();
		$is_mesh_enabled       = Settings::is_mesh_enabled();

		// Load well-known endpoints if either federation or directory is enabled.
		if ( $is_federation_enabled || $is_directory_enabled ) {
			$this->wellknown_handler = new WellKnown( $this->registry );
		}

		// Load AI Peer CPT if either directory OR mesh is enabled.
		// Directory needs it for federation peers, mesh needs it for mesh peers.
		if ( $is_directory_enabled || $is_mesh_enabled ) {
			$this->peer_cpt_handler = new PeerCpt();
		}

		// Load directory REST API only if directory is enabled.
		if ( $is_directory_enabled ) {
			$this->directory_rest_handler = new DirectoryRest();

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

		// Mesh networking: not yet ported (tracked in MIGRATION-GAPS.md).
		// In standalone mode mesh stays unavailable until the mesh classes are
		// extracted; RuntimeMode reports the subsystem accordingly.

		// Check if we need to flush rewrite rules after CPT registration.
		// This ensures the AI Peers menu appears immediately after enabling directory service.
		if ( get_transient( 'wp_mcp_ai_flush_rewrite_rules' ) ) {
			delete_transient( 'wp_mcp_ai_flush_rewrite_rules' );
			flush_rewrite_rules();
		}
	}

	/**
	 * Conditionally load A2A protocol features based on settings.
	 */
	public function maybe_load_a2a_features() {
		$settings = get_option( Settings::OPTION_NAME, array() );

		if ( ! empty( $settings['enable_a2a_server'] ) && class_exists( '\NvoosContentGraphAiPlatform\A2A\WellKnown' ) ) {
			$this->a2a_wellknown_handler = new \NvoosContentGraphAiPlatform\A2A\WellKnown();
		}
	}

	/**
	 * Handle plugin activation.
	 */
	public function on_activation() {
		$is_federation_enabled = Settings::is_federation_enabled();
		$is_directory_enabled  = Settings::is_directory_enabled();

		// Flush rewrite rules if either federation or directory is enabled.
		if ( $is_federation_enabled || $is_directory_enabled ) {
			WellKnown::activate();
		}

		// Schedule cron if directory service is enabled.
		if ( $is_directory_enabled ) {
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
		return Settings::is_federation_enabled();
	}

	/**
	 * Check if directory service is available.
	 *
	 * @return bool True if directory service is enabled.
	 */
	public static function is_directory_enabled() {
		return Settings::is_directory_enabled();
	}
}
