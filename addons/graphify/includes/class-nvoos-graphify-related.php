<?php
/**
 * Graphify Related Content — appends graph‑based related posts to singular views.
 *
 * @package NV_oOS_Graphify
 * @since   0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filters the_content on singular views to append a list of related posts
 * derived from knowledge‑graph neighbours.
 *
 * @since 0.4.0
 */
class NV_oOS_Graphify_Related {

	/**
	 * Hook into the_content at a late priority.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'the_content', array( __CLASS__, 'append_related_content' ), 99 );
	}

	/**
	 * Append a related‑content section to singular post content.
	 *
	 * @since 0.4.0
	 *
	 * @param string $content Existing post content.
	 * @return string Content with related section appended, or unchanged content.
	 */
	public static function append_related_content( $content ) {
		if ( ! is_singular() ) {
			return $content;
		}

		if ( ! is_main_query() || ! in_the_loop() ) {
			return $content;
		}

		if ( ! class_exists( 'NV_oOS_Graphify' ) ) {
			return $content;
		}

		$settings = NV_oOS_Graphify::get_settings();

		if ( empty( $settings['enabled'] ) ) {
			return $content;
		}

		if ( empty( $settings['show_related'] ) ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $content;
		}

		$related = self::get_related_posts( $post_id );
		if ( empty( $related ) ) {
			return $content;
		}

		$html  = '<div class="nvoos-graphify-related">';
		$html .= '<h3>' . esc_html__( 'Related Content', 'mcp-ai-wpoos' ) . '</h3>';
		$html .= '<ul>';

		foreach ( $related as $item ) {
			$html .= '<li><a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['title'] ) . '</a></li>';
		}

		$html .= '</ul>';
		$html .= '</div>';

		return $content . $html;
	}

	/**
	 * Retrieve related posts from the knowledge graph.
	 *
	 * Returns up to 5 neighbouring post/page nodes sorted by degree descending.
	 *
	 * @since 0.4.0
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array List of arrays with 'title' and 'url' keys.
	 */
	private static function get_related_posts( $post_id ) {
		global $wpdb;

		$post_id     = absint( $post_id );
		$graph_id    = class_exists( 'NV_oOS_Graphify' ) ? NV_oOS_Graphify::get_instance()->get_graph_id() : 1;
		$node_id     = 'post_' . $post_id;
		$nodes_table = $wpdb->prefix . 'nvoos_graph_nodes';
		$edges_table = $wpdb->prefix . 'nvoos_graph_edges';

		// Verify the post has a node.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT node_id FROM {$nodes_table} WHERE graph_id = %d AND node_id = %s LIMIT 1",
				$graph_id,
				$node_id
			)
		);

		if ( ! $exists ) {
			return array();
		}

		// Fetch post/page neighbours ordered by degree.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$neighbours = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT n.source_id, n.label, n.degree
				FROM {$edges_table} AS e
				INNER JOIN {$nodes_table} AS n
					ON n.graph_id = e.graph_id
					AND n.node_id = CASE
						WHEN e.source_node_id = %s THEN e.target_node_id
						ELSE e.source_node_id
					END
				WHERE e.graph_id = %d
					AND ( e.source_node_id = %s OR e.target_node_id = %s )
					AND n.node_type IN ( 'post', 'page' )
					AND n.source_id != %d
				ORDER BY n.degree DESC
				LIMIT 5",
				$node_id,
				$graph_id,
				$node_id,
				$node_id,
				$post_id
			),
			ARRAY_A
		);

		if ( ! is_array( $neighbours ) ) {
			return array();
		}

		$related = array();
		foreach ( $neighbours as $row ) {
			$source_id = absint( $row['source_id'] );
			if ( ! $source_id ) {
				continue;
			}

			$permalink = get_permalink( $source_id );
			if ( ! $permalink ) {
				continue;
			}

			$title = get_the_title( $source_id );
			if ( ! $title ) {
				continue;
			}

			$related[] = array(
				'title' => $title,
				'url'   => $permalink,
			);
		}

		return $related;
	}
}
