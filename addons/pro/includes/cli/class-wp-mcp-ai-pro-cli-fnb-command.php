<?php
/**
 * WP-CLI Food & Beverage commands for NV oOS Pro.
 *
 * Read-only operator surface over the universal F&B toolkit: the toolkit
 * gate, the tenant profile, and the native-storage flag.
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
 * Inspect the Food & Beverage toolkit from the command line.
 *
 * ## EXAMPLES
 *
 *     # F&B toolkit status.
 *     $ wp mcp-ai fnb status
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_CLI_Fnb_Command extends WP_MCP_AI_Pro_CLI_Base_Command {

	/**
	 * Resolve a tenant-profile value defensively.
	 *
	 * @param string $method Static method name on WP_MCP_AI_Fnb_Tenant_Profile.
	 * @return string
	 */
	private function profile_value( $method ) {
		if ( ! class_exists( 'WP_MCP_AI_Fnb_Tenant_Profile' ) || ! is_callable( array( 'WP_MCP_AI_Fnb_Tenant_Profile', $method ) ) ) {
			return '';
		}
		return (string) call_user_func( array( 'WP_MCP_AI_Fnb_Tenant_Profile', $method ) );
	}

	/**
	 * Show the Food & Beverage toolkit status.
	 *
	 * ## OPTIONS
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
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # F&B status.
	 *     $ wp mcp-ai fnb status
	 *
	 *     # Export as JSON.
	 *     $ wp mcp-ai fnb status --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function status( $args, $assoc_args ) {
		unset( $args );
		$this->assert_pro_loaded();

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$fnb      = get_option( 'wp_mcp_ai_fnb_settings', array() );
		$fnb      = is_array( $fnb ) ? $fnb : array();

		$this->render_envelope(
			array(
				'Toolkit Enabled' => ! empty( $settings['enable_fnb_toolkit'] ),
				'Native Storage'  => ! empty( $fnb['enable_fnb_native_storage'] ),
				'Venue'           => $this->profile_value( 'venue_name' ),
				'Venue Type'      => $this->profile_value( 'venue_type' ),
				'Owner Role'      => $this->profile_value( 'owner_role' ),
				'Currency'        => $this->profile_value( 'currency_code' ),
				'Locale'          => $this->profile_value( 'locale' ),
			),
			$this->get_format( $assoc_args, 'table' )
		);
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai fnb', 'WP_MCP_AI_Pro_CLI_Fnb_Command' );
}
