<?php
/**
 * MCP App Registry.
 *
 * Manages MCP App configurations per assistant and coordinates
 * tool discovery and registration from remote MCP servers.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.8.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registry for managing MCP App connections per assistant.
 *
 * Stores MCP App configurations as post meta on the assistant CPT,
 * handles connection testing, tool discovery, and bridging remote
 * tools into the local tool registry.
 *
 * @since 1.8.0
 */
class WP_MCP_AI_MCP_App_Registry {

	/**
	 * Post meta key for MCP Apps configuration.
	 *
	 * @var string
	 */
	const META_KEY = '_wp_mcp_ai_mcp_apps';

	/**
	 * Transient prefix for cached tool discovery results.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'wp_mcp_ai_mcp_app_tools_';

	/**
	 * Cache duration in seconds (5 minutes).
	 *
	 * @var int
	 */
	const CACHE_TTL = 300;

	/**
	 * Maximum number of MCP Apps per assistant.
	 *
	 * @var int
	 */
	const MAX_APPS_PER_ASSISTANT = 10;

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $instance = null;

	/**
	 * Get the singleton instance.
	 *
	 * @since 1.8.0
	 * @return self
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Get MCP Apps configured for an assistant.
	 *
	 * @since 1.8.0
	 * @param int $assistant_id Assistant post ID.
	 * @return array Array of MCP App configurations.
	 */
	public function get_apps( $assistant_id ) {
		$assistant_id = absint( $assistant_id );

		if ( ! $assistant_id ) {
			return array();
		}

		$apps = get_post_meta( $assistant_id, self::META_KEY, true );

		if ( ! is_array( $apps ) ) {
			return array();
		}

		return $apps;
	}

	/**
	 * Save MCP Apps configuration for an assistant.
	 *
	 * @since 1.8.0
	 * @param int   $assistant_id Assistant post ID.
	 * @param array $apps         Array of MCP App configurations.
	 * @return bool True on success.
	 */
	public function save_apps( $assistant_id, array $apps ) {
		$assistant_id = absint( $assistant_id );

		if ( ! $assistant_id ) {
			return false;
		}

		// Enforce maximum apps limit.
		$apps = array_slice( $apps, 0, self::MAX_APPS_PER_ASSISTANT );

		// Sanitize each app configuration.
		$sanitized_apps = array();
		foreach ( $apps as $app ) {
			$sanitized = self::sanitize_app_config( $app );
			if ( ! empty( $sanitized['server_url'] ) ) {
				$sanitized_apps[] = $sanitized;
			}
		}

		if ( empty( $sanitized_apps ) ) {
			delete_post_meta( $assistant_id, self::META_KEY );
		} else {
			update_post_meta( $assistant_id, self::META_KEY, $sanitized_apps );
		}

		// Clear cached tools for this assistant.
		$this->clear_tool_cache( $assistant_id );

		return true;
	}

	/**
	 * Sanitize a single MCP App configuration.
	 *
	 * @since 1.8.0
	 * @param array $app Raw app configuration.
	 * @return array Sanitized app configuration.
	 */
	public static function sanitize_app_config( $app ) {
		if ( ! is_array( $app ) ) {
			return array();
		}

		return array(
			'label'       => isset( $app['label'] ) ? sanitize_text_field( $app['label'] ) : '',
			'server_url'  => isset( $app['server_url'] ) ? esc_url_raw( $app['server_url'] ) : '',
			'auth_type'   => isset( $app['auth_type'] ) && in_array( $app['auth_type'], array( 'none', 'bearer', 'header' ), true )
				? $app['auth_type']
				: 'none',
			'token'       => isset( $app['token'] ) ? sanitize_text_field( $app['token'] ) : '',
			'header_name' => isset( $app['header_name'] ) ? sanitize_text_field( $app['header_name'] ) : '',
			'enabled'     => isset( $app['enabled'] ) ? (bool) $app['enabled'] : true,
			'timeout'     => isset( $app['timeout'] ) ? max( 1, min( 120, absint( $app['timeout'] ) ) ) : 30,
			'verify_ssl'  => isset( $app['verify_ssl'] ) ? (bool) $app['verify_ssl'] : true,
		);
	}

