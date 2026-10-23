<?php
/**
 * WP-CLI Pro status command for NV oOS.
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
 * Show NV oOS Pro status summary.
 *
 * ## EXAMPLES
 *
 *     # Print the pro addon status dashboard.
 *     $ wp mcp-ai pro status
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_CLI_Status_Command extends WP_MCP_AI_Pro_CLI_Base_Command {

	/**
	 * Resolve the canonical toolkit map (shared with the `toolkit` command
	 * via `WP_MCP_AI_Pro_Toolkit_Settings_Map`).
	 *
	 * @return array<string,string>
	 */
	private static function toolkit_map() {
		if ( class_exists( 'WP_MCP_AI_Pro_Toolkit_Settings_Map' ) ) {
			return WP_MCP_AI_Pro_Toolkit_Settings_Map::get();
		}
		return array();
	}

	/**
	 * Display a summary of the NV oOS Pro addon.
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
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Show pro status as a table.
	 *     $ wp mcp-ai pro status
	 *
	 *     # Export status as JSON.
	 *     $ wp mcp-ai pro status --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function __invoke( $args, $assoc_args ) {
		$this->assert_pro_loaded();

		$format   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		// Prefer the plugin-header version over the manually-maintained constant.
		$pro_version = method_exists( 'WP_MCP_AI_Plugin_Updater', 'get_pro_installed_version' )
			? WP_MCP_AI_Plugin_Updater::get_pro_installed_version()
			: ( defined( 'WP_MCP_AI_PRO_VERSION' ) ? WP_MCP_AI_PRO_VERSION : 'unknown' );

		$info = array(
			array(
				'key'   => 'Pro Version',
				'value' => $pro_version ? $pro_version : 'unknown',
			),
			array(
				'key'   => 'Core Version',
				'value' => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : 'unknown',
			),
			array(
				'key'   => 'Base Version Mode',
				'value' => ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) ? 'yes' : 'no',
			),
			array(
				'key'   => 'Site URL',
				'value' => get_option( 'siteurl' ),
			),
		);

		$toolkit_labels = implode( ', ', $this->get_active_toolkit_labels( $settings ) );
		$info[]         = array(
			'key'   => 'Active Toolkits',
			'value' => ! empty( $toolkit_labels ) ? $toolkit_labels : 'none',
		);

		// Append connection count.
		if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
			$info[]      = array(
				'key'   => 'Remote Connections',
				'value' => (string) count( $connections ),
			);
		}

		if ( 'json' === $format ) {
			$data = array();
			foreach ( $info as $row ) {
				$data[ $row['key'] ] = $row['value'];
			}
			WP_CLI::line( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
			return;
		}

		if ( 'yaml' === $format ) {
			foreach ( $info as $row ) {
				WP_CLI::line( "{$row['key']}: {$row['value']}" );
			}
			return;
		}

		\WP_CLI\Utils\format_items( 'table', $info, array( 'key', 'value' ) );
	}

	/**
	 * Get an array of human-readable labels for every active toolkit.
	 *
	 * @param array $settings Plugin settings.
	 * @return string[]
	 */
	private function get_active_toolkit_labels( $settings ) {
		$active = array();
		foreach ( self::toolkit_map() as $key => $label ) {
			if ( ! empty( $settings[ $key ] ) ) {
				$active[] = $label;
			}
		}
		return $active;
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	require_once __DIR__ . '/class-wp-mcp-ai-pro-toolkit-settings-map.php';
	WP_CLI::add_command( 'mcp-ai pro status', 'WP_MCP_AI_Pro_CLI_Status_Command' );
}
