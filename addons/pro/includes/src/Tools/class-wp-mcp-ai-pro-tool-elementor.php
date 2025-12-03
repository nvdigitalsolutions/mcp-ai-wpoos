<?php
/**
 * Elementor Tool - Pro add-on tool for Elementor template operations.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
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
		return __( 'Elementor tool requires Elementor to be installed and activated.', 'wp-mcp-ai-pro' );
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
		return __( 'Elementor Templates', 'wp-mcp-ai-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Query Elementor templates. List and search saved templates, sections, and pages.', 'wp-mcp-ai-pro' );
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
					'description' => __( 'The action to perform: list, get, search.', 'wp-mcp-ai-pro' ),
					'enum'        => array( 'list', 'get', 'search' ),
					'default'     => 'list',
				),
				'template_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Template ID for get action.', 'wp-mcp-ai-pro' ),
				),
				'template_type' => array(
					'type'        => 'string',
					'description' => __( 'Filter by template type.', 'wp-mcp-ai-pro' ),
					'enum'        => array( 'page', 'section', 'header', 'footer', 'single', 'archive' ),
				),
				'per_page'      => array(
					'type'        => 'integer',
					'description' => __( 'Number of templates to return. Default: 10. Max: 100.', 'wp-mcp-ai-pro' ),
					'default'     => 10,
					'maximum'     => 100,
				),
				'page'          => array(
					'type'        => 'integer',
					'description' => __( 'Page number for pagination. Default: 1.', 'wp-mcp-ai-pro' ),
					'default'     => 1,
				),
				'search'        => array(
					'type'        => 'string',
					'description' => __( 'Search term to filter templates.', 'wp-mcp-ai-pro' ),
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
				__( 'Elementor is not installed or activated.', 'wp-mcp-ai-pro' )
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
					__( 'Invalid action specified.', 'wp-mcp-ai-pro' )
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
				__( 'Template ID is required for get action.', 'wp-mcp-ai-pro' )
			);
		}

		$template = get_post( absint( $arguments['template_id'] ) );

		if ( ! $template || 'elementor_library' !== $template->post_type ) {
			return new WP_Error(
				'template_not_found',
				__( 'Template not found.', 'wp-mcp-ai-pro' )
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
				__( 'Search term is required for search action.', 'wp-mcp-ai-pro' )
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
