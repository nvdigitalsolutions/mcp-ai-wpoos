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
class WP_MCP_AI_Tool_Get_Recent_Posts implements WP_MCP_AI_Tool_Interface {
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
		return __( 'Get Recent Posts', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns the most recent published posts, including titles and permalinks.', 'wp-mcp-ai' );
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
					'description' => __( 'Maximum number of posts to return.', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 5,
				),
				'post_type' => array(
					'type'        => 'string',
					'description' => __( 'The post type to query.', 'wp-mcp-ai' ),
					'default'     => 'post',
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view recent posts.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$limit     = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 5;
		$limit     = $limit > 0 ? min( $limit, 50 ) : 5;
		$post_type = isset( $arguments['post_type'] ) ? sanitize_key( $arguments['post_type'] ) : 'post';

		$posts = get_posts(
			array(
				'post_type'        => $post_type,
				'post_status'      => 'publish',
				'numberposts'      => $limit,
				'orderby'          => 'date',
				'order'            => 'DESC',
				'suppress_filters' => false,
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

		return $results;
	}
}
