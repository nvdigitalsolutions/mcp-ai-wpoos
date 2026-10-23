<?php
/**
 * REST controller for the console Media Ops feature (Proposal 084
 * Phase 4, W14 — the media command-center port).
 *
 * Serves the media library's health snapshot and the compression-sweep
 * trigger:
 *   GET  /mcp-ai-pro/v1/media-cc/health             — snapshot metrics
 *   POST /mcp-ai-pro/v1/media-cc/compression-sweep  — queue a sweep
 *
 * Data providers are the wp-admin Media Command Center page's own
 * gatherers (promoted public seams) — the controller never re-implements
 * a query. Read-only library management (optimization, alt-text repair,
 * cleanup) stays wp-admin.
 *
 * @package WP_MCP_AI_Pro
 * @since   2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media command-center REST controller.
 */
class WP_MCP_AI_Pro_Media_CC_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'mcp-ai-pro/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	const ROUTE_BASE = '/media-cc';

	/**
	 * Boot the controller (idempotent).
	 *
	 * @since 2.4.0
	 * @return void
	 */
	public static function init() {
		static $initialized = false;
		if ( $initialized ) {
			return;
		}
		$initialized = true;

		$controller = new self();
		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
	}

	/**
	 * Register the health + sweep routes.
	 *
	 * @since 2.4.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_health' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/compression-sweep',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run_compression_sweep' ),
				'permission_callback' => array( $this, 'permissions_check' ),
			)
		);
	}

	/**
	 * Permission check — media ops is an administrator-only console surface.
	 *
	 * @since 2.4.0
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Administrator access required.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * Load the wp-admin page class if it is not already available.
	 *
	 * @since 2.4.0
	 * @return bool True when the provider class is loaded.
	 */
	private function load_provider() {
		if ( class_exists( 'WP_MCP_AI_Media_Command_Center_Page' ) ) {
			return true;
		}

		$page_file = WP_MCP_AI_PRO_PATH . 'includes/admin/class-wp-mcp-ai-media-command-center-page.php';
		if ( file_exists( $page_file ) ) {
			require_once $page_file;
		}

		return class_exists( 'WP_MCP_AI_Media_Command_Center_Page' );
	}

	/**
	 * GET /media-cc/health — the media library health snapshot.
	 *
	 * @since 2.4.0
	 * @return array|WP_Error
	 */
	public function get_health() {
		if ( ! $this->load_provider() ) {
			return new WP_Error(
				'media_cc_provider_unavailable',
				__( 'The media command-center provider is not available on this site.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 501 )
			);
		}

		return rest_ensure_response( WP_MCP_AI_Media_Command_Center_Page::get_health_snapshot() );
	}

	/**
	 * POST /media-cc/compression-sweep — queue a library-wide compression
	 * sweep (deduped in Action Scheduler).
	 *
	 * @since 2.4.0
	 * @return array|WP_Error
	 */
	public function run_compression_sweep() {
		if ( ! $this->load_provider() ) {
			return new WP_Error(
				'media_cc_provider_unavailable',
				__( 'The media command-center provider is not available on this site.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 501 )
			);
		}

		$result = WP_MCP_AI_Media_Command_Center_Page::dispatch_compression_sweep();
		if ( is_wp_error( $result ) ) {
			$result->add_data( array( 'status' => 503 ) );
			return $result;
		}

		return rest_ensure_response( $result );
	}
}
