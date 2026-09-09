<?php
/**
 * WP_MCP_AI_Tool_Generate_Gallery_Section (ecosystem port - Wave F2, site-creator tool batch).
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
		return __( 'Generate Gallery Section', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates image and portfolio gallery sections with lightbox, filters, and various layouts. Supports grid, masonry, and carousel presentations.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'Gallery title', 'nvoos-content-graph-pro' ),
					'default'     => 'Our Portfolio',
				),
				'layout'          => array(
					'type'        => 'string',
					'description' => __( 'Gallery layout', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'grid', 'masonry', 'carousel', 'justified' ),
					'default'     => 'grid',
				),
				'columns'         => array(
					'type'        => 'integer',
					'description' => __( 'Number of columns (2-5)', 'nvoos-content-graph-pro' ),
					'default'     => 3,
					'minimum'     => 2,
					'maximum'     => 5,
				),
				'include_filters' => array(
					'type'        => 'boolean',
					'description' => __( 'Include category filters', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
				'lightbox'        => array(
					'type'        => 'boolean',
					'description' => __( 'Enable lightbox on click', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
			),
			'required'             => array(),
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
	 * @return array|WP_Error Gallery section data or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if site creator toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator_toolkit'] ) ) {
			return new WP_Error( 'wp_mcp_ai_feature_disabled', __( 'The Site Creator Toolkit is disabled.', 'nvoos-content-graph-pro' ) );
		}

		// Check permissions.
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'edit_pages' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission.', 'nvoos-content-graph-pro' ) );
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
			'summary'         => sprintf( __( 'Generated %s gallery section with filters and lightbox.', 'nvoos-content-graph-pro' ), $layout ),
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
