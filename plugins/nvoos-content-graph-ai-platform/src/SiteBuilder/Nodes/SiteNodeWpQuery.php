<?php
/**
 * Site Node: WP Query Source (Wave E4, sub-cluster 5).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Site_Node_WP_Query`:
 * byte-identical slug/metadata, the five input port definitions
 * (post_type, posts_per_page, orderby, order, category_slug), the
 * `post_list` output, and the WP_Query execution with the 100-post cap,
 * the ASC/DESC whitelist, the category-name narrowing, and the
 * seven-field post summary shape.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - Class name/namespace — the platform's PSR-4 tree.
 *  - `WP_Query` is fully qualified (`\WP_Query`).
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\SiteBuilder\Nodes
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SiteBuilder\Nodes;

use NvoosContentGraphAiPlatform\SiteBuilder\SiteNodeInterface;

/**
 * Source node that runs WP_Query and returns a list of post summaries.
 *
 * Category: source
 * Inputs:  post_type, posts_per_page, orderby, order, category_slug
 * Outputs: posts (post_list) — an array of { id, title, excerpt, permalink, thumbnail_url }
 *
 * @since 2.1.0
 */
class SiteNodeWpQuery implements SiteNodeInterface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug(): string {
		return 'wp_query_source';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'WP Query', 'nvoos-content-graph-ai-platform' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Fetch WordPress posts using WP_Query. Connect the output to a layout node.', 'nvoos-content-graph-ai-platform' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_category(): string {
		return 'source';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_inputs(): array {
		return array(
			array(
				'name'     => 'post_type',
				'type'     => 'string',
				'label'    => __( 'Post Type', 'nvoos-content-graph-ai-platform' ),
				'required' => false,
				'default'  => 'post',
			),
			array(
				'name'     => 'posts_per_page',
				'type'     => 'number',
				'label'    => __( 'Posts Per Page', 'nvoos-content-graph-ai-platform' ),
				'required' => false,
				'default'  => 10,
			),
			array(
				'name'     => 'orderby',
				'type'     => 'string',
				'label'    => __( 'Order By', 'nvoos-content-graph-ai-platform' ),
				'required' => false,
				'default'  => 'date',
			),
			array(
				'name'     => 'order',
				'type'     => 'string',
				'label'    => __( 'Order', 'nvoos-content-graph-ai-platform' ),
				'required' => false,
				'default'  => 'DESC',
			),
			array(
				'name'     => 'category_slug',
				'type'     => 'string',
				'label'    => __( 'Category Slug', 'nvoos-content-graph-ai-platform' ),
				'required' => false,
				'default'  => '',
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_outputs(): array {
		return array(
			array(
				'name'  => 'posts',
				'type'  => 'post_list',
				'label' => __( 'Posts', 'nvoos-content-graph-ai-platform' ),
			),
		);
	}

	/**
	 * Execute: run WP_Query and return post summaries.
	 *
	 * {@inheritdoc}
	 *
	 * @param array $inputs Node input values keyed by input name.
	 */
	public function execute( array $inputs ) {
		$post_type      = isset( $inputs['post_type'] ) ? sanitize_text_field( $inputs['post_type'] ) : 'post';
		$posts_per_page = isset( $inputs['posts_per_page'] ) ? min( absint( $inputs['posts_per_page'] ), 100 ) : 10;
		$orderby        = isset( $inputs['orderby'] ) ? sanitize_text_field( $inputs['orderby'] ) : 'date';
		$order          = isset( $inputs['order'] ) ? strtoupper( sanitize_text_field( $inputs['order'] ) ) : 'DESC';
		$category_slug  = isset( $inputs['category_slug'] ) ? sanitize_text_field( $inputs['category_slug'] ) : '';

		if ( ! in_array( $order, array( 'ASC', 'DESC' ), true ) ) {
			$order = 'DESC';
		}

		$query_args = array(
			'post_type'      => $post_type,
			'posts_per_page' => $posts_per_page,
			'orderby'        => $orderby,
			'order'          => $order,
			'post_status'    => 'publish',
			'no_found_rows'  => true,
		);

		if ( '' !== $category_slug ) {
			$query_args['category_name'] = $category_slug;
		}

		$query  = new \WP_Query( $query_args );
		$result = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$post_id   = get_the_ID();
				$thumbnail = get_the_post_thumbnail_url( $post_id, 'medium' );

				$result[] = array(
					'id'            => $post_id,
					'title'         => get_the_title(),
					'excerpt'       => wp_kses_post( get_the_excerpt() ),
					'permalink'     => esc_url( get_permalink() ),
					'thumbnail_url' => $thumbnail ? esc_url( $thumbnail ) : '',
					'post_date'     => esc_html( get_the_date() ),
					'author_name'   => esc_html( get_the_author() ),
				);
			}
			wp_reset_postdata();
		}

		return array(
			'posts' => $result,
		);
	}
}
