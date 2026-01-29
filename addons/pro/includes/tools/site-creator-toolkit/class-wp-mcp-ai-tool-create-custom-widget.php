<?php
/**
 * Create Custom Widget Tool
 *
 * Generates custom WordPress widgets with dynamic content capabilities,
 * customizable settings, and responsive design.
 *
 * @package WP_MCP_AI
 * @subpackage Site_Creator_Toolkit
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create Custom Widget Tool
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Create_Custom_Widget implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'create_custom_widget';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Custom Widget', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates custom WordPress widgets with dynamic content, settings panel, and responsive design. Creates widget code and configuration.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'widget_name'      => array(
					'type'        => 'string',
					'description' => __( 'Widget name', 'mcp-ai-wpoos-pro' ),
				),
				'widget_type'      => array(
					'type'        => 'string',
					'description' => __( 'Widget type', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'content', 'cta', 'social', 'newsletter', 'recent-posts', 'custom' ),
				),
				'description'      => array(
					'type'        => 'string',
					'description' => __( 'Widget description', 'mcp-ai-wpoos-pro' ),
				),
				'configurable_options' => array(
					'type'        => 'array',
					'description' => __( 'Configurable options for widget settings', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
			),
			'required'             => array( 'widget_name', 'widget_type' ),
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
	 * @return array|WP_Error Widget data or error.
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
		$widget_name = isset( $arguments['widget_name'] ) ? sanitize_text_field( $arguments['widget_name'] ) : '';
		$widget_type = isset( $arguments['widget_type'] ) ? sanitize_text_field( $arguments['widget_type'] ) : 'custom';
		$description = isset( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '';
		$options     = isset( $arguments['configurable_options'] ) && is_array( $arguments['configurable_options'] ) ?
			array_map( 'sanitize_text_field', $arguments['configurable_options'] ) : array();

		if ( empty( $widget_name ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_required', __( 'Widget name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Generate widget structure.
		$widget = array(
			'name'        => $widget_name,
			'type'        => $widget_type,
			'description' => ! empty( $description ) ? $description : "Custom {$widget_type} widget",
			'class_name'  => $this->generate_class_name( $widget_name ),
			'settings'    => $this->generate_widget_settings( $widget_type, $options ),
			'output'      => $this->generate_widget_output( $widget_type ),
		);

		return array(
			'success'   => true,
			'widget'    => $widget,
			'summary'   => sprintf( __( 'Generated %s widget with customizable settings.', 'mcp-ai-wpoos-pro' ), $widget_type ),
			'timestamp' => current_time( 'mysql' ),
		);
	}

	/**
	 * Generate class name.
	 *
	 * @since 1.2.0
	 *
	 * @param string $widget_name Widget name.
	 * @return string Class name.
	 */
	private function generate_class_name( $widget_name ) {
		return 'WP_Widget_' . str_replace( array( ' ', '-' ), '_', ucwords( $widget_name ) );
	}

	/**
	 * Generate widget settings.
	 *
	 * @since 1.2.0
	 *
	 * @param string $widget_type Widget type.
	 * @param array  $options     Additional options.
	 * @return array Settings.
	 */
	private function generate_widget_settings( $widget_type, $options ) {
		$base_settings = array(
			array(
				'name'    => 'title',
				'type'    => 'text',
				'label'   => 'Widget Title',
				'default' => '',
			),
		);

		// Add type-specific settings.
		switch ( $widget_type ) {
			case 'cta':
				$base_settings[] = array(
					'name'  => 'button_text',
					'type'  => 'text',
					'label' => 'Button Text',
				);
				$base_settings[] = array(
					'name'  => 'button_url',
					'type'  => 'url',
					'label' => 'Button URL',
				);
				break;

			case 'social':
				$base_settings[] = array(
					'name'    => 'platforms',
					'type'    => 'multiselect',
					'label'   => 'Social Platforms',
					'options' => array( 'facebook', 'twitter', 'instagram', 'linkedin' ),
				);
				break;

			case 'newsletter':
				$base_settings[] = array(
					'name'  => 'form_id',
					'type'  => 'text',
					'label' => 'Form ID',
				);
				break;
		}

		return $base_settings;
	}

	/**
	 * Generate widget output.
	 *
	 * @since 1.2.0
	 *
	 * @param string $widget_type Widget type.
	 * @return array Output structure.
	 */
	private function generate_widget_output( $widget_type ) {
		return array(
			'template' => 'widget-' . $widget_type,
			'classes'  => array( 'custom-widget', 'widget-' . $widget_type ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'requires-capability', 'consumes-tokens', 'non-deterministic' );
	}
}
