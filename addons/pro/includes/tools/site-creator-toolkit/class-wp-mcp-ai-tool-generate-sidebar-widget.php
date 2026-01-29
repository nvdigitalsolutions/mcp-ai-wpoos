<?php
/**
 * Generate Sidebar Widget Tool
 *
 * Creates sidebar widgets with dynamic content including recent posts,
 * categories, tags, and custom content areas.
 *
 * @package WP_MCP_AI
 * @subpackage Site_Creator_Toolkit
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate Sidebar Widget Tool
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Generate_Sidebar_Widget implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'generate_sidebar_widget';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Sidebar Widget', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates sidebar widgets with dynamic content including recent posts, categories, tags, search, and custom areas.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'widget_type' => array(
					'type'        => 'string',
					'description' => __( 'Sidebar widget type', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'recent-posts', 'categories', 'tags', 'search', 'custom-html', 'newsletter' ),
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'Widget title', 'mcp-ai-wpoos-pro' ),
				),
				'count'       => array(
					'type'        => 'integer',
					'description' => __( 'Number of items to display (for lists)', 'mcp-ai-wpoos-pro' ),
					'default'     => 5,
					'minimum'     => 1,
					'maximum'     => 10,
				),
			),
			'required'             => array( 'widget_type' ),
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
	 * @return array|WP_Error Sidebar widget data or error.
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
		$widget_type = isset( $arguments['widget_type'] ) ? sanitize_text_field( $arguments['widget_type'] ) : 'recent-posts';
		$title       = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : $this->get_default_title( $widget_type );
		$count       = isset( $arguments['count'] ) ? min( 10, max( 1, absint( $arguments['count'] ) ) ) : 5;

		// Generate sidebar widget.
		$sidebar_widget = array(
			'type'     => $widget_type,
			'title'    => $title,
			'settings' => $this->get_widget_settings( $widget_type, $count ),
		);

		return array(
			'success'        => true,
			'sidebar_widget' => $sidebar_widget,
			/* translators: %s: widget type */
			'summary'        => sprintf( __( 'Generated %s sidebar widget.', 'mcp-ai-wpoos-pro' ), $widget_type ),
			'timestamp'      => current_time( 'mysql' ),
		);
	}

	/**
	 * Get default title for widget type.
	 *
	 * @since 1.2.0
	 *
	 * @param string $widget_type Widget type.
	 * @return string Default title.
	 */
	private function get_default_title( $widget_type ) {
		$titles = array(
			'recent-posts' => 'Recent Posts',
			'categories'   => 'Categories',
			'tags'         => 'Tags',
			'search'       => 'Search',
			'custom-html'  => 'Custom Content',
			'newsletter'   => 'Newsletter',
		);

		return isset( $titles[ $widget_type ] ) ? $titles[ $widget_type ] : 'Widget';
	}

	/**
	 * Get widget settings.
	 *
	 * @since 1.2.0
	 *
	 * @param string $widget_type Widget type.
	 * @param int    $count       Item count.
	 * @return array Settings.
	 */
	private function get_widget_settings( $widget_type, $count ) {
		$settings = array();

		switch ( $widget_type ) {
			case 'recent-posts':
				$settings = array(
					'count'          => $count,
					'show_date'      => true,
					'show_thumbnail' => true,
				);
				break;

			case 'categories':
				$settings = array(
					'show_count'   => true,
					'hierarchical' => true,
					'dropdown'     => false,
				);
				break;

			case 'tags':
				$settings = array(
					'count'      => $count,
					'show_count' => true,
					'style'      => 'cloud',
				);
				break;

			case 'search':
				$settings = array(
					'placeholder' => 'Search...',
					'button_text' => 'Search',
				);
				break;

			case 'newsletter':
				$settings = array(
					'description' => 'Subscribe to our newsletter',
					'button_text' => 'Subscribe',
				);
				break;
		}

		return $settings;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'requires-capability', 'non-deterministic' );
	}
}
