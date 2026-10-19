<?php
/**
 * WP-CLI MCP Apps commands for NV oOS Pro.
 *
 * Read-only operator surface over the per-assistant MCP Apps store
 * (`WP_MCP_AI_MCP_App_Registry`). Renders metadata only — token and OAuth
 * material is decrypted by the registry but never printed.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CLI
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/class-wp-mcp-ai-pro-cli-base-command.php';

/**
 * Inspect MCP Apps configured on an assistant.
 *
 * ## EXAMPLES
 *
 *     # List the MCP Apps configured on assistant 123.
 *     $ wp mcp-ai mcp-app list --assistant-id=123
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_CLI_Mcp_App_Command extends WP_MCP_AI_Pro_CLI_Base_Command {

	/**
	 * List the MCP Apps configured on an assistant (metadata only).
	 *
	 * ## OPTIONS
	 *
	 * --assistant-id=<id>
	 * : Assistant post ID whose MCP Apps to list. Required.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 *   - count
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated column list (Label, Server URL, Auth, Enabled, Connection Ref, Timeout).
	 *
	 * ## EXAMPLES
	 *
	 *     # List apps for assistant 123.
	 *     $ wp mcp-ai mcp-app list --assistant-id=123
	 *
	 *     # Export as JSON.
	 *     $ wp mcp-ai mcp-app list --assistant-id=123 --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function list( $args, $assoc_args ) {
		unset( $args );
		$this->assert_pro_loaded();

		$assistant_id = isset( $assoc_args['assistant-id'] ) ? absint( $assoc_args['assistant-id'] ) : 0;
		if ( $assistant_id <= 0 ) {
			WP_CLI::error( __( 'Please provide --assistant-id=<id>.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( 'mcp_ai_assistant' !== get_post_type( $assistant_id ) ) {
			WP_CLI::error( __( 'Assistant not found.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! class_exists( 'WP_MCP_AI_MCP_App_Registry' ) ) {
			WP_CLI::error( __( 'The MCP Apps module is not loaded.', 'mcp-ai-wpoos-pro' ) );
		}

		$format = $this->get_format( $assoc_args, 'table' );
		$fields = $this->get_fields( $assoc_args, array( 'Label', 'Server URL', 'Auth', 'Enabled', 'Connection Ref', 'Timeout' ) );

		$apps = WP_MCP_AI_MCP_App_Registry::get_instance()->get_apps( $assistant_id );

		$items = array();
		foreach ( $apps as $app ) {
			if ( ! is_array( $app ) || empty( $app['server_url'] ) ) {
				continue;
			}

			// Metadata allowlist only — token / oauth_data are never rendered.
			$items[] = array(
				'Label'          => isset( $app['label'] ) ? (string) $app['label'] : '',
				'Server URL'     => (string) $app['server_url'],
				'Auth'           => isset( $app['auth_type'] ) ? (string) $app['auth_type'] : 'none',
				'Enabled'        => isset( $app['enabled'] ) && false === $app['enabled'] ? 'no' : 'yes',
				'Connection Ref' => isset( $app['connection_ref'] ) ? (string) $app['connection_ref'] : '',
				'Timeout'        => isset( $app['timeout'] ) ? (string) absint( $app['timeout'] ) : '30',
			);
		}

		if ( empty( $items ) ) {
			WP_CLI::log( __( 'No MCP Apps configured for this assistant.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai mcp-app', 'WP_MCP_AI_Pro_CLI_Mcp_App_Command' );
}
