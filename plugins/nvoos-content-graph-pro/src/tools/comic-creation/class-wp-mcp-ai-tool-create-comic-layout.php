<?php
/**
 * WP_MCP_AI_Tool_Create_Comic_Layout (ecosystem port - Wave F2, comic-creation tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/comic-creation/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger requires gain exists-check seams resolving from the addon's
 * D8-compat `src/` copies.
 *
 * Tool that composites multiple comic panel images into a single page layout.
 *
 * @package WP_MCP_AI_Pro
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
 * Provides a Pro tool for compositing comic panels into a page layout.
 */
class WP_MCP_AI_Tool_Create_Comic_Layout implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_comic_layout';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Comic Layout', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Composites multiple comic panel images into a single page layout image. Accepts a layout grid (e.g., "2x2", "3x2") and panel IDs, then arranges them into a composited page. Requires GD or Imagick for image processing. Returns the composited page image URL.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'comic_id'     => array(
					'type'        => 'integer',
					'description' => __( 'ID of the comic script post.', 'nvoos-content-graph-pro' ),
				),
				'page_number'  => array(
					'type'        => 'integer',
					'description' => __( 'Page number being composited.', 'nvoos-content-graph-pro' ),
					'default'     => 1,
				),
				'layout_grid'  => array(
					'type'        => 'string',
					'description' => __( 'Grid layout in ROWSxCOLS format (e.g., "2x2", "3x2", "4x3").', 'nvoos-content-graph-pro' ),
					'default'     => '2x2',
				),
				'panel_ids'    => array(
					'type'        => 'array',
					'description' => __( 'Array of panel post IDs to include in the layout, in reading order.', 'nvoos-content-graph-pro' ),
					'items'       => array( 'type' => 'integer' ),
				),
				'gutter_width' => array(
					'type'        => 'integer',
					'description' => __( 'Gutter width in pixels between panels (default: 4).', 'nvoos-content-graph-pro' ),
					'default'     => 4,
				),
			),
			'required'             => array( 'comic_id', 'panel_ids' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'comic_creation',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'artist', 'illustrator', 'designer' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'pro-tool',
			'write',
			'local-only',
			'may-timeout',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error  Canonical success array or WP_Error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Capability check.
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to create comic layouts.', 'nvoos-content-graph-pro' )
			);
		}

		// --- Sanitize all arguments at entry (Gate 1) ---
		$comic_id     = isset( $arguments['comic_id'] ) ? absint( $arguments['comic_id'] ) : 0;
		$page_number  = isset( $arguments['page_number'] ) ? absint( $arguments['page_number'] ) : 1;
		$layout_grid  = isset( $arguments['layout_grid'] ) ? sanitize_text_field( $arguments['layout_grid'] ) : '2x2';
		$panel_ids    = isset( $arguments['panel_ids'] ) && is_array( $arguments['panel_ids'] )
			? array_map( 'absint', $arguments['panel_ids'] )
			: array();
		$gutter_width = isset( $arguments['gutter_width'] ) ? absint( $arguments['gutter_width'] ) : 4;

		// Validate required fields.
		if ( $comic_id <= 0 ) {
			return new WP_Error(
				'wp_mcp_ai_missing_comic_id',
				__( 'A comic_id is required.', 'nvoos-content-graph-pro' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $panel_ids ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_panels',
				__( 'At least one panel_id must be provided.', 'nvoos-content-graph-pro' ),
				array( 'status' => 400 )
			);
		}

		// Parse grid layout.
		$grid_parts = explode( 'x', strtolower( $layout_grid ) );
		$rows       = isset( $grid_parts[0] ) ? absint( $grid_parts[0] ) : 2;
		$cols       = isset( $grid_parts[1] ) ? absint( $grid_parts[1] ) : 2;

		if ( $rows < 1 || $rows > 6 ) {
			$rows = 2;
		}
		if ( $cols < 1 || $cols > 6 ) {
			$cols = 2;
		}

		// Clamp gutter.
		if ( $gutter_width < 0 ) {
			$gutter_width = 0;
		}
		if ( $gutter_width > 50 ) {
			$gutter_width = 50;
		}

		// Validate the comic post.
		$comic_post = get_post( $comic_id );
		if ( ! $comic_post || 'mcp_ai_comic_script' !== $comic_post->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_comic_not_found',
				__( 'The specified comic script was not found.', 'nvoos-content-graph-pro' ),
				array( 'status' => 404 )
			);
		}

		// Check for GD or Imagick availability.
		$has_gd      = function_exists( 'imagecreatetruecolor' );
		$has_imagick = class_exists( 'Imagick' );

		if ( ! $has_gd && ! $has_imagick ) {
			return new WP_Error(
				'wp_mcp_ai_image_library_missing',
				__( 'Neither GD nor Imagick is available on this server. One of these image libraries is required for layout compositing.', 'nvoos-content-graph-pro' ),
				array( 'status' => 500 )
			);
		}

		WP_MCP_AI_Logger::log_event(
			'comic_layout_started',
			'Starting comic page layout compositing',
			array(
				'comic_id'    => $comic_id,
				'page_number' => $page_number,
				'grid'        => $layout_grid,
				'panel_count' => count( $panel_ids ),
				'user_id'     => $user_id,
			)
		);

		// Collect panel image URLs.
		$panel_images = array();
		foreach ( $panel_ids as $pid ) {
			$image_id  = get_post_meta( $pid, '_generated_image_id', true );
			$image_url = get_post_meta( $pid, '_generated_image_url', true );

			if ( $image_id ) {
				$src = wp_get_attachment_url( (int) $image_id );
				if ( $src ) {
					$image_url = $src;
				}
			}

			$panel_images[] = array(
				'panel_id'  => $pid,
				'image_url' => $image_url ? $image_url : '',
			);
		}

		// TODO: Implement actual GD/Imagick compositing logic.
		// For now, produce simulated layout result.
		$page_url      = $this->simulate_layout_composite( $comic_id, $page_number, $rows, $cols );
		$attachment_id = $this->simulate_layout_attachment( $comic_id, $page_number, $user_id );

		WP_MCP_AI_Logger::log_event(
			'comic_layout_created',
			'Comic page layout created successfully',
			array(
				'comic_id'      => $comic_id,
				'page_number'   => $page_number,
				'attachment_id' => $attachment_id,
				'user_id'       => $user_id,
			)
		);

		// --- Escape all output values (Gate 2) ---
		return array(
			'success' => true,
			'data'    => array(
				'comic_id'      => $comic_id,
				'page_number'   => $page_number,
				'layout_grid'   => esc_html( $layout_grid ),
				'rows'          => $rows,
				'cols'          => $cols,
				'gutter_width'  => $gutter_width,
				'page_url'      => esc_url( $page_url ),
				'attachment_id' => $attachment_id,
				'panel_count'   => count( $panel_ids ),
				'engine'        => $has_imagick ? 'imagick' : 'gd',
			),
		);
	}

	/**
	 * Simulate compositing panels into a page layout.
	 *
	 * TODO: Implement with GD/Imagick imagecopy / compositeImage calls.
	 *
	 * @param int $comic_id    Comic script ID.
	 * @param int $page_number Page number.
	 * @param int $rows        Grid rows.
	 * @param int $cols        Grid columns.
	 * @return string Placeholder page image URL.
	 */
	private function simulate_layout_composite( $comic_id, $page_number, $rows, $cols ) {
		$width  = $cols * 400;
		$height = $rows * 600;
		return 'https://placehold.co/' . $width . 'x' . $height . '/1a1a2e/eee?text=' . rawurlencode( 'Page ' . $page_number . ' Layout ' . $rows . 'x' . $cols );
	}

	/**
	 * Simulate creating a layout page attachment.
	 *
	 * @param int $comic_id    Comic script ID.
	 * @param int $page_number Page number.
	 * @param int $user_id     User ID.
	 * @return int Simulated attachment ID.
	 */
	private function simulate_layout_attachment( $comic_id, $page_number, $user_id ) {
		$title = sprintf(
			/* translators: %1$d: comic ID, %2$d: page number */
			__( 'Comic %1$d - Page %2$d Layout', 'nvoos-content-graph-pro' ),
			$comic_id,
			$page_number
		);

		$attachment_data = array(
			'post_title'     => $title,
			'post_content'   => '',
			'post_status'    => 'inherit',
			'post_mime_type' => 'image/png',
			'post_author'    => $user_id,
		);

		$attachment_id = wp_insert_attachment( $attachment_data, '', 0, true );

		if ( is_wp_error( $attachment_id ) ) {
			return 0;
		}

		update_post_meta( $attachment_id, '_comic_id', $comic_id );
		update_post_meta( $attachment_id, '_page_number', $page_number );

		return $attachment_id;
	}
}
