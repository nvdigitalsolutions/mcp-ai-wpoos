<?php
/**
 * Toolkit MCP Server Registry
 *
 * Singleton registry of per-toolkit MCP servers. Servers register themselves
 * during the `wp_mcp_ai_register_toolkit_servers` action which is fired once at
 * `init` priority 12 (after toolkit init at priority 11).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/interface-wp-mcp-ai-toolkit-server.php';

/**
 * Registry singleton.
 */
class WP_MCP_AI_Toolkit_Server_Registry {

	/**
	 * Instance.
	 *
	 * @var WP_MCP_AI_Toolkit_Server_Registry|null
	 */
	private static $instance = null;

	/**
	 * Registered servers keyed by slug.
	 *
	 * @var array<string,WP_MCP_AI_Toolkit_Server_Interface>
	 */
	private $servers = array();

	/**
	 * Whether the registration action has been fired.
	 *
	 * @var bool
	 */
	private $bootstrapped = false;

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Toolkit_Server_Registry
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Reset (test fixture support).
	 */
	public static function reset_instance() {
		self::$instance = null;
	}

	/**
	 * Register a server. Late-binding-safe: callable from the
	 * `wp_mcp_ai_register_toolkit_servers` action or directly.
	 *
	 * @param WP_MCP_AI_Toolkit_Server_Interface $server Server instance.
	 * @return bool True on success, false if a server with that slug is already registered.
	 */
	public function register( $server ) {
		if ( ! ( $server instanceof WP_MCP_AI_Toolkit_Server_Interface ) ) {
			return false;
		}
		$slug = $server->get_slug();
		if ( '' === $slug || isset( $this->servers[ $slug ] ) ) {
			return false;
		}
		$this->servers[ $slug ] = $server;
		return true;
	}

	/**
	 * Fire the `wp_mcp_ai_register_toolkit_servers` action exactly once.
	 *
	 * Called by the bootstrap loader at `init` priority 12.
	 */
	public function bootstrap() {
		if ( $this->bootstrapped ) {
			return;
		}
		$this->bootstrapped = true;

		/**
		 * Fires once during init to allow toolkits to register their MCP servers.
		 *
		 * @since 1.2.0
		 *
		 * @param WP_MCP_AI_Toolkit_Server_Registry $registry Registry instance.
		 */
		do_action( 'wp_mcp_ai_register_toolkit_servers', $this );
	}

	/**
	 * Look up a server by slug.
	 *
	 * @param string $slug Toolkit slug.
	 * @return WP_MCP_AI_Toolkit_Server_Interface|null
	 */
	public function get( $slug ) {
		$slug = (string) $slug;
		return isset( $this->servers[ $slug ] ) ? $this->servers[ $slug ] : null;
	}

	/**
	 * All registered servers.
	 *
	 * @return array<string,WP_MCP_AI_Toolkit_Server_Interface>
	 */
	public function all() {
		return $this->servers;
	}

	/**
	 * Slugs of all registered servers.
	 *
	 * @return string[]
	 */
	public function slugs() {
		return array_keys( $this->servers );
	}
}
