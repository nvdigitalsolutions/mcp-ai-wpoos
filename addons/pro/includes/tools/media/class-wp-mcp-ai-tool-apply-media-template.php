<?php
/**
 * Tool for applying a media template to an image.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Apply a media template to a single image.
 */
class WP_MCP_AI_Tool_Apply_Media_Template implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'apply_media_template';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Apply Media Template', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Apply a media template to a single image. Uses the template configuration to process the image via the Graphic Editor Plus tool. Updates template usage statistics and returns the processed image details.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'template_id'     => array(
					'type'        => 'integer',
					'description' => __( 'ID of the media template to apply', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'attachment_id'   => array(
					'type'        => 'integer',
					'description' => __( 'ID of the image attachment to process', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'override_params' => array(
					'type'        => 'object',
					'description' => __( 'Optional parameters to override template defaults (e.g., logo_attachment_id for logo operations)', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'template_id', 'attachment_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'upload_files';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'media_content',
			'post_type'             => 'mcp_ai_media_tpl',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'content_creator', 'designer' ),
			'risk_level'            => 'standard',
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'write',                // Creates new media files.
			'requires-capability',  // Requires upload_files capability.
			'state-changing',       // Modifies media library.
			'mixed-mode',           // Uses Graphic Editor Plus (some ops local, some external).
			'idempotent',           // Can be called multiple times safely.
			'performance-impact',   // Large images may temporarily affect performance.
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if media toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_media_toolkit'] ) ) {
			return new WP_Error(
				'tool_error',
				__( 'Media Toolkit is not enabled. Please enable it in Settings → NV oOS → Tools & Features.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate arguments.
		$template_id     = isset( $arguments['template_id'] ) ? absint( $arguments['template_id'] ) : 0;
		$attachment_id   = isset( $arguments['attachment_id'] ) ? absint( $arguments['attachment_id'] ) : 0;
		$override_params = isset( $arguments['override_params'] ) ? $arguments['override_params'] : array();

		if ( empty( $template_id ) ) {
			return new WP_Error(
				'tool_error',
				__( 'Template ID is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $attachment_id ) ) {
			return new WP_Error(
				'tool_error',
				__( 'Attachment ID is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Verify template exists and is a media template.
		$template = get_post( $template_id );
		if ( ! $template || 'mcp_ai_media_tpl' !== $template->post_type || 'publish' !== $template->post_status ) {
			return new WP_Error(
				'tool_error',
				__( 'Invalid template ID or template is not published.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Verify attachment exists and is an image.
		$attachment = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error(
				'tool_error',
				__( 'Invalid attachment ID.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return new WP_Error(
				'tool_error',
				__( 'Attachment is not an image.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Get template configuration.
		$operation  = get_post_meta( $template_id, '_mcp_ai_template_operation', true );
		$parameters = get_post_meta( $template_id, '_mcp_ai_template_parameters', true );

		if ( empty( $operation ) ) {
			return new WP_Error(
				'tool_error',
				__( 'Template operation is not configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Decode parameters.
		$params = array();
		if ( ! empty( $parameters ) ) {
			$params = json_decode( $parameters, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return new WP_Error(
					'tool_error',
					__( 'Template parameters are invalid JSON.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		// Merge with override params.
		if ( ! empty( $override_params ) && is_array( $override_params ) ) {
			$params = array_merge( $params, $override_params );
		}

		// Get the Graphic Editor Plus tool.
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( 'graphic_editor_plus' );

		if ( ! $tool ) {
			return new WP_Error(
				'tool_error',
				__( 'Graphic Editor Plus tool is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Build tool arguments.
		$tool_args = array_merge(
			$params,
			array(
				'operation'     => $operation,
				'attachment_id' => $attachment_id,
			)
		);

		// Execute the tool.
		$result = $tool->execute( $tool_args, $context );

		// Update template usage statistics on success.
		if ( ! empty( $result['success'] ) ) {
			$usage_count = absint( get_post_meta( $template_id, '_mcp_ai_template_usage_count', true ) );
			update_post_meta( $template_id, '_mcp_ai_template_usage_count', $usage_count + 1 );
			update_post_meta( $template_id, '_mcp_ai_template_last_used', current_time( 'mysql' ) );

			// Add template info to result.
			$result['template'] = array(
				'id'          => $template_id,
				'title'       => $template->post_title,
				'operation'   => $operation,
				'usage_count' => $usage_count + 1,
			);
		}

		return $result;
	}
}
