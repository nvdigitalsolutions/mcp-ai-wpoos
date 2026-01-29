<?php
/**
 * Generate Gallery Section Tool
 *
 * Creates image and portfolio gallery sections with lightbox, filters,
 * and various layout options (grid, masonry, carousel).
 *
 * @package WP_MCP_AI
 * @subpackage Site_Creator_Toolkit
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate Gallery Section Tool
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Generate_Gallery_Section implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'generate_gallery_section';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Gallery Section', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates image and portfolio gallery sections with lightbox, filters, and various layouts. Supports grid, masonry, and carousel presentations.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'title'           => array(
					'type'        => 'string',
					'description' => __( 'Gallery title', 'mcp-ai-wpoos-pro' ),
					'default'     => 'Our Portfolio',
				),
				'layout'          => array(
					'type'        => 'string',
					'description' => __( 'Gallery layout', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'grid', 'masonry', 'carousel', 'justified' ),
					'default'     => 'grid',
				),
				'columns'         => array(
					'type'        => 'integer',
					'description' => __( 'Number of columns (2-5)', 'mcp-ai-wpoos-pro' ),
					'default'     => 3,
					'minimum'     => 2,
					'maximum'     => 5,
				),
				'include_filters' => array(
					'type'        => 'boolean',
					'description' => __( 'Include category filters', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'lightbox'        => array(
					'type'        => 'boolean',
					'description' => __( 'Enable lightbox on click', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'             => array(),
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
	 * @return array|WP_Error Gallery section data or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if site creator toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator_toolkit'] ) ) {
			return new WP_Error( 'wp_mcp_ai_feature_disabled', __( 'The Site Creator Toolkit is disabled.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check permissions.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_pages' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize arguments.
		$title           = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : 'Our Portfolio';
		$layout          = isset( $arguments['layout'] ) ? sanitize_text_field( $arguments['layout'] ) : 'grid';
		$columns         = isset( $arguments['columns'] ) ? min( 5, max( 2, absint( $arguments['columns'] ) ) ) : 3;
		$include_filters = isset( $arguments['include_filters'] ) ? (bool) $arguments['include_filters'] : true;
		$lightbox        = isset( $arguments['lightbox'] ) ? (bool) $arguments['lightbox'] : true;

		// Generate gallery section.
		$gallery_section = array(
			'type'    => 'gallery',
			'title'   => $title,
			'layout'  => $layout,
			'columns' => $columns,
			'options' => array(
				'lightbox'  => $lightbox,
				'lazy_load' => true,
			),
		);

		if ( $include_filters ) {
			$gallery_section['filters'] = array(
				'categories' => array( 'All', 'Category 1', 'Category 2', 'Category 3' ),
				'style'      => 'buttons',
			);
		}

		$gallery_section['items'] = $this->generate_gallery_items();

		return array(
			'success'         => true,
			'gallery_section' => $gallery_section,
			/* translators: %s: layout type */
			'summary'         => sprintf( __( 'Generated %s gallery section with filters and lightbox.', 'mcp-ai-wpoos-pro' ), $layout ),
			'timestamp'       => current_time( 'mysql' ),
		);
	}

	/**
	 * Generate gallery items.
	 *
	 * @since 1.2.0
	 *
	 * @return array Gallery items.
	 */
	private function generate_gallery_items() {
		$items = array();
		for ( $i = 1; $i <= 12; $i++ ) {
			$items[] = array(
				'title'       => 'Gallery Item ' . $i,
				'description' => 'Description for item ' . $i,
				'category'    => 'Category ' . ( ( $i % 3 ) + 1 ),
				'image'       => array(
					'placeholder' => true,
					'alt'         => 'Gallery item ' . $i,
				),
			);
		}
		return $items;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'requires-capability', 'non-deterministic' );
	}
}
