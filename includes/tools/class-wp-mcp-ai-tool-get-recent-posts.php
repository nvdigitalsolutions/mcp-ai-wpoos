<?php
/**
 * Tool that returns recent WordPress posts.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns a list of recent posts.
 */
class WP_MCP_AI_Tool_Get_Recent_Posts implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_recent_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Recent Posts', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns the most recent published posts, including titles and permalinks.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'limit'     => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of posts to return.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 5,
				),
				'post_type' => array(
					'type'        => 'string',
					'description' => __( 'The post type to query.', 'mcp-ai-wpoos' ),
					'default'     => 'post',
				),
			),
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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view recent posts.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		$limit     = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 5;
		$limit     = $limit > 0 ? min( $limit, 50 ) : 5;
		$post_type = isset( $arguments['post_type'] ) ? sanitize_key( $arguments['post_type'] ) : 'post';

		$posts = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'numberposts'            => $limit,
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'suppress_filters'       => false,
				'no_found_rows'          => true,  // Performance: Skip counting total rows.
				'update_post_term_cache' => false, // Performance: Skip term cache if not needed.
				'update_post_meta_cache' => true,  // Keep meta cache for post data.
			)
		);

		$results = array();
		foreach ( $posts as $post ) {
			$results[] = array(
				'ID'        => $post->ID,
				'title'     => get_the_title( $post ),
				'permalink' => get_permalink( $post ),
				'excerpt'   => wp_trim_words( wp_strip_all_tags( $post->post_content ), 30 ),
				'date'      => get_the_date( DATE_W3C, $post ),
			);
		}

		$summary_text = sprintf(
			/* translators: 1: number of posts, 2: post type */
			__( 'Found %1$d recent %2$s', 'mcp-ai-wpoos' ),
			count( $results ),
			$post_type
		);

		return array(
			'message' => $summary_text, // Chat client display
			'summary' => $summary_text, // Backward compatibility
			'posts'   => $results,
			'count'   => count( $results ),
		);
	}


	/**

	 * Get extended tool definition including toolkit metadata.

	 *

	 * @since 1.1.0

	 *

	 * @return array Tool definition with metadata.

	 */

	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'content_publishing',

			'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer' ),

			'profession_tags'       => array( 'writer', 'content_creator', 'editor' ),

			'risk_level'            => 'info',

		);

	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires 'read' capability.
			'cacheable',            // Results can be cached.
		);
	}
}