	/**
	 * Create an MCP App Client from a configuration.
	 *
	 * @since 1.8.0
	 * @param array $app_config MCP App configuration.
	 * @return WP_MCP_AI_MCP_App_Client
	 */
	public function create_client( array $app_config ) {
		return new WP_MCP_AI_MCP_App_Client( $app_config );
	}

	/**
	 * Discover and register tools from MCP Apps for an assistant.
	 *
	 * Connects to each enabled MCP App, discovers available tools,
	 * and registers bridge tools in the local registry.
	 *
	 * @since 1.8.0
	 * @param int                      $assistant_id Assistant post ID.
	 * @param WP_MCP_AI_Tool_Registry $registry     Tool registry instance.
	 * @return array Array of registered bridge tool slugs.
	 */
	public function register_remote_tools( $assistant_id, $registry ) {
		$apps = $this->get_apps( $assistant_id );

		if ( empty( $apps ) ) {
			return array();
		}

		$registered_slugs = array();

		foreach ( $apps as $app_config ) {
			if ( empty( $app_config['enabled'] ) ) {
				continue;
			}

			if ( empty( $app_config['server_url'] ) ) {
				continue;
			}

			$tools = $this->discover_tools( $app_config );

			if ( is_wp_error( $tools ) || empty( $tools ) ) {
				continue;
			}

			$label = ! empty( $app_config['label'] ) ? $app_config['label'] : wp_parse_url( $app_config['server_url'], PHP_URL_HOST );

			foreach ( $tools as $remote_tool ) {
				$bridge = new WP_MCP_AI_MCP_App_Tool_Bridge( $remote_tool, $app_config, $label );
				$slug   = $bridge->get_slug();

				// Avoid duplicate registration.
				if ( $registry->get_tool( $slug ) ) {
					continue;
				}

				$registry->register_tool( $bridge );
				$registered_slugs[] = $slug;
			}
		}

		return $registered_slugs;
	}

	/**
	 * Discover tools from a single MCP App server.
	 *
	 * Uses transient caching to avoid repeated requests.
	 *
	 * @since 1.8.0
	 * @param array $app_config MCP App configuration.
	 * @return array|WP_Error Array of tool definitions or WP_Error.
	 */
	public function discover_tools( array $app_config ) {
		$cache_key = self::CACHE_PREFIX . md5( wp_json_encode( $app_config ) );

		$cached = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		$client = $this->create_client( $app_config );

		$init_result = $client->initialize();
		if ( is_wp_error( $init_result ) ) {
			return $init_result;
		}

		$tools = $client->list_tools();
		if ( is_wp_error( $tools ) ) {
			return $tools;
		}

		// Cache the results.
		set_transient( $cache_key, $tools, self::CACHE_TTL );

		return $tools;
	}

	/**
	 * Clear the tool cache for an assistant.
	 *
	 * @since 1.8.0
	 * @param int $assistant_id Assistant post ID.
	 * @return void
	 */
	public function clear_tool_cache( $assistant_id ) {
		$apps = get_post_meta( absint( $assistant_id ), self::META_KEY, true );

		if ( ! is_array( $apps ) ) {
			return;
		}

		foreach ( $apps as $app_config ) {
			$cache_key = self::CACHE_PREFIX . md5( wp_json_encode( $app_config ) );
			delete_transient( $cache_key );
		}
	}

	/**
	 * Test connection to a specific MCP App.
	 *
	 * @since 1.8.0
	 * @param array $app_config MCP App configuration.
	 * @return array|WP_Error Connection test result.
	 */
	public function test_connection( array $app_config ) {
		$client = $this->create_client( $app_config );
		return $client->test_connection();
	}
}
