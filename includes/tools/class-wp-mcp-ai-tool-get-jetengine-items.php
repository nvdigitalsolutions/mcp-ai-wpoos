<?php
/**
 * Tool returning items registered via JetEngine (custom post types).
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides access to JetEngine registered post types.
 */
class WP_MCP_AI_Tool_Get_JetEngine_Items implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Determine whether JetEngine is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return function_exists( 'jet_engine' ) || class_exists( 'Jet_Engine' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The JetEngine Items tool is disabled because JetEngine is not active.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_jetengine_items';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get JetEngine Items', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns content items from a JetEngine managed post type. Requires JetEngine.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'post_type' => array(
					'type'        => 'string',
					'description' => __( 'JetEngine post type slug to query.', 'wp-mcp-ai' ),
				),
				'limit'     => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of items to retrieve.', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
			),
			'required'             => array( 'post_type' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_jetengine_missing', __( 'JetEngine is not active on this site.', 'wp-mcp-ai' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view JetEngine content.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$post_type = isset( $arguments['post_type'] ) ? sanitize_key( $arguments['post_type'] ) : '';
		if ( empty( $post_type ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_post_type', __( 'A JetEngine post type must be provided.', 'wp-mcp-ai' ) );
		}

		$post_type_object = get_post_type_object( $post_type );
		if ( ! $post_type_object ) {
			return new WP_Error( 'wp_mcp_ai_unknown_post_type', __( 'The requested post type does not exist.', 'wp-mcp-ai' ) );
		}

		$required_cap = isset( $post_type_object->cap->edit_posts ) ? $post_type_object->cap->edit_posts : 'edit_posts';
		if ( ! user_can( $user_id, $required_cap ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to read content from this post type.', 'wp-mcp-ai' ) );
		}

		$limit = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 10;
		$limit = $limit > 0 ? min( $limit, 50 ) : 10;

		$items = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'posts_per_page'         => $limit,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'no_found_rows'          => true,  // Performance: Skip counting total rows.
				'update_post_term_cache' => false, // Performance: Skip term cache if not needed.
				'update_post_meta_cache' => true,  // Keep meta cache for JetEngine data.
			)
		);

		$results = array();
		foreach ( $items as $item ) {
			$results[] = array(
				'ID'        => $item->ID,
				'title'     => get_the_title( $item ),
				'permalink' => get_permalink( $item ),
				'excerpt'   => wp_trim_words( wp_strip_all_tags( $item->post_content ), 30 ),
				'date'      => get_the_date( DATE_W3C, $item ),
			);
		}

		return $results;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
