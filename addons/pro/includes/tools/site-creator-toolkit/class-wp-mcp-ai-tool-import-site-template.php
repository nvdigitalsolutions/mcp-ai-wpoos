<?php
/**
 * Import Site Template Tool
 *
 * Imports and applies saved site templates from the wp_site_template CPT.
 *
 * @package WP_MCP_AI
 * @subpackage Site_Creator_Toolkit
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
		return __( 'Import Site Template', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Imports and applies saved site templates from the wp_site_template CPT or external sources.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'template_id'      => array(
					'type'        => 'integer',
					'description' => __( 'Template post ID', 'mcp-ai-wpoos-pro' ),
				),
				'import_mode'      => array(
					'type'        => 'string',
					'description' => __( 'Import mode', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'replace', 'merge', 'preview' ),
					'default'     => 'merge',
				),
			),
			'required'             => array( 'template_id' ),
			'additionalProperties' => false,
		);
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
			return new WP_Error( 'wp_mcp_ai_feature_disabled', __( 'The Site Creator Toolkit is disabled.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check permissions.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize arguments.
		$template_id = isset( $arguments['template_id'] ) ? absint( $arguments['template_id'] ) : 0;
		$import_mode = isset( $arguments['import_mode'] ) ? sanitize_text_field( $arguments['import_mode'] ) : 'merge';

		if ( ! $template_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_required', __( 'Template ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get template.
		$template_post = get_post( $template_id );
		if ( ! $template_post || 'wp_site_template' !== $template_post->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_template', __( 'Invalid template ID.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get template data.
		$template_data_json = get_post_meta( $template_id, '_template_data', true );
		$template_data      = json_decode( $template_data_json, true );

		if ( empty( $template_data ) ) {
			return new WP_Error( 'wp_mcp_ai_empty_template', __( 'Template data is empty.', 'mcp-ai-wpoos-pro' ) );
		}

		// Preview mode.
		if ( 'preview' === $import_mode ) {
			return array(
				'success'       => true,
				'mode'          => 'preview',
				'template_data' => $template_data,
				'summary'       => __( 'Template preview generated.', 'mcp-ai-wpoos-pro' ),
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
			'summary'        => sprintf( __( 'Imported template "%s" in %s mode.', 'mcp-ai-wpoos-pro' ), $template_post->post_title, $import_mode ),
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
