<?php
/**
 * Federation well-known endpoints for AI peer discovery.
 *
 * Implements /.well-known/ai-peer and /.well-known/jwks.json endpoints
 * for publishing this site's MCP capabilities to the federation network.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles well-known endpoints for federation discovery.
 */
class WP_MCP_AI_Federation_WellKnown {

	/**
	 * Tool registry instance.
	 *
	 * @var WP_MCP_AI_Tool_Registry
	 */
	protected $registry;

	/**
	 * Constructor.
	 *
	 * @param WP_MCP_AI_Tool_Registry $registry Tool registry instance.
	 */
	public function __construct( WP_MCP_AI_Tool_Registry $registry ) {
		$this->registry = $registry;

		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle_wellknown_requests' ) );
	}

	/**
	 * Add rewrite rules for well-known endpoints.
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule(
			'^\.well-known/ai-peer/?$',
			'index.php?wp_mcp_ai_wellknown=ai-peer',
			'top'
		);

		add_rewrite_rule(
			'^\.well-known/jwks\.json/?$',
			'index.php?wp_mcp_ai_wellknown=jwks',
			'top'
		);
	}

	/**
	 * Add query vars for well-known endpoints.
	 *
	 * @param array $vars Existing query vars.
	 * @return array Modified query vars.
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'wp_mcp_ai_wellknown';
		return $vars;
	}

	/**
	 * Handle well-known endpoint requests.
	 */
	public function handle_wellknown_requests() {
		$wellknown = get_query_var( 'wp_mcp_ai_wellknown' );

		if ( ! $wellknown ) {
			return;
		}

		// Clean output buffer to prevent any WordPress output.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );

		switch ( $wellknown ) {
			case 'ai-peer':
				echo wp_json_encode( $this->get_ai_peer_document(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				break;

			case 'jwks':
				echo wp_json_encode( $this->get_jwks_document(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				break;

			default:
				status_header( 404 );
				echo wp_json_encode(
					array(
						'error'   => 'not_found',
						'message' => 'Unknown well-known endpoint.',
					)
				);
				break;
		}

		exit;
	}

	/**
	 * Generate the ai-peer well-known document.
	 *
	 * @return array AI peer document.
	 */
	protected function get_ai_peer_document() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$site_url = trailingslashit( get_site_url() );

		// Get available capabilities from tool registry.
		$capabilities = $this->get_available_capabilities();

		// Get regions and data tags from settings.
		$regions = isset( $settings['federation_regions'] ) && is_array( $settings['federation_regions'] )
			? $settings['federation_regions']
			: array( 'global' );

		$data_tags = isset( $settings['federation_data_tags'] ) && is_array( $settings['federation_data_tags'] )
			? $settings['federation_data_tags']
			: array();

		// Get quotas from settings.
		$quotas = array(
			'qps'   => isset( $settings['federation_qps'] ) ? absint( $settings['federation_qps'] ) : 5,
			'burst' => isset( $settings['federation_burst'] ) ? absint( $settings['federation_burst'] ) : 10,
		);

		// Get price hints if configured.
		$price_hints = isset( $settings['federation_price_hints'] ) && is_array( $settings['federation_price_hints'] )
			? $settings['federation_price_hints']
			: array();

		return array(
			'version'      => '1.0',
			'site_name'    => get_bloginfo( 'name' ),
			'site_url'     => $site_url,
			'mcp'          => array(
				'url' => rest_url( 'mcp-ai/v1' ),
			),
			'openapi'      => array(
				'url' => rest_url( 'mcp-ai/v1/openapi.json' ),
			),
			'jwks_uri'     => $site_url . '.well-known/jwks.json',
			'capabilities' => $capabilities,
			'regions'      => $regions,
			'data_tags'    => $data_tags,
			'quotas'       => $quotas,
			'price_hints'  => $price_hints,
			'updated_at'   => current_time( 'mysql', true ),
		);
	}

	/**
	 * Get available capabilities from the tool registry.
	 *
	 * @return array Array of capability slugs.
	 */
	protected function get_available_capabilities() {
		$tools        = $this->registry->get_tools();
		$capabilities = array();

		foreach ( $tools as $tool ) {
			if ( ! is_object( $tool ) || ! method_exists( $tool, 'get_slug' ) ) {
				continue;
			}

			$slug = $tool->get_slug();
			if ( ! empty( $slug ) ) {
				$capabilities[] = $slug;
			}
		}

		return $capabilities;
	}

	/**
	 * Generate the JWKS (JSON Web Key Set) document.
	 *
	 * Returns the public keys used to verify JWT signatures issued by this site.
	 *
	 * @return array JWKS document.
	 */
	protected function get_jwks_document() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		// Check if public keys are configured.
		$public_keys = isset( $settings['federation_jwks_keys'] ) && is_array( $settings['federation_jwks_keys'] )
			? $settings['federation_jwks_keys']
			: array();

		// If no keys configured, generate a placeholder indicating keys should be configured.
		if ( empty( $public_keys ) ) {
			return array(
				'keys'    => array(),
				'message' => 'No public keys configured. Configure federation keys in WP oOS settings.',
			);
		}

		return array(
			'keys' => $public_keys,
		);
	}

	/**
	 * Flush rewrite rules on activation.
	 */
	public static function activate() {
		flush_rewrite_rules();
	}

	/**
	 * Flush rewrite rules on deactivation.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
