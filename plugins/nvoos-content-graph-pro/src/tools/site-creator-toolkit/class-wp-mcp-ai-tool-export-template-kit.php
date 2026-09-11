<?php
/**
 * WP_MCP_AI_Tool_Export_Template_Kit (ecosystem port - Wave F2, site-creator tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/site-creator-toolkit/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger seams resolve from the addon's D8-compat `src/` copies (the monorepo root classmap serves the base copies monolith).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Site_Creator_Toolkit
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);



if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface and
// Logger requires gain exists-check seams resolving from the addon's
// D8-compat copies (the monorepo root classmap serves the base copies
// monolith).
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	$nvoos_content_graph_pro_logger = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-logger.php';
	if ( file_exists( $nvoos_content_graph_pro_logger ) ) {
		require_once $nvoos_content_graph_pro_logger;
	}
}


/**
 * Export Template Kit Tool
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Export_Template_Kit implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True if tool is available.
	 */
	public static function is_available() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'export_template_kit';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Export Template Kit', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Exports template kits as portable JSON files for sharing, backup, and distribution.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'template_ids'     => array(
					'type'        => 'array',
					'description' => __( 'Array of template IDs to export', 'nvoos-content-graph-pro' ),
					'items'       => array( 'type' => 'integer' ),
				),
				'include_metadata' => array(
					'type'        => 'boolean',
					'description' => __( 'Include metadata and version info', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
			),
			'required'             => array( 'template_ids' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @since 1.2.0
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Export data or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if site creator toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator_toolkit'] ) ) {
			return new WP_Error( 'wp_mcp_ai_feature_disabled', __( 'The Site Creator Toolkit is disabled.', 'nvoos-content-graph-pro' ) );
		}

		// Check permissions.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission.', 'nvoos-content-graph-pro' ) );
		}

		// Sanitize arguments.
		$template_ids     = isset( $arguments['template_ids'] ) && is_array( $arguments['template_ids'] ) ?
			array_map( 'absint', $arguments['template_ids'] ) : array();
		$include_metadata = isset( $arguments['include_metadata'] ) ? (bool) $arguments['include_metadata'] : true;

		if ( empty( $template_ids ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_required', __( 'At least one template ID is required.', 'nvoos-content-graph-pro' ) );
		}

		// Export templates.
		$export_data = array(
			'version'   => '1.0.0',
			'exported'  => current_time( 'mysql' ),
			'templates' => array(),
		);

		foreach ( $template_ids as $template_id ) {
			$template_post = get_post( $template_id );
			if ( ! $template_post || 'wp_site_template' !== $template_post->post_type ) {
				continue;
			}

			$template_data_json = get_post_meta( $template_id, '_template_data', true );
			$template_data      = json_decode( $template_data_json, true );

			$export_item = array(
				'name' => $template_post->post_title,
				'data' => $template_data,
			);

			if ( $include_metadata ) {
				$export_item['metadata'] = array(
					'description' => $template_post->post_content,
					'version'     => get_post_meta( $template_id, '_template_version', true ),
					'created'     => get_post_meta( $template_id, '_created_date', true ),
				);
			}

			$export_data['templates'][] = $export_item;
		}

		return array(
			'success'     => true,
			'export_data' => $export_data,
			'file_name'   => 'template-kit-' . gmdate( 'Y-m-d' ) . '.json',
			/* translators: %d: number of templates exported */
			'summary'     => sprintf( __( 'Exported %d template(s).', 'nvoos-content-graph-pro' ), count( $export_data['templates'] ) ),
			'timestamp'   => current_time( 'mysql' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'read-only', 'requires-capability', 'non-deterministic' );
	}
}
