<?php
/**
 * NV oOS Graphify — WooCommerce Remote Driver (base)
 *
 * Local-DB driver that ingests WooCommerce products (and product
 * categories / tags) as graph nodes plus their relationships as edges:
 *
 *   product  IN_CATEGORY  product_cat
 *   product  TAGGED_WITH  product_tag
 *
 * No remote API calls — reads the local WordPress database. Activated only
 * when WooCommerce itself is loaded (class WooCommerce / function wc_get_products).
 *
 * @package NV_oOS_Graphify
 * @since   0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WooCommerce-as-a-graph-source remote driver.
 *
 * @since 0.7.0
 */
class NV_oOS_Graphify_Remote_WooCommerce extends NV_oOS_Graphify_Remote_Source_Base {

	/** {@inheritdoc} */
	public function get_driver_id() {
		return 'woocommerce';
	}

	/** {@inheritdoc} */
	public function get_driver_label() {
		return __( 'WooCommerce (local)', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_capabilities() {
		return array( 'fetch_nodes', 'fetch_edges' );
	}

	/** {@inheritdoc} */
	public function get_capability_flags() {
		return array(
			'supports_incremental'   => true,
			'supports_webhooks'      => false,
			'supports_oauth'         => false,
			'supports_pagination'    => true,
			'supports_relationships' => true,
		);
	}

	/** {@inheritdoc} */
	public function get_config_schema() {
		return array(
			'product_status' => array(
				'type'        => 'text',
				'label'       => __( 'Product Status', 'nvoos-graphify' ),
				'description' => __( 'Comma-separated WooCommerce product statuses to include (e.g. "publish,draft").', 'nvoos-graphify' ),
				'default'     => 'publish',
			),
			'max_items'      => array(
				'type'        => 'number',
				'label'       => __( 'Max Products Per Sync', 'nvoos-graphify' ),
				'description' => __( 'Maximum number of products to ingest per sync run.', 'nvoos-graphify' ),
				'default'     => 200,
			),
		);
	}

	/** {@inheritdoc} */
	public function test_connection() {
		if ( ! $this->is_woocommerce_active() ) {
			return array(
				'success' => false,
				'message' => __( 'WooCommerce is not active on this site.', 'nvoos-graphify' ),
			);
		}
		return array(
			'success' => true,
			'message' => __( 'WooCommerce detected.', 'nvoos-graphify' ),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $args Optional fetch arguments (limit override).
	 */
	public function fetch_nodes( array $args = array() ) {
		if ( ! $this->is_woocommerce_active() ) {
			return array();
		}
		$slug      = $this->get_slug();
		$max_items = $this->resolve_limit( $args );
		$statuses  = $this->resolve_statuses();

		$nodes = array();

		// 1. Product category terms.
		$cats = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
				'number'     => $max_items,
			)
		);
		if ( ! is_wp_error( $cats ) ) {
			foreach ( $cats as $term ) {
				$nodes[] = $this->term_to_node( $term, 'product_category', $slug );
			}
		}

		// 2. Product tag terms.
		$tags = get_terms(
			array(
				'taxonomy'   => 'product_tag',
				'hide_empty' => false,
				'number'     => $max_items,
			)
		);
		if ( ! is_wp_error( $tags ) ) {
			foreach ( $tags as $term ) {
				$nodes[] = $this->term_to_node( $term, 'product_tag', $slug );
			}
		}

		// 3. Products.
		$products = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => $statuses,
				'numberposts'      => $max_items,
				'suppress_filters' => false,
			)
		);
		foreach ( $products as $product_post ) {
			$nodes[] = $this->product_to_node( $product_post, $slug );
		}

		return $nodes;
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $args Optional fetch arguments (limit override).
	 */
	public function fetch_edges( array $args = array() ) {
		if ( ! $this->is_woocommerce_active() ) {
			return array();
		}
		$slug      = $this->get_slug();
		$max_items = $this->resolve_limit( $args );
		$statuses  = $this->resolve_statuses();

		$edges = array();

		$products = get_posts(
			array(
				'post_type'        => 'product',
				'post_status'      => $statuses,
				'numberposts'      => $max_items,
				'suppress_filters' => false,
			)
		);
		foreach ( $products as $product_post ) {
			$product_node_id = $this->product_node_id( (int) $product_post->ID, $slug );

			$cat_terms = wp_get_post_terms( (int) $product_post->ID, 'product_cat' );
			if ( ! is_wp_error( $cat_terms ) ) {
				foreach ( $cat_terms as $term ) {
					$edges[] = array(
						'source_node_id' => $product_node_id,
						'target_node_id' => $this->term_node_id( (int) $term->term_id, 'product_category', $slug ),
						'relation'       => 'IN_CATEGORY',
						'confidence'     => 1.0,
						'provenance'     => 'IMPORTED',
						'source_slug'    => $slug,
					);
				}
			}

			$tag_terms = wp_get_post_terms( (int) $product_post->ID, 'product_tag' );
			if ( ! is_wp_error( $tag_terms ) ) {
				foreach ( $tag_terms as $term ) {
					$edges[] = array(
						'source_node_id' => $product_node_id,
						'target_node_id' => $this->term_node_id( (int) $term->term_id, 'product_tag', $slug ),
						'relation'       => 'TAGGED_WITH',
						'confidence'     => 1.0,
						'provenance'     => 'IMPORTED',
						'source_slug'    => $slug,
					);
				}
			}
		}

		return $edges;
	}

	/**
	 * Build a graph node array from a WooCommerce product post.
	 *
	 * @param WP_Post $product_post Product post object.
	 * @param string  $slug         Source slug.
	 * @return array
	 */
	private function product_to_node( $product_post, $slug ) {
		$post_id    = (int) $product_post->ID;
		$node_id    = $this->product_node_id( $post_id, $slug );
		$properties = array(
			'post_id' => $post_id,
			'status'  => sanitize_text_field( $product_post->post_status ),
		);

		$sku = get_post_meta( $post_id, '_sku', true );
		if ( '' !== (string) $sku ) {
			$properties['sku'] = sanitize_text_field( (string) $sku );
		}
		$price = get_post_meta( $post_id, '_price', true );
		if ( '' !== (string) $price ) {
			$properties['price'] = sanitize_text_field( (string) $price );
		}

		return array(
			'node_id'     => $node_id,
			'label'       => sanitize_text_field( get_the_title( $post_id ) ),
			'type'        => 'product',
			'post_id'     => $post_id,
			'url'         => esc_url_raw( (string) get_permalink( $post_id ) ),
			'properties'  => $properties,
			'external_id' => 'wc:product:' . $post_id,
			'source_slug' => $slug,
			'confidence'  => 1.0,
		);
	}

	/**
	 * Build a graph node array from a WooCommerce taxonomy term.
	 *
	 * @param WP_Term $term Term object.
	 * @param string  $type Node type.
	 * @param string  $slug Source slug.
	 * @return array
	 */
	private function term_to_node( $term, $type, $slug ) {
		$node_id = $this->term_node_id( (int) $term->term_id, $type, $slug );
		return array(
			'node_id'     => $node_id,
			'label'       => sanitize_text_field( $term->name ),
			'type'        => sanitize_key( $type ),
			'post_id'     => 0,
			'url'         => esc_url_raw( (string) get_term_link( $term ) ),
			'properties'  => array(
				'term_id'  => (int) $term->term_id,
				'taxonomy' => sanitize_key( $term->taxonomy ),
			),
			'external_id' => 'wc:' . sanitize_key( $type ) . ':' . (int) $term->term_id,
			'source_slug' => $slug,
			'confidence'  => 1.0,
		);
	}

	/**
	 * Build the node_id for a product.
	 *
	 * @param int    $post_id Product post ID.
	 * @param string $slug    Source slug.
	 * @return string
	 */
	private function product_node_id( $post_id, $slug ) {
		return 'remote_' . sanitize_key( $slug ) . '_product_' . absint( $post_id );
	}

	/**
	 * Build the node_id for a taxonomy term.
	 *
	 * @param int    $term_id Term ID.
	 * @param string $type    Node type ('product_category' / 'product_tag').
	 * @param string $slug    Source slug.
	 * @return string
	 */
	private function term_node_id( $term_id, $type, $slug ) {
		return 'remote_' . sanitize_key( $slug ) . '_' . sanitize_key( $type ) . '_' . absint( $term_id );
	}

	/**
	 * Resolve the configured per-sync limit, with arg override.
	 *
	 * @param array $args Optional fetch args.
	 * @return int
	 */
	private function resolve_limit( array $args ) {
		if ( isset( $args['limit'] ) ) {
			return max( 1, min( 1000, absint( $args['limit'] ) ) );
		}
		$max = isset( $this->config['max_items'] ) ? absint( $this->config['max_items'] ) : 200;
		return max( 1, min( 1000, $max ) );
	}

	/**
	 * Resolve the configured product status list.
	 *
	 * @return string[]
	 */
	private function resolve_statuses() {
		$raw = isset( $this->config['product_status'] ) ? (string) $this->config['product_status'] : 'publish';
		$out = array();
		foreach ( explode( ',', $raw ) as $candidate ) {
			$candidate = sanitize_key( trim( $candidate ) );
			if ( '' !== $candidate ) {
				$out[] = $candidate;
			}
		}
		return empty( $out ) ? array( 'publish' ) : $out;
	}

	/**
	 * Whether WooCommerce is active.
	 *
	 * @return bool
	 */
	private function is_woocommerce_active() {
		return class_exists( 'WooCommerce' ) || function_exists( 'wc_get_products' );
	}
}
