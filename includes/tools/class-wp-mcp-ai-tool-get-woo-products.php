<?php
/**
 * Tool returning WooCommerce products.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

/**
 * Provides WooCommerce product listings with core merchandising metadata.
 */
class WP_MCP_AI_Tool_Get_Woo_Products implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Determine whether WooCommerce is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return class_exists( 'WooCommerce' ) && function_exists( 'wc_get_products' );
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'The WooCommerce Products tool is disabled because WooCommerce is not active.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_woo_products';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get WooCommerce Products', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns WooCommerce catalog products with pricing and stock details. Requires WooCommerce.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'limit'        => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of products to retrieve.', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 20,
					'default'     => 5,
				),
				'sku'          => array(
					'type'        => 'string',
					'description' => __( 'Optional product SKU to filter by.', 'wp-mcp-ai' ),
				),
				'status'       => array(
					'type'        => 'string',
					'description' => __( 'Optional product status to filter by (e.g. publish, draft).', 'wp-mcp-ai' ),
				),
				'stock_status' => array(
					'type'        => 'string',
					'description' => __( 'Optional stock status to filter by (e.g. instock, outofstock).', 'wp-mcp-ai' ),
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
		if ( ! self::is_available() ) {
			return new WP_Error( 'wp_mcp_ai_woo_missing', __( 'WooCommerce is not active on this site.', 'wp-mcp-ai' ) );
		}

		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to view WooCommerce products.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		if ( ! user_can( $user_id, 'manage_woocommerce' ) && ! user_can( $user_id, 'view_woocommerce_reports' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view WooCommerce products.', 'wp-mcp-ai' ) );
		}

		$limit = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 5;
		$limit = $limit > 0 ? min( $limit, 20 ) : 5;

		$args = array(
			'limit'   => $limit,
			'orderby' => 'date',
			'order'   => 'DESC',
			'return'  => 'objects',
		);

		if ( ! empty( $arguments['status'] ) ) {
			$args['status'] = sanitize_key( $arguments['status'] );
		}

		if ( ! empty( $arguments['stock_status'] ) ) {
			$args['stock_status'] = sanitize_key( $arguments['stock_status'] );
		}

		if ( ! empty( $arguments['sku'] ) ) {
			$sku = is_string( $arguments['sku'] ) ? $arguments['sku'] : '';

			if ( function_exists( 'wc_clean' ) ) {
				$args['sku'] = wc_clean( $sku );
			} else {
				$args['sku'] = sanitize_text_field( $sku );
			}
		}

		$products = wc_get_products( $args );

		$results = array();

		foreach ( $products as $product ) {
			if ( ! $product || ! is_object( $product ) ) {
				continue;
			}

			/** @var WC_Product $product */
			$results[] = array(
				'id'             => $product->get_id(),
				'name'           => $product->get_name(),
				'sku'            => $product->get_sku(),
				'type'           => $product->get_type(),
				'status'         => $product->get_status(),
				'price'          => $product->get_price(),
				'regular_price'  => $product->get_regular_price(),
				'sale_price'     => $product->get_sale_price(),
				'stock_status'   => $product->get_stock_status(),
				'stock_quantity' => $product->get_stock_quantity(),
				'manage_stock'   => method_exists( $product, 'get_manage_stock' ) ? $product->get_manage_stock() : null,
				'permalink'      => method_exists( $product, 'get_permalink' ) ? $product->get_permalink() : '',
				'date_created'   => method_exists( $product, 'get_date_created' ) && $product->get_date_created() ? gmdate( DATE_W3C, $product->get_date_created()->getTimestamp() ) : null,
				'date_modified'  => method_exists( $product, 'get_date_modified' ) && $product->get_date_modified() ? gmdate( DATE_W3C, $product->get_date_modified()->getTimestamp() ) : null,
			);
		}

		return array(
			'summary'  => sprintf(
				/* translators: %d: number of products */
				__( 'Found %d product(s)', 'wp-mcp-ai' ),
				count( $results )
			),
			'products' => $results,
			'count'    => count( $results ),
		);
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
