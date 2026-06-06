<?php
/**
 * Tool that returns recent WordPress posts.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-relevance-search.php';

/**
 * Returns a list of recent posts with optional search and TF-IDF ranking.
 *
 * @since 2.4.0 Added search, orderby, order params with TF-IDF relevance.
 */
class WP_MCP_AI_Tool_Get_Recent_Posts implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Relevance_Search;

	/**
	 * Allowed orderby values for recent posts.
	 *
	 * @since 2.4.0
	 * @var string[]
	 */
	const ORDERBY_OPTIONS = array( 'relevance', 'date', 'title' );

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
		return __( 'Returns recent published posts with optional keyword search, configurable sort order (date, title), and TF-IDF relevance ranking via orderby=relevance.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		$settings  = get_option( 'wp_mcp_ai_settings', array() );
		$max_limit = isset( $settings['query_posts_limit'] ) && $settings['query_posts_limit'] > 0 ? absint( $settings['query_posts_limit'] ) : 50;
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'limit'     => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of posts to return.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => $max_limit,
					'default'     => 5,
				),
				'post_type' => array(
					'type'        => 'string',
					'description' => __( 'The post type to query.', 'mcp-ai-wpoos' ),
					'default'     => 'post',
				),
				'search'    => array(
					'type'        => 'string',
					'description' => __( 'Free-text search query. When combined with orderby=relevance, results are ranked by TF-IDF scoring.', 'mcp-ai-wpoos' ),
				),
				'orderby'   => array(
					'type'        => 'string',
					'enum'        => self::ORDERBY_OPTIONS,
					'description' => __( 'Sort results by this field. Use "relevance" for TF-IDF scored results (requires search).', 'mcp-ai-wpoos' ),
					'default'     => 'date',
				),
				'order'     => array(
					'type'        => 'string',
					'enum'        => array( 'ASC', 'DESC' ),
					'description' => __( 'Sort direction: ASC (ascending) or DESC (descending).', 'mcp-ai-wpoos' ),
					'default'     => 'DESC',
				),
			),
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

		$settings  = get_option( 'wp_mcp_ai_settings', array() );
		$max_limit = isset( $settings['query_posts_limit'] ) && $settings['query_posts_limit'] > 0 ? absint( $settings['query_posts_limit'] ) : 50;
		$limit     = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 5;
		$limit     = $limit > 0 ? min( $limit, $max_limit ) : 5;
		$post_type = isset( $arguments['post_type'] ) ? sanitize_key( $arguments['post_type'] ) : 'post';

		$search       = isset( $arguments['search'] ) ? sanitize_text_field( $arguments['search'] ) : '';
		$orderby      = $this->sanitise_orderby(
			isset( $arguments['orderby'] ) ? $arguments['orderby'] : 'date',
			'date',
			self::ORDERBY_OPTIONS
		);
		$order        = isset( $arguments['order'] ) && 'ASC' === strtoupper( $arguments['order'] ) ? 'ASC' : 'DESC';
		$algorithm    = isset( $arguments['search_algorithm'] ) && 'bm25' === $arguments['search_algorithm'] ? 'bm25' : 'tfidf';
		$is_relevance = ( 'relevance' === $orderby && '' !== $search );

		$posts = get_posts(
			array(
				'post_type'              => $post_type,
				'post_status'            => 'publish',
				'numberposts'            => $is_relevance ? 500 : $limit,
				'suppress_filters'       => false,
				'no_found_rows'          => ! $is_relevance,
				'update_post_term_cache' => false,
				'update_post_meta_cache' => true,
			)
		);

		// Apply search term for non-relevance mode (relevance handles it post-query).
		if ( ! $is_relevance && '' !== $search ) {
			$posts = array_filter(
				$posts,
				function ( $post ) use ( $search ) {
					$content = strtolower( $post->post_title . ' ' . wp_strip_all_tags( $post->post_content ) );
					return false !== strpos( $content, strtolower( $search ) );
				}
			);
			$posts = array_slice( $posts, 0, $limit );
		}

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

		// Apply TF-IDF relevance ranking when orderby=relevance with a search term.
		if ( $is_relevance ) {
			$field_weights = array(
				'title'   => 3.0,
				'content' => 2.0,
				'excerpt' => 1.0,
			);
			$results = $this->rank_by_relevance( $results, $search, $field_weights, $algorithm );
			$results = array_slice( $results, 0, $limit );
		}

		$summary_text = sprintf(
			/* translators: 1: number of posts, 2: post type */
			__( 'Found %1$d recent %2$s', 'mcp-ai-wpoos' ),
			count( $results ),
			$post_type
		);

		return array(
			'message' => $summary_text, // Chat client display.
			'summary' => $summary_text, // Backward compatibility.
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
