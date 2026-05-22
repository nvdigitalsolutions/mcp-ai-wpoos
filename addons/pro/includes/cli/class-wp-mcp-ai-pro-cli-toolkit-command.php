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
	 * @var array<string,string>
	 */
	const TOOLKITS = array(
		'enable_crm_toolkit'                     => 'CRM Toolkit',
		'enable_ecommerce_toolkit'               => 'E-commerce Toolkit',
		'enable_social_media_toolkit'            => 'Social Media Toolkit',
		'enable_analytics_toolkit'               => 'Analytics Toolkit',
		'enable_multilingual_toolkit'            => 'Multilingual Toolkit',
		'enable_video_production_toolkit'        => 'Video Production Toolkit',
		'enable_financial_planner_toolkit'       => 'Financial Planner Toolkit',
		'enable_dj_management_toolkit'           => 'DJ Management Toolkit',
		'enable_image_production_toolkit'        => 'Image Production Toolkit',
		'enable_ai_tool_builder_toolkit'         => 'AI Tool Builder Toolkit',
		'enable_architect_agent_toolkit'         => 'Architect Agent Toolkit',
		'enable_architectural_design_toolkit'    => 'Architectural Design Toolkit',
		'enable_site_creator_toolkit'            => 'Site Creator Toolkit',
		'enable_document_generation_toolkit'     => 'Document Generation Toolkit',
		'enable_regulatory_registration_toolkit' => 'Regulatory Registration Toolkit',
		'enable_chat_channels_toolkit'           => 'Chat Channels Toolkit',
		'enable_project_management'              => 'Project Management',
		'enable_eca_management'                  => 'ECA Management',
		'enable_quiz_system'                     => 'Quiz System',
		'enable_places_management'               => 'Places Management',
		'enable_health_wellness_management'      => 'Health & Wellness Management',
		'enable_media_toolkit'                   => 'Media Toolkit',
		'enable_ai_cpt_management'               => 'AI CPT Management',
		'enable_advanced_analytics_toolkit'      => 'Advanced Analytics Toolkit',
	);

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
	public function list_( $args, $assoc_args ) {
		$this->assert_pro_loaded();

		$status_filter = \WP_CLI\Utils\get_flag_value( $assoc_args, 'status', '' );
		$format        = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$items    = array();

		foreach ( self::TOOLKITS as $key => $label ) {
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
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai toolkit enable enable_crm_toolkit
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function enable( $args, $assoc_args ) {
		$this->assert_pro_loaded();

		$key = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';

		if ( ! $key ) {
			WP_CLI::error( __( 'Please provide a toolkit settings key.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! array_key_exists( $key, self::TOOLKITS ) ) {
			/* translators: %s: toolkit key */
			WP_CLI::error( sprintf( __( 'Unknown toolkit key "%s". Run `wp mcp-ai toolkit list` to see valid keys.', 'mcp-ai-wpoos-pro' ), $key ) );
		}

		$settings         = get_option( 'wp_mcp_ai_settings', array() );
		$settings[ $key ] = '1';
		update_option( 'wp_mcp_ai_settings', $settings );

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		/* translators: 1: toolkit key, 2: toolkit label */
		WP_CLI::success( sprintf( __( 'Toolkit "%2$s" (%1$s) enabled.', 'mcp-ai-wpoos-pro' ), $key, self::TOOLKITS[ $key ] ) );
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

		$key = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';
		$yes = \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false );

		if ( ! $key ) {
			WP_CLI::error( __( 'Please provide a toolkit settings key.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! array_key_exists( $key, self::TOOLKITS ) ) {
			/* translators: %s: toolkit key */
			WP_CLI::error( sprintf( __( 'Unknown toolkit key "%s". Run `wp mcp-ai toolkit list` to see valid keys.', 'mcp-ai-wpoos-pro' ), $key ) );
		}

		if ( ! $yes ) {
			/* translators: 1: toolkit label, 2: toolkit key */
			WP_CLI::confirm( sprintf( __( 'Disable toolkit "%1$s" (%2$s)?', 'mcp-ai-wpoos-pro' ), self::TOOLKITS[ $key ], $key ) );
		}

		$settings         = get_option( 'wp_mcp_ai_settings', array() );
		$settings[ $key ] = '';
		update_option( 'wp_mcp_ai_settings', $settings );

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		/* translators: 1: toolkit key, 2: toolkit label */
		WP_CLI::success( sprintf( __( 'Toolkit "%2$s" (%1$s) disabled.', 'mcp-ai-wpoos-pro' ), $key, self::TOOLKITS[ $key ] ) );
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai toolkit', 'WP_MCP_AI_Pro_CLI_Toolkit_Command' );
}
