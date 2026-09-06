<?php
/**
 * A2A well-known endpoint handler.
 *
 * Serves the /.well-known/agent.json endpoint for A2A agent discovery,
 * as defined by the A2A protocol specification.
 *
 * @package NvoosContentGraphAiPlatform
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 * @see       https://a2a-protocol.org/latest/specification/
 * @since     2.0.0 Ported from mcp-ai-wpoos/includes/a2a/class-wp-mcp-ai-a2a-wellknown.php.
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\A2A;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the /.well-known/agent.json endpoint for A2A discovery.
 */
class WellKnown {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle_wellknown_request' ), 5 );
		add_filter( 'redirect_canonical', array( $this, 'prevent_canonical_redirect' ), 10, 2 );
	}

	/**
	 * Add rewrite rules for the A2A well-known endpoint.
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule(
			'^\.well-known/agent\.json/?$',
			'index.php?wp_mcp_ai_a2a_wellknown=agent-card',
			'top'
		);
	}

	/**
	 * Add query vars for A2A well-known endpoints.
	 *
	 * @param array $vars Existing query vars.
	 * @return array Modified query vars.
	 */
	public function add_query_vars( $vars ) {
		$vars[] = 'wp_mcp_ai_a2a_wellknown';
		return $vars;
	}

	/**
	 * Prevent redirect_canonical from interfering with the
	 * /.well-known/agent.json URL (trailing-slash redirects, etc.).
	 *
	 * @param string|false $redirect_url  Canonical URL to redirect to, or false.
	 * @param string       $requested_url Original requested URL.
	 * @return string|false
	 */
	public function prevent_canonical_redirect( $redirect_url, $requested_url ) {
		if ( false !== strpos( $requested_url, '.well-known/agent.json' ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Handle well-known endpoint requests.
	 */
	public function handle_wellknown_request() {
		$wellknown = get_query_var( 'wp_mcp_ai_a2a_wellknown' );

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
		header( 'Cache-Control: public, max-age=3600' );

		switch ( $wellknown ) {
			case 'agent-card':
				$card = AgentCard::build_site_card();
				echo wp_json_encode( $card, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
				break;

			default:
				status_header( 404 );
				echo wp_json_encode(
					array(
						'error'   => 'not_found',
						'message' => 'Unknown A2A well-known endpoint.',
					)
				);
				break;
		}

		exit;
	}

	/**
	 * Flush rewrite rules on activation.
	 */
	public static function activate() {
		flush_rewrite_rules();
	}
}
