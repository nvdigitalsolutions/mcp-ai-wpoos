<?php
/**
 * /.well-known/mcp — MCP Discovery Endpoint
 *
 * Serves a JSON discovery document at `/.well-known/mcp` listing every
 * enabled per-toolkit MCP server registered with NV oOS.  MCP clients can
 * retrieve this document to auto-discover server endpoints without requiring
 * prior knowledge of the site's WordPress REST API base URL.
 *
 * Discovery document shape (loosely following the draft MCP discovery spec):
 *
 * {
 *   "mcpServers": [
 *     {
 *       "slug":        "crm",
 *       "name":        "CRM",
 *       "description": "...",
 *       "version":     "1.0.0",
 *       "endpoint":    "https://example.com/wp-json/mcp-ai-pro/v1/mcp/crm"
 *     },
 *     ...
 *   ]
 * }
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the /.well-known/mcp endpoint for toolkit-MCP discovery.
 */
class WP_MCP_AI_Pro_Well_Known_MCP {

	/**
	 * Query-var name used to route the request.
	 */
	const QUERY_VAR = 'wp_mcp_ai_well_known_mcp';

	/**
	 * Constructor — wire WordPress hooks.
	 */
	public function __construct() {
		add_action( 'init', array( $this, 'add_rewrite_rules' ) );
		add_filter( 'query_vars', array( $this, 'add_query_vars' ) );
		add_action( 'template_redirect', array( $this, 'handle_request' ) );
	}

	/**
	 * Add rewrite rule for the well-known MCP endpoint.
	 */
	public function add_rewrite_rules() {
		add_rewrite_rule(
			'^\.well-known/mcp/?$',
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
	 * Serve the /.well-known/mcp discovery document.
	 *
	 * Fires on template_redirect before any template output.
	 */
	public function handle_request() {
		if ( ! get_query_var( self::QUERY_VAR ) ) {
			return;
		}

		// Flush any buffered output.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Robots-Tag: noindex' );
		/**
		 * Filter the Cache-Control max-age for the well-known MCP document.
		 *
		 * @since 1.2.0
		 *
		 * @param int $max_age Cache-Control max-age in seconds. Default 3600.
		 */
		$max_age = (int) apply_filters( 'wp_mcp_ai_well_known_mcp_cache_max_age', 3600 );
		if ( $max_age > 0 ) {
			header( 'Cache-Control: public, max-age=' . $max_age );
		} else {
			header( 'Cache-Control: no-store' );
		}

		echo wp_json_encode( $this->build_discovery_document(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		exit;
	}

	/**
	 * Build the MCP discovery document.
	 *
	 * Lists every enabled toolkit server whose `is_enabled()` returns true.
	 * The `endpoint` field points at the per-server JSON-RPC route under the
	 * REST namespace `mcp-ai-pro/v1`.
	 *
	 * @return array<string,mixed>
	 */
	public function build_discovery_document() {
		$servers = array();

		if ( class_exists( 'WP_MCP_AI_Toolkit_Server_Registry' ) ) {
			$registry    = WP_MCP_AI_Toolkit_Server_Registry::get_instance();
			$rest_base   = rest_url( 'mcp-ai-pro/v1/mcp' );
			$all_servers = $registry->all();

			foreach ( $all_servers as $server ) {
				if ( ! $server->is_enabled() ) {
					continue;
				}

				$servers[] = array(
					'slug'        => $server->get_slug(),
					'name'        => $server->get_name(),
					'description' => $server->get_description(),
					'version'     => $server->get_version(),
					'endpoint'    => trailingslashit( $rest_base ) . $server->get_slug(),
				);
			}
		}

		/**
		 * Filter the complete MCP discovery document before it is sent.
		 *
		 * @since 1.2.0
		 *
		 * @param array<string,mixed> $document Discovery document.
		 */
		return apply_filters(
			'wp_mcp_ai_well_known_mcp_document',
			array( 'mcpServers' => $servers )
		);
	}

	/**
	 * Flush rewrite rules on plugin activation.
	 */
	public static function activate() {
		flush_rewrite_rules();
	}

	/**
	 * Flush rewrite rules on plugin deactivation.
	 */
	public static function deactivate() {
		flush_rewrite_rules();
	}
}
