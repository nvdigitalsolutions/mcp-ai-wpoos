<?php
declare(strict_types=1);

namespace NvoosGraphify\Remote\Drivers;

use NvoosGraphify\Contracts\RemoteSource;
use function absint;
use function class_exists;
use function md5;
use function sanitize_key;
use function sanitize_text_field;

/**
 * WooCommerce local-DB driver.
 *
 * Ingests WooCommerce products, categories, and tags as graph nodes
 * plus their relationships as edges. No remote API calls — reads the
 * local WordPress database.
 *
 * @since 1.0.0
 */
class WooCommerce implements RemoteSource {

	/** @var array<string,mixed> Driver configuration. */
	private array $config = array();

	public function getDriverId(): string {
		return 'woocommerce';
	}

	public function getDriverLabel(): string {
		return __( 'WooCommerce (local)', 'nvoos-graphify' );
	}

	public function setConfig( array $config ): void {
		$this->config = $config;
	}

	public function getConfig(): array {
		return $this->config;
	}

	public function getCapabilities(): array {
		return array( 'fetch_nodes', 'fetch_edges' );
	}

	public function getConfigSchema(): array {
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

	public function testConnection(): array {
		if ( ! $this->isWooCommerceActive() ) {
			return array(
				'success' => false,
				'message' => __( 'WooCommerce is not active on this site.', 'nvoos-graphify' ),
			);
		}

		return array(
			'success' => true,
			'message' => __( 'WooCommerce is active. Ready to ingest products.', 'nvoos-graphify' ),
		);
	}

	public function discover(): array {
		return array(
			'driver'       => $this->getDriverId(),
			'label'        => $this->getDriverLabel(),
			'capabilities' => $this->getCapabilities(),
		);
	}

	public function fetchNodes( array $args = array() ): array {
		if ( ! $this->isWooCommerceActive() ) {
			return array();
		}

		$maxItems   = absint( $this->config['max_items'] ?? 200 );
		$statusStr  = sanitize_text_field( (string) ( $this->config['product_status'] ?? 'publish' ) );
		$statuses   = array_filter( array_map( 'trim', explode( ',', $statusStr ) ) );
		$sourceSlug = $this->config['_slug'] ?? 'woocommerce';

		$products = wc_get_products(
			array(
				'limit'  => $maxItems ?: -1,
				'status' => $statuses ?: array( 'publish' ),
			)
		);

		$nodes = array();
		foreach ( $products as $product ) {
			$nodeId = 'wc_product_' . $product->get_id();

			$nodes[] = array(
				'node_id'     => $nodeId,
				'label'       => sanitize_text_field( $product->get_name() ),
				'type'        => 'product',
				'post_id'     => $product->get_id(),
				'url'         => \get_permalink( $product->get_id() ) ?: '',
				'properties'  => array(
					'price' => $product->get_price(),
					'sku'   => $product->get_sku(),
				),
				'source_slug' => $sourceSlug,
				'provenance'  => 'LOCAL',
			);
		}

		return $nodes;
	}

	public function fetchEdges( array $args = array() ): array {
		if ( ! $this->isWooCommerceActive() ) {
			return array();
		}

		$maxItems   = absint( $this->config['max_items'] ?? 200 );
		$statusStr  = sanitize_text_field( (string) ( $this->config['product_status'] ?? 'publish' ) );
		$statuses   = array_filter( array_map( 'trim', explode( ',', $statusStr ) ) );
		$sourceSlug = $this->config['_slug'] ?? 'woocommerce';

		$products = wc_get_products(
			array(
				'limit'  => $maxItems ?: -1,
				'status' => $statuses ?: array( 'publish' ),
			)
		);

		$edges = array();
		foreach ( $products as $product ) {
			$productId = $product->get_id();
			$nodeId    = 'wc_product_' . $productId;

			// Product → Category edges.
			$categoryIds = $product->get_category_ids();
			foreach ( $categoryIds as $catId ) {
				$term = \get_term( $catId, 'product_cat' );
				if ( $term && ! is_wp_error( $term ) ) {
					$edges[] = array(
						'source_node_id' => $nodeId,
						'target_node_id' => 'term_product_cat_' . $catId,
						'relation'       => 'IN_CATEGORY',
						'confidence'     => 1.0,
						'provenance'     => 'LOCAL',
						'source_slug'    => $sourceSlug,
					);
				}
			}

			// Product → Tag edges.
			$tagIds = $product->get_tag_ids();
			foreach ( $tagIds as $tagId ) {
				$term = \get_term( $tagId, 'product_tag' );
				if ( $term && ! is_wp_error( $term ) ) {
					$edges[] = array(
						'source_node_id' => $nodeId,
						'target_node_id' => 'term_product_tag_' . $tagId,
						'relation'       => 'TAGGED_WITH',
						'confidence'     => 1.0,
						'provenance'     => 'LOCAL',
						'source_slug'    => $sourceSlug,
					);
				}
			}
		}

		return $edges;
	}

	public function reconcile( $localNode ): array {
		return array(
			'external_id' => '',
			'confidence'  => 0.0,
			'matched'     => false,
		);
	}

	/**
	 * Check if WooCommerce is active and its API is available.
	 *
	 * @return bool
	 */
	private function isWooCommerceActive(): bool {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_products' );
	}
}
