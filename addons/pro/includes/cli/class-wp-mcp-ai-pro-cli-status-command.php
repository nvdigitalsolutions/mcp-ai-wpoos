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
	 * Map of every known toolkit/feature settings key to its human-readable label.
	 *
	 * @var array<string,string>
	 */
	private static $toolkit_keys = array(
		'enable_crm_toolkit'                     => 'CRM Toolkit',
		'enable_ecommerce_toolkit'               => 'E-commerce Toolkit',
		'enable_flowhub_toolkit'                 => 'FlowHub Toolkit',
		'enable_shopify_sync_toolkit'            => 'Shopify Sync Toolkit',
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
		foreach ( self::$toolkit_keys as $key => $label ) {
			if ( ! empty( $settings[ $key ] ) ) {
				$active[] = $label;
			}
		}
		return $active;
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai pro status', 'WP_MCP_AI_Pro_CLI_Status_Command' );
}
