<?php
/**
 * /.well-known/openai-apps-challenge — OpenAI plugin domain verification.
 *
 * Serves the exact plain-text challenge token that OpenAI's plugin
 * submission portal asks the site owner to host during domain
 * verification. The portal shows the token; the operator stores it in the
 * `wp_mcp_ai_openai_apps_challenge_token` option (via WP-CLI or the
 * wp_mcp_ai_openai_apps_challenge_token filter).
 *
 * Only the exact token is returned — not JSON, not a list. Returns 404
 * while no token is configured, so the endpoint is inert by default.
 *
 * @package WP_MCP_AI
 * @since 1.1.94
 * @see    https://developers.openai.com/plugins/deploy/submission#domain-verification-details
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serves the OpenAI plugin domain-verification challenge token.
 */
class WP_MCP_AI_Well_Known_OpenAI_Challenge {

	/**
	 * Query-var name used to route the request.
	 */
	const QUERY_VAR = 'wp_mcp_ai_openai_apps_challenge';

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Well_Known_OpenAI_Challenge|null
	 */
	private static $instance = null;

	/**
	 * Wire the hooks (idempotent singleton).
	 *
	 * @return WP_MCP_AI_Well_Known_OpenAI_Challenge
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
	 * Add the rewrite rule for the challenge endpoint.
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule(
			'^\.well-known/openai-apps-challenge/?$',
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
		if ( false !== strpos( $requested_url, '.well-known/openai-apps-challenge' ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * The configured challenge token, if any.
	 *
	 * @return string
	 */
	public static function get_challenge_token() {
		/**
		 * Filter the OpenAI plugin domain-verification challenge token.
		 *
		 * @param string $token Challenge token from the submission portal.
		 */
		return (string) apply_filters(
			'wp_mcp_ai_openai_apps_challenge_token',
			(string) get_option( WP_MCP_AI_OAuth_Resource_Server::OPTION_CHALLENGE_TOKEN, '' )
		);
	}

	/**
	 * Serve the challenge token as plain text, or 404 when unconfigured.
	 */
	public function handle_request() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		$token = trim( self::get_challenge_token() );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Cache-Control: no-store' );

		if ( '' === $token ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Not found';
			exit;
		}

		header( 'Content-Type: text/plain; charset=utf-8' );
		echo $token; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Challenge tokens are single opaque strings from the submission portal; JSON/HTML escaping would corrupt the value OpenAI verifies.
		exit;
	}
}
