<?php
/**
 * WP_MCP_AI_Tool_Generate_Feature_Section (ecosystem port - Wave F2, site-creator tool batch).
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
 * Generate Feature Section Tool
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Generate_Feature_Section implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'generate_feature_section';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Feature Section', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates feature showcase sections with icons, titles, and descriptions. Supports grid, list, and card layouts with customizable columns and styling.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'    => array(
					'type'        => 'string',
					'description' => __( 'Section title', 'nvoos-content-graph-pro' ),
					'default'     => 'Our Features',
				),
				'features' => array(
					'type'        => 'array',
					'description' => __( 'Array of features to showcase', 'nvoos-content-graph-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'title'       => array( 'type' => 'string' ),
							'description' => array( 'type' => 'string' ),
						),
					),
				),
				'layout'   => array(
					'type'        => 'string',
					'description' => __( 'Layout style', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'grid', 'list', 'cards' ),
					'default'     => 'grid',
				),
				'columns'  => array(
					'type'        => 'integer',
					'description' => __( 'Number of columns (2-4)', 'nvoos-content-graph-pro' ),
					'default'     => 3,
					'minimum'     => 2,
					'maximum'     => 4,
				),
			),
			'required'             => array( 'features' ),
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
	 * @return array|WP_Error Feature section data or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if site creator toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator_toolkit'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_feature_disabled',
				__( 'The Site Creator Toolkit is disabled.', 'nvoos-content-graph-pro' )
			);
		}

		// Check permissions.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_pages' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission.', 'nvoos-content-graph-pro' ) );
		}

		// Sanitize arguments.
		$title    = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : 'Our Features';
		$features = isset( $arguments['features'] ) && is_array( $arguments['features'] ) ? $arguments['features'] : array();
		$layout   = isset( $arguments['layout'] ) ? sanitize_text_field( $arguments['layout'] ) : 'grid';
		$columns  = isset( $arguments['columns'] ) ? min( 4, max( 2, absint( $arguments['columns'] ) ) ) : 3;

		if ( empty( $features ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_required', __( 'Features array is required.', 'nvoos-content-graph-pro' ) );
		}

		// Generate feature section.
		$feature_section = array(
			'type'     => 'features',
			'title'    => $title,
			'layout'   => $layout,
			'columns'  => $columns,
			'features' => array_map(
				function ( $feature ) {
					return array(
						'icon'        => 'star',
						'title'       => isset( $feature['title'] ) ? sanitize_text_field( $feature['title'] ) : '',
						'description' => isset( $feature['description'] ) ? sanitize_textarea_field( $feature['description'] ) : '',
					);
				},
				$features
			),
		);

		return array(
			'success'         => true,
			'feature_section' => $feature_section,
			/* translators: 1: number of features, 2: layout type */
			'summary'         => sprintf( __( 'Generated feature section with %1$d features in %2$s layout.', 'nvoos-content-graph-pro' ), count( $features ), $layout ),
			'timestamp'       => current_time( 'mysql' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'requires-capability', 'non-deterministic' );
	}
}
