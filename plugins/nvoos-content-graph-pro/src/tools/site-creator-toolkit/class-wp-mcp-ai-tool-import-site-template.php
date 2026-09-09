<?php
/**
 * WP_MCP_AI_Tool_Import_Site_Template (ecosystem port - Wave F2, site-creator tool batch).
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
 * Import Site Template Tool
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Import_Site_Template implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'import_site_template';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Import Site Template', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Imports and applies saved site templates from the wp_site_template CPT or external sources.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'template_id' => array(
					'type'        => 'integer',
					'description' => __( 'Template post ID', 'nvoos-content-graph-pro' ),
				),
				'import_mode' => array(
					'type'        => 'string',
					'description' => __( 'Import mode', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'replace', 'merge', 'preview' ),
					'default'     => 'merge',
				),
			),
			'required'             => array( 'template_id' ),
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
	 * @return array|WP_Error Import result or error.
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
		$template_id = isset( $arguments['template_id'] ) ? absint( $arguments['template_id'] ) : 0;
		$import_mode = isset( $arguments['import_mode'] ) ? sanitize_text_field( $arguments['import_mode'] ) : 'merge';

		if ( ! $template_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_required', __( 'Template ID is required.', 'nvoos-content-graph-pro' ) );
		}

		// Get template.
		$template_post = get_post( $template_id );
		if ( ! $template_post || 'wp_site_template' !== $template_post->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_template', __( 'Invalid template ID.', 'nvoos-content-graph-pro' ) );
		}

		// Get template data.
		$template_data_json = get_post_meta( $template_id, '_template_data', true );
		$template_data      = json_decode( $template_data_json, true );

		if ( empty( $template_data ) ) {
			return new WP_Error( 'wp_mcp_ai_empty_template', __( 'Template data is empty.', 'nvoos-content-graph-pro' ) );
		}

		// Preview mode.
		if ( 'preview' === $import_mode ) {
			return array(
				'success'       => true,
				'mode'          => 'preview',
				'template_data' => $template_data,
				'summary'       => __( 'Template preview generated.', 'nvoos-content-graph-pro' ),
			);
		}

		// Import template (simplified placeholder).
		$imported_items = array(
			'pages'    => 0,
			'sections' => 0,
			'widgets'  => 0,
		);

		return array(
			'success'        => true,
			'mode'           => $import_mode,
			'template_name'  => $template_post->post_title,
			'imported_items' => $imported_items,
			/* translators: 1: template name, 2: import mode */
			'summary'        => sprintf( __( 'Imported template "%1$s" in %2$s mode.', 'nvoos-content-graph-pro' ), $template_post->post_title, $import_mode ),
			'timestamp'      => current_time( 'mysql' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'requires-capability', 'non-deterministic' );
	}
}
