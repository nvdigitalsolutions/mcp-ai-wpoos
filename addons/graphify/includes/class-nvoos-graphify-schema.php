<?php
/**
 * Graphify Schema.org — injects JSON‑LD structured data from the knowledge graph.
 *
 * @package NV_oOS_Graphify
 * @since   0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Injects Schema.org JSON‑LD into wp_head for singular posts that have
 * a node in the knowledge graph.
 *
 * @since 0.4.0
 */
class NV_oOS_Graphify_Schema {

	/**
	 * Hook into wp_head.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'inject_schema' ) );
	}

	/**
	 * Output Schema.org JSON‑LD on singular views when conditions are met.
	 *
	 * @since 0.4.0
	 *
	 * @return void
	 */
	public static function inject_schema() {
		if ( ! is_singular() ) {
			return;
		}

		if ( ! class_exists( 'NV_oOS_Graphify' ) ) {
			return;
		}

		$settings = NV_oOS_Graphify::get_settings();

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		if ( empty( $settings['inject_schema'] ) ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		$schema = self::get_post_schema_data( $post_id );
		if ( empty( $schema ) ) {
			return;
		}

		$json = wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( ! $json ) {
			return;
		}

		echo '<script type="application/ld+json">' . $json . '</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- pre-encoded via wp_json_encode; ld+json is not user-rendered HTML.
	}

	/**
	 * Build Schema.org data for a post from its knowledge‑graph neighbours.
	 *
	 * @since 0.4.0
	 *
	 * @param int $post_id WordPress post ID.
	 * @return array Schema.org associative array, or empty array when the post has no graph node.
	 */
	public static function get_post_schema_data( $post_id ) {
		global $wpdb;

		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return array();
		}

		$graph_id    = class_exists( 'NV_oOS_Graphify' ) ? NV_oOS_Graphify::get_instance()->get_graph_id() : 1;
		$node_id     = 'post_' . $post_id;
		$nodes_table = $wpdb->prefix . 'nvoos_graph_nodes';
		$edges_table = $wpdb->prefix . 'nvoos_graph_edges';

		// Verify the post has a node.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$node = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT node_id FROM {$nodes_table} WHERE graph_id = %d AND node_id = %s",
				$graph_id,
				$node_id
			),
			ARRAY_A
		);

		if ( ! $node ) {
			return array();
		}

		// Fetch neighbours with their types.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$neighbours = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT n.node_id, n.label, n.node_type, n.source_id, e.relation
				FROM {$edges_table} AS e
				INNER JOIN {$nodes_table} AS n
					ON n.graph_id = e.graph_id
					AND n.node_id = CASE
						WHEN e.source_node_id = %s THEN e.target_node_id
						ELSE e.source_node_id
					END
				WHERE e.graph_id = %d
					AND ( e.source_node_id = %s OR e.target_node_id = %s )",
				$node_id,
				$graph_id,
				$node_id,
				$node_id
			),
			ARRAY_A
		);

		$about         = array();
		$related_links = array();

		if ( is_array( $neighbours ) ) {
			foreach ( $neighbours as $neighbour ) {
				$type = isset( $neighbour['node_type'] ) ? $neighbour['node_type'] : '';

				if ( 'taxonomy_term' === $type ) {
					$about[] = array(
						'@type' => 'Thing',
						'name'  => sanitize_text_field( $neighbour['label'] ),
					);
				}

				$linkable_types = array( 'post', 'page' );
				if ( in_array( $type, $linkable_types, true ) ) {
					$source_id = absint( $neighbour['source_id'] );
					if ( $source_id ) {
						$permalink = get_permalink( $source_id );
						if ( $permalink ) {
							$related_links[] = esc_url( $permalink );
						}
					}
				}
			}
		}

		$post       = get_post( $post_id );
		$post_title = $post ? get_the_title( $post ) : '';
		$post_url   = get_permalink( $post_id );

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'WebPage',
			'name'     => sanitize_text_field( $post_title ),
			'url'      => esc_url( $post_url ),
		);

		if ( ! empty( $about ) ) {
			$schema['about'] = $about;
		}

		if ( ! empty( $related_links ) ) {
			$schema['relatedLink'] = array_values( array_unique( $related_links ) );
		}

		$schema['isPartOf'] = array(
			'@type' => 'WebSite',
			'name'  => sanitize_text_field( get_bloginfo( 'name' ) ),
			'url'   => esc_url( home_url( '/' ) ),
		);

		return $schema;
	}
}
