<?php
/**
 * WP_MCP_AI_Tool_Save_Site_Template (ecosystem port - Wave F2, site-creator tool batch).
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
 * Save Site Template Tool
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Save_Site_Template implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'save_site_template';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Save Site Template', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Saves complete site structures as reusable templates including pages, sections, widgets, and settings to the wp_site_template CPT.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'template_name' => array(
					'type'        => 'string',
					'description' => __( 'Template name', 'nvoos-content-graph-pro' ),
				),
				'description'   => array(
					'type'        => 'string',
					'description' => __( 'Template description', 'nvoos-content-graph-pro' ),
				),
				'template_data' => array(
					'type'        => 'object',
					'description' => __( 'Complete template structure', 'nvoos-content-graph-pro' ),
				),
				'category'      => array(
					'type'        => 'string',
					'description' => __( 'Template category', 'nvoos-content-graph-pro' ),
				),
			),
			'required'             => array( 'template_name', 'template_data' ),
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
	 * @return array|WP_Error Template ID or error.
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
		$template_name = isset( $arguments['template_name'] ) ? sanitize_text_field( $arguments['template_name'] ) : '';
		$description   = isset( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '';
		$template_data = isset( $arguments['template_data'] ) ? $arguments['template_data'] : array();
		$category      = isset( $arguments['category'] ) ? sanitize_text_field( $arguments['category'] ) : 'general';

		if ( empty( $template_name ) || empty( $template_data ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_required', __( 'Template name and data are required.', 'nvoos-content-graph-pro' ) );
		}

		// Create template post.
		$template_id = wp_insert_post(
			array(
				'post_type'    => 'wp_site_template',
				'post_title'   => $template_name,
				'post_content' => $description,
				'post_status'  => 'publish',
				'post_author'  => $user_id,
			)
		);

		if ( is_wp_error( $template_id ) ) {
			return $template_id;
		}

		// Save template data as meta.
		update_post_meta( $template_id, '_template_data', wp_json_encode( $template_data ) );
		update_post_meta( $template_id, '_template_version', '1.0.0' );
		update_post_meta( $template_id, '_created_date', current_time( 'mysql' ) );

		// Set category term.
		wp_set_object_terms( $template_id, $category, 'template_category' );

		return array(
			'success'     => true,
			'template_id' => $template_id,
			/* translators: 1: template name, 2: template ID */
			'summary'     => sprintf( __( 'Saved template "%1$s" (ID: %2$d).', 'nvoos-content-graph-pro' ), $template_name, $template_id ),
			'timestamp'   => current_time( 'mysql' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'requires-capability', 'non-deterministic' );
	}
}
