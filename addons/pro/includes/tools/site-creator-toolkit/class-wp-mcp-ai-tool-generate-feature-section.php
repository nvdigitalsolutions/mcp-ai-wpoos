<?php
/**
 * Generate Feature Section Tool
 *
 * Creates feature showcase sections with icons, titles, descriptions,
 * and various layout options (grid, list, card-based).
 *
 * @package WP_MCP_AI
 * @subpackage Site_Creator_Toolkit
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
		return __( 'Generate Feature Section', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates feature showcase sections with icons, titles, and descriptions. Supports grid, list, and card layouts with customizable columns and styling.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Section title', 'mcp-ai-wpoos-pro' ),
					'default'     => 'Our Features',
				),
				'features' => array(
					'type'        => 'array',
					'description' => __( 'Array of features to showcase', 'mcp-ai-wpoos-pro' ),
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
					'description' => __( 'Layout style', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'grid', 'list', 'cards' ),
					'default'     => 'grid',
				),
				'columns'  => array(
					'type'        => 'integer',
					'description' => __( 'Number of columns (2-4)', 'mcp-ai-wpoos-pro' ),
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
				__( 'The Site Creator Toolkit is disabled.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check permissions.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_pages' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize arguments.
		$title    = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : 'Our Features';
		$features = isset( $arguments['features'] ) && is_array( $arguments['features'] ) ? $arguments['features'] : array();
		$layout   = isset( $arguments['layout'] ) ? sanitize_text_field( $arguments['layout'] ) : 'grid';
		$columns  = isset( $arguments['columns'] ) ? min( 4, max( 2, absint( $arguments['columns'] ) ) ) : 3;

		if ( empty( $features ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_required', __( 'Features array is required.', 'mcp-ai-wpoos-pro' ) );
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
			'summary'         => sprintf( __( 'Generated feature section with %1$d features in %2$s layout.', 'mcp-ai-wpoos-pro' ), count( $features ), $layout ),
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
