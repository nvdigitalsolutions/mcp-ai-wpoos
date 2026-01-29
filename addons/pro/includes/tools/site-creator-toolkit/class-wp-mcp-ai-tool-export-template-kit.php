<?php
/**
 * Export Template Kit Tool
 *
 * Exports template kits as portable JSON files for sharing and backup.
 *
 * @package WP_MCP_AI
 * @subpackage Site_Creator_Toolkit
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
		return __( 'Export Template Kit', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Exports template kits as portable JSON files for sharing, backup, and distribution.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Array of template IDs to export', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'integer' ),
				),
				'include_metadata' => array(
					'type'        => 'boolean',
					'description' => __( 'Include metadata and version info', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'             => array( 'template_ids' ),
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
	 * @return array|WP_Error Export data or error.
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
		$template_ids     = isset( $arguments['template_ids'] ) && is_array( $arguments['template_ids'] ) ?
			array_map( 'absint', $arguments['template_ids'] ) : array();
		$include_metadata = isset( $arguments['include_metadata'] ) ? (bool) $arguments['include_metadata'] : true;

		if ( empty( $template_ids ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_required', __( 'At least one template ID is required.', 'mcp-ai-wpoos-pro' ) );
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
			'file_name'   => 'template-kit-' . date( 'Y-m-d' ) . '.json',
			'summary'     => sprintf( __( 'Exported %d template(s).', 'mcp-ai-wpoos-pro' ), count( $export_data['templates'] ) ),
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
