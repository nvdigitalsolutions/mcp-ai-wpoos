<?php
/**
 * WP_MCP_AI_Pro_Tool_Elementor (ecosystem port - Wave F2, site-creator tool batch).
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
 * Tool for Elementor template operations.
 *
 * Provides operations for Elementor templates including:
 * - Listing templates
 * - Getting template details
 *
 * Requires Elementor plugin to be active.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Pro_Tool_Elementor implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if Elementor is active.
	 */
	public static function is_available() {
		return defined( 'ELEMENTOR_VERSION' );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.0.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		return __( 'Elementor tool requires Elementor to be installed and activated.', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'elementor';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Elementor Templates', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Query Elementor templates. List and search saved templates, sections, and pages.', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'        => array(
					'type'        => 'string',
					'description' => __( 'The action to perform: list, get, search.', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'list', 'get', 'search' ),
					'default'     => 'list',
				),
				'template_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Template ID for get action.', 'nvoos-content-graph-pro' ),
				),
				'template_type' => array(
					'type'        => 'string',
					'description' => __( 'Filter by template type.', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'page', 'section', 'header', 'footer', 'single', 'archive' ),
				),
				'per_page'      => array(
					'type'        => 'integer',
					'description' => __( 'Number of templates to return. Default: 10. Max: 100.', 'nvoos-content-graph-pro' ),
					'default'     => 10,
					'maximum'     => 100,
				),
				'page'          => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination. Default: 1.', 'nvoos-content-graph-pro' ),
					'default'     => 1,
				),
				'search'        => array(
					'type'        => 'string',
					'description' => __( 'Search term to filter templates.', 'nvoos-content-graph-pro' ),
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',              // Pro tier tool.
			'read-only',        // Only read operations.
			'requires-plugin',  // Requires Elementor.
			'local-only',       // No external API calls.
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return mixed|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if Elementor is active.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'elementor_not_active',
				__( 'Elementor is not installed or activated.', 'nvoos-content-graph-pro' )
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'list';

		switch ( $action ) {
			case 'list':
				return $this->list_templates( $arguments );
			case 'get':
				return $this->get_template( $arguments );
			case 'search':
				return $this->search_templates( $arguments );
			default:
				return new WP_Error(
					'invalid_action',
					__( 'Invalid action specified.', 'nvoos-content-graph-pro' )
				);
		}
	}

	/**
	 * List Elementor templates.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	protected function list_templates( $arguments ) {
		$per_page = isset( $arguments['per_page'] ) ? min( absint( $arguments['per_page'] ), 100 ) : 10;
		$page     = isset( $arguments['page'] ) ? absint( $arguments['page'] ) : 1;

		$query_args = array(
			'post_type'      => 'elementor_library',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'date',
			'order'          => 'DESC',
		);

		if ( ! empty( $arguments['template_type'] ) ) {
			$query_args['meta_query'] = array(
				array(
					'key'   => '_elementor_template_type',
					'value' => sanitize_key( $arguments['template_type'] ),
				),
			);
		}

		if ( ! empty( $arguments['search'] ) ) {
			$query_args['s'] = sanitize_text_field( $arguments['search'] );
		}

		$query = new WP_Query( $query_args );

		$templates = array();
		foreach ( $query->posts as $post ) {
			$templates[] = $this->format_template( $post );
		}

		return array(
			'templates'   => $templates,
			'total'       => $query->found_posts,
			'total_pages' => $query->max_num_pages,
			'page'        => $page,
		);
	}

	/**
	 * Get a single template.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function get_template( $arguments ) {
		if ( empty( $arguments['template_id'] ) ) {
			return new WP_Error(
				'missing_template_id',
				__( 'Template ID is required for get action.', 'nvoos-content-graph-pro' )
			);
		}

		$template = get_post( absint( $arguments['template_id'] ) );

		if ( ! $template || 'elementor_library' !== $template->post_type ) {
			return new WP_Error(
				'template_not_found',
				__( 'Template not found.', 'nvoos-content-graph-pro' )
			);
		}

		return $this->format_template( $template, true );
	}

	/**
	 * Search templates.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function search_templates( $arguments ) {
		if ( empty( $arguments['search'] ) ) {
			return new WP_Error(
				'missing_search_term',
				__( 'Search term is required for search action.', 'nvoos-content-graph-pro' )
			);
		}

		return $this->list_templates( $arguments );
	}

	/**
	 * Format a template for output.
	 *
	 * @param WP_Post $template        Template post object.
	 * @param bool    $include_content Whether to include template data.
	 * @return array
	 */
	protected function format_template( $template, $include_content = false ) {
		$data = array(
			'id'            => $template->ID,
			'title'         => get_the_title( $template ),
			'slug'          => $template->post_name,
			'type'          => get_post_meta( $template->ID, '_elementor_template_type', true ),
			'date_created'  => $template->post_date,
			'date_modified' => $template->post_modified,
			'author'        => absint( $template->post_author ),
		);

		if ( $include_content ) {
			$data['elementor_data'] = get_post_meta( $template->ID, '_elementor_data', true );
		}

		return $data;
	}
}
