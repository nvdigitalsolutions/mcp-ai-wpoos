<?php
/**
 * /.well-known/oauth-protected-resource — RFC 9728 protected-resource metadata.
 *
 * Serves the MCP protected-resource metadata document that ChatGPT (and any
 * MCP client) reads before running the OAuth 2.1 authorization-code + PKCE
 * flow. The document is built by WP_MCP_AI_OAuth_Resource_Server; authorization
 * servers are only advertised when Auth0 is configured in Settings → NV oOS.
 *
 * Same rewrite + template_redirect pattern as the Pro addon's
 * /.well-known/mcp endpoint (class-wp-mcp-ai-pro-well-known-mcp.php),
 * including the redirect_canonical guard that stops WordPress from
 * redirecting the URL before this handler runs.
 *
 * @package WP_MCP_AI
 * @since 1.1.94
 * @see    https://www.rfc-editor.org/rfc/rfc9728
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves the RFC 9728 protected-resource metadata document.
 */
class WP_MCP_AI_Well_Known_OAuth_Protected_Resource {

	/**
	 * Query-var name used to route the request.
	 */
	const QUERY_VAR = 'wp_mcp_ai_oauth_protected_resource';

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Well_Known_OAuth_Protected_Resource|null
	 */
	private static $instance = null;

	/**
	 * Wire the hooks (idempotent singleton).
	 *
	 * @return WP_MCP_AI_Well_Known_OAuth_Protected_Resource
	 */
	public static function init() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor — wire WordPress hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle_request' ), 5 );
		add_filter( 'redirect_canonical', array( $this, 'prevent_canonical_redirect' ), 10, 2 );
	}

	/**
	 * Add the rewrite rule for the well-known endpoint.
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule(
			'^\.well-known/oauth-protected-resource/?$',
			'index.php?' . self::QUERY_VAR . '=1',
			'top'
		);
	}

	/**
	 * Register the query var.
	 *
	 * @param string[] $vars Existing query vars.
	 * @return string[]
	 */
	public function add_query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Prevent redirect_canonical from altering the URL before our handler runs.
	 *
	 * @param string|false $redirect_url  Canonical URL to redirect to, or false.
	 * @param string       $requested_url Original requested URL.
	 * @return string|false
	 */
	public function prevent_canonical_redirect( $redirect_url, $requested_url ) {
		if ( false !== strpos( $requested_url, '.well-known/oauth-protected-resource' ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Serve the RFC 9728 metadata document.
	 */
	public function handle_request() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );

		/**
		 * Filter the Cache-Control max-age for the metadata document.
		 *
		 * @param int $max_age Cache-Control max-age in seconds. Default 3600.
		 */
		$max_age = (int) apply_filters( 'wp_mcp_ai_oauth_protected_resource_cache_max_age', 3600 );
		if ( $max_age > 0 ) {
			header( 'Cache-Control: public, max-age=' . $max_age );
		} else {
			header( 'Cache-Control: no-store' );
		}

		echo wp_json_encode( WP_MCP_AI_OAuth_Resource_Server::build_protected_resource_document(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}
}
