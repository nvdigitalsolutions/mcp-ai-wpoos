<?php
/**
 * WP-CLI toolkit management commands for NV oOS Pro.
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
 * List, enable, and disable NV oOS Pro toolkits from the command line.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_CLI_Toolkit_Command extends WP_MCP_AI_Pro_CLI_Base_Command {

	/**
	 * Settings key → human-readable label for every known Pro toolkit.
	 *
	 * Source of truth is the shared
	 * `WP_MCP_AI_Pro_Toolkit_Settings_Map` (three hardcoded copies drifted
	 * out of sync with the real settings keys before — see the F&B toggle
	 * bug, PR #7010).
	 *
	 * @var array<string,string>
	 */
	private static $toolkit_keys = array();

	/**
	 * Resolve the canonical toolkit map (lazy, so this file stays loadable
	 * without the map class).
	 *
	 * @return array<string,string>
	 */
	private static function toolkit_map() {
		if ( empty( self::$toolkit_keys ) && class_exists( 'WP_MCP_AI_Pro_Toolkit_Settings_Map' ) ) {
			self::$toolkit_keys = WP_MCP_AI_Pro_Toolkit_Settings_Map::get();
		}
		return self::$toolkit_keys;
	}

	/**
	 * List all toolkits and their current enabled/disabled state.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : Filter by toolkit status.
	 * ---
	 * options:
	 *   - enabled
	 *   - disabled
	 * ---
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
	 *   - ids
	 *   - count
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List all toolkits.
	 *     $ wp mcp-ai toolkit list
	 *
	 *     # Show only enabled toolkits.
	 *     $ wp mcp-ai toolkit list --status=enabled
	 *
	 *     # Export as JSON.
	 *     $ wp mcp-ai toolkit list --format=json
	 *
	 * @subcommand list
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function list( $args, $assoc_args ) {
		$this->assert_pro_loaded();

		$status_filter = \WP_CLI\Utils\get_flag_value( $assoc_args, 'status', '' );
		$format        = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$items    = array();

		foreach ( self::toolkit_map() as $key => $label ) {
			$is_enabled = ! empty( $settings[ $key ] );
			$status     = $is_enabled ? 'enabled' : 'disabled';

			if ( $status_filter && $status_filter !== $status ) {
				continue;
			}

			$items[] = array(
				'key'    => $key,
				'label'  => $label,
				'status' => $status,
			);
		}

		if ( empty( $items ) ) {
			WP_CLI::log( __( 'No toolkits match the given filter.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $items, array( 'key', 'label', 'status' ) );
	}

	/**
	 * Enable a Pro toolkit.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : The toolkit settings key (e.g. enable_crm_toolkit).
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai toolkit enable enable_crm_toolkit --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function enable( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->require_capability( 'manage_options' );

		$key = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';

		if ( ! $key ) {
			WP_CLI::error( __( 'Please provide a toolkit settings key.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! array_key_exists( $key, self::toolkit_map() ) ) {
			/* translators: %s: toolkit key */
			WP_CLI::error( sprintf( __( 'Unknown toolkit key "%s". Run `wp mcp-ai toolkit list` to see valid keys.', 'mcp-ai-wpoos-pro' ), $key ) );
		}

		if ( ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false ) ) {
			/* translators: 1: toolkit label, 2: toolkit key */
			WP_CLI::confirm( sprintf( __( 'Enable toolkit "%1$s" (%2$s)?', 'mcp-ai-wpoos-pro' ), self::toolkit_map()[ $key ], $key ) );
		}

		$settings         = get_option( 'wp_mcp_ai_settings', array() );
		$settings[ $key ] = '1';
		update_option( 'wp_mcp_ai_settings', $settings );

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		/* translators: 1: toolkit key, 2: toolkit label */
		WP_CLI::success( sprintf( __( 'Toolkit "%2$s" (%1$s) enabled.', 'mcp-ai-wpoos-pro' ), $key, self::toolkit_map()[ $key ] ) );
	}

	/**
	 * Disable a Pro toolkit.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : The toolkit settings key (e.g. enable_crm_toolkit).
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai toolkit disable enable_crm_toolkit --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function disable( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->require_capability( 'manage_options' );

		$key = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';
		$yes = \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false );

		if ( ! $key ) {
			WP_CLI::error( __( 'Please provide a toolkit settings key.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! array_key_exists( $key, self::toolkit_map() ) ) {
			/* translators: %s: toolkit key */
			WP_CLI::error( sprintf( __( 'Unknown toolkit key "%s". Run `wp mcp-ai toolkit list` to see valid keys.', 'mcp-ai-wpoos-pro' ), $key ) );
		}

		if ( ! $yes ) {
			/* translators: 1: toolkit label, 2: toolkit key */
			WP_CLI::confirm( sprintf( __( 'Disable toolkit "%1$s" (%2$s)?', 'mcp-ai-wpoos-pro' ), self::toolkit_map()[ $key ], $key ) );
		}

		$settings         = get_option( 'wp_mcp_ai_settings', array() );
		$settings[ $key ] = '';
		update_option( 'wp_mcp_ai_settings', $settings );

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		/* translators: 1: toolkit key, 2: toolkit label */
		WP_CLI::success( sprintf( __( 'Toolkit "%2$s" (%1$s) disabled.', 'mcp-ai-wpoos-pro' ), $key, self::toolkit_map()[ $key ] ) );
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	require_once __DIR__ . '/class-wp-mcp-ai-pro-toolkit-settings-map.php';
	WP_CLI::add_command( 'mcp-ai toolkit', 'WP_MCP_AI_Pro_CLI_Toolkit_Command' );
}
