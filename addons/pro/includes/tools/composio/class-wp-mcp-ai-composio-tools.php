<?php
/**
 * Composio tools — shared static helpers.
 *
 * Connection resolution, client construction and toolkit-allowlist checks
 * shared by the six composio_* MCP tools.
 *
 * PHP 8.1+ only (Pro addon).
 *
 * @package WP_MCP_AI_Pro
 * @since   1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Composio tools helper.
 */
class WP_MCP_AI_Composio_Tools {

	/**
	 * Ensure the Remote Site Manager class is loaded.
	 *
	 * @since 1.4.0
	 *
	 * @return void
	 */
	public static function maybe_load_manager() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) && defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$manager_file = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
			if ( file_exists( $manager_file ) ) {
				require_once $manager_file;
			}
		}
	}

	/**
	 * Resolve the target Composio connection from tool arguments.
	 *
	 * Falls back to the first enabled composio connection when no explicit
	 * connection_id argument is supplied.
	 *
	 * @since 1.4.0
	 *
	 * @param array      $arguments  Tool arguments.
	 * @param array|null $connection Output. Resolved connection record.
	 * @return WP_Error|array Connection record or error.
	 */
	public static function resolve_connection( array $arguments, &$connection = null ) {
		self::maybe_load_manager();

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return new WP_Error( 'wp_mcp_ai_composio_missing_manager', __( 'The Remote Site Manager is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		$connection = null;

		if ( ! empty( $arguments['connection_id'] ) ) {
			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( sanitize_key( $arguments['connection_id'] ) );
			if ( null === $connection || 'composio' !== ( isset( $connection['connection_type'] ) ? $connection['connection_type'] : '' ) ) {
				return new WP_Error( 'wp_mcp_ai_composio_invalid_connection', __( 'Composio connection not found.', 'mcp-ai-wpoos-pro' ) );
			}
		} else {
			$all = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
			foreach ( $all as $candidate ) {
				if ( 'composio' === ( isset( $candidate['connection_type'] ) ? $candidate['connection_type'] : '' ) && ! empty( $candidate['enabled'] ) ) {
					$connection = $candidate;
					break;
				}
			}

			if ( null === $connection ) {
				return new WP_Error( 'wp_mcp_ai_composio_no_connection', __( 'No enabled Composio connection found. Create one in the Remote Site Manager.', 'mcp-ai-wpoos-pro' ) );
			}
		}

		return $connection;
	}

	/**
	 * Build a client instance for a resolved connection.
	 *
	 * @since 1.4.0
	 *
	 * @param array $connection Composio connection record.
	 * @return WP_MCP_AI_Composio_Client
	 */
	public static function build_client( array $connection ) {
		return WP_MCP_AI_Composio_Client::from_connection( $connection );
	}

	/**
	 * Check whether a toolkit is allowed for a connection.
	 *
	 * An empty allowlist permits every toolkit.
	 *
	 * @since 1.4.0
	 *
	 * @param array  $connection Composio connection record.
	 * @param string $toolkit    Toolkit slug.
	 * @return bool
	 */
	public static function is_toolkit_allowed( array $connection, $toolkit ) {
		$allowlist = isset( $connection['toolkit_allowlist'] ) && is_array( $connection['toolkit_allowlist'] ) ? $connection['toolkit_allowlist'] : array();

		if ( empty( $allowlist ) ) {
			return true;
		}

		return in_array( sanitize_key( (string) $toolkit ), array_map( 'sanitize_key', $allowlist ), true );
	}
}
