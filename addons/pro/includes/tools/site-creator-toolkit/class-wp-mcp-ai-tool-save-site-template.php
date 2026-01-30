<?php
/**
 * Save Site Template Tool
 *
 * Saves complete site structures as reusable templates including pages,
 * sections, widgets, and settings.
 *
 * @package WP_MCP_AI
 * @subpackage Site_Creator_Toolkit
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
		return __( 'Save Site Template', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Saves complete site structures as reusable templates including pages, sections, widgets, and settings to the wp_site_template CPT.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Template name', 'mcp-ai-wpoos-pro' ),
				),
				'description'   => array(
					'type'        => 'string',
					'description' => __( 'Template description', 'mcp-ai-wpoos-pro' ),
				),
				'template_data' => array(
					'type'        => 'object',
					'description' => __( 'Complete template structure', 'mcp-ai-wpoos-pro' ),
				),
				'category'      => array(
					'type'        => 'string',
					'description' => __( 'Template category', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array( 'template_name', 'template_data' ),
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
	 * @return array|WP_Error Template ID or error.
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
		$template_name = isset( $arguments['template_name'] ) ? sanitize_text_field( $arguments['template_name'] ) : '';
		$description   = isset( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '';
		$template_data = isset( $arguments['template_data'] ) ? $arguments['template_data'] : array();
		$category      = isset( $arguments['category'] ) ? sanitize_text_field( $arguments['category'] ) : 'general';

		if ( empty( $template_name ) || empty( $template_data ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_required', __( 'Template name and data are required.', 'mcp-ai-wpoos-pro' ) );
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
			'summary'     => sprintf( __( 'Saved template "%1$s" (ID: %2$d).', 'mcp-ai-wpoos-pro' ), $template_name, $template_id ),
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
