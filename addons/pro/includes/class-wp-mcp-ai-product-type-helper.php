<?php
/**
 * Product Type Helper
 *
 * Utilities for detecting and handling all WooCommerce product types.
 * Supports simple, variable, grouped, external, subscription, bundle, and more.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product Type Helper class.
 *
 * Provides methods to detect product types and handle type-specific operations.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Product_Type_Helper {

	/**
	 * Get all supported product types.
	 *
	 * @return array Product types.
	 */
	public static function get_supported_types() {
		return array(
			'simple'      => __( 'Simple Product', 'mcp-ai-wpoos-pro' ),
			'variable'    => __( 'Variable Product', 'mcp-ai-wpoos-pro' ),
			'grouped'     => __( 'Grouped Product', 'mcp-ai-wpoos-pro' ),
			'external'    => __( 'External/Affiliate Product', 'mcp-ai-wpoos-pro' ),
			'subscription' => __( 'Subscription Product', 'mcp-ai-wpoos-pro' ),
			'variable-subscription' => __( 'Variable Subscription', 'mcp-ai-wpoos-pro' ),
			'bundle'      => __( 'Product Bundle', 'mcp-ai-wpoos-pro' ),
			'composite'   => __( 'Composite Product', 'mcp-ai-wpoos-pro' ),
			'booking'     => __( 'Booking Product', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Detect product type.
	 *
	 * @param WC_Product|int $product Product object or ID.
	 * @return string|false Product type or false.
	 */
	public static function get_product_type( $product ) {
		if ( is_numeric( $product ) ) {
			$product = wc_get_product( $product );
		}

		if ( ! $product || ! is_a( $product, 'WC_Product' ) ) {
			return false;
		}

		return $product->get_type();
	}

	/**
	 * Check if product is a specific type.
	 *
	 * @param WC_Product|int $product Product object or ID.
	 * @param string         $type Product type to check.
	 * @return bool True if product is of specified type.
	 */
	public static function is_product_type( $product, $type ) {
		$product_type = self::get_product_type( $product );
		return $product_type === $type;
	}

	/**
	 * Check if product supports inventory management.
	 *
	 * @param WC_Product $product Product object.
	 * @return bool True if supports inventory.
	 */
	public static function supports_inventory( $product ) {
		// External/affiliate products don't support inventory.
		if ( 'external' === $product->get_type() ) {
			return false;
		}

		// Variable products manage stock at variation level.
		if ( 'variable' === $product->get_type() || 'variable-subscription' === $product->get_type() ) {
			return true; // But check variations individually.
		}

		// All other types support inventory.
		return true;
	}

	/**
	 * Check if product has variations.
	 *
	 * @param WC_Product $product Product object.
	 * @return bool True if product has variations.
	 */
	public static function has_variations( $product ) {
		return in_array( $product->get_type(), array( 'variable', 'variable-subscription' ), true );
	}

	/**
	 * Get product variations.
	 *
	 * @param WC_Product_Variable $product Variable product object.
	 * @return array Variation objects.
	 */
	public static function get_variations( $product ) {
		if ( ! self::has_variations( $product ) ) {
			return array();
		}

		$variation_ids = $product->get_children();
		$variations    = array();

		foreach ( $variation_ids as $variation_id ) {
			$variation = wc_get_product( $variation_id );
			if ( $variation ) {
				$variations[] = $variation;
			}
		}

		return $variations;
	}

	/**
	 * Get grouped product children.
	 *
	 * @param WC_Product_Grouped $product Grouped product object.
	 * @return array Child product IDs.
	 */
	public static function get_grouped_children( $product ) {
		if ( 'grouped' !== $product->get_type() ) {
			return array();
		}

		return $product->get_children();
	}

	/**
	 * Check if product is a subscription.
	 *
	 * @param WC_Product $product Product object.
	 * @return bool True if subscription product.
	 */
	public static function is_subscription( $product ) {
		return in_array( $product->get_type(), array( 'subscription', 'variable-subscription' ), true );
	}

	/**
	 * Get subscription meta data.
	 *
	 * @param WC_Product $product Product object.
	 * @return array Subscription data.
	 */
	public static function get_subscription_meta( $product ) {
		if ( ! self::is_subscription( $product ) ) {
			return array();
		}

		return array(
			'period'         => $product->get_meta( '_subscription_period', true ),
			'period_interval' => $product->get_meta( '_subscription_period_interval', true ),
			'length'         => $product->get_meta( '_subscription_length', true ),
			'trial_length'   => $product->get_meta( '_subscription_trial_length', true ),
			'trial_period'   => $product->get_meta( '_subscription_trial_period', true ),
			'sign_up_fee'    => $product->get_meta( '_subscription_sign_up_fee', true ),
		);
	}

	/**
	 * Get stock quantity based on product type.
	 *
	 * @param WC_Product $product Product object.
	 * @return int|string Stock quantity or 'N/A'.
	 */
	public static function get_stock_quantity( $product ) {
		// External products don't have stock.
		if ( 'external' === $product->get_type() ) {
			return 'N/A';
		}

		// Variable products: sum of all variations.
		if ( self::has_variations( $product ) ) {
			$total_stock = 0;
			$variations  = self::get_variations( $product );

			foreach ( $variations as $variation ) {
				if ( $variation->managing_stock() ) {
					$total_stock += $variation->get_stock_quantity();
				}
			}

			return $total_stock;
		}

		// Simple, subscription, etc.
		if ( $product->managing_stock() ) {
			return $product->get_stock_quantity();
		}

		return 'N/A';
	}

	/**
	 * Update stock quantity based on product type.
	 *
	 * @param WC_Product $product Product object.
	 * @param int        $quantity New quantity.
	 * @param array      $params Additional parameters.
	 * @return bool|WP_Error True if updated, WP_Error on failure.
	 */
	public static function update_stock_quantity( $product, $quantity, $params = array() ) {
		// External products don't support stock management.
		if ( 'external' === $product->get_type() ) {
			return new WP_Error(
				'unsupported_type',
				__( 'External products do not support inventory management.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Variable products: update specific variation if provided.
		if ( self::has_variations( $product ) ) {
			if ( isset( $params['variation_id'] ) ) {
				$variation = wc_get_product( absint( $params['variation_id'] ) );
				if ( ! $variation ) {
					return new WP_Error(
						'invalid_variation',
						__( 'Invalid variation ID.', 'mcp-ai-wpoos-pro' )
					);
				}

				$variation->set_manage_stock( true );
				$variation->set_stock_quantity( absint( $quantity ) );
				$variation->save();

				return true;
			}

			// If no variation specified, update all variations proportionally.
			$variations = self::get_variations( $product );
			if ( empty( $variations ) ) {
				return new WP_Error(
					'no_variations',
					__( 'Variable product has no variations.', 'mcp-ai-wpoos-pro' )
				);
			}

			$qty_per_variation = absint( $quantity ) / count( $variations );

			foreach ( $variations as $variation ) {
				$variation->set_manage_stock( true );
				$variation->set_stock_quantity( $qty_per_variation );
				$variation->save();
			}

			return true;
		}

		// Simple, subscription, bundle, etc.
		$product->set_manage_stock( true );
		$product->set_stock_quantity( absint( $quantity ) );
		$product->save();

		return true;
	}

	/**
	 * Get product price based on type.
	 *
	 * @param WC_Product $product Product object.
	 * @return float|string Price or price range.
	 */
	public static function get_price( $product ) {
		// Variable products: price range.
		if ( self::has_variations( $product ) ) {
			$min_price = $product->get_variation_price( 'min' );
			$max_price = $product->get_variation_price( 'max' );

			if ( $min_price === $max_price ) {
				return wc_format_decimal( $min_price, 2 );
			}

			return sprintf(
				'%s - %s',
				wc_format_decimal( $min_price, 2 ),
				wc_format_decimal( $max_price, 2 )
			);
		}

		// All other types.
		return wc_format_decimal( $product->get_price(), 2 );
	}

	/**
	 * Check if product supports specific feature.
	 *
	 * @param WC_Product $product Product object.
	 * @param string     $feature Feature name.
	 * @return bool True if feature is supported.
	 */
	public static function supports_feature( $product, $feature ) {
		$type = $product->get_type();

		$features_by_type = array(
			'simple'      => array( 'price', 'stock', 'categories', 'tags', 'images', 'downloads' ),
			'variable'    => array( 'price', 'stock', 'categories', 'tags', 'images', 'variations', 'attributes' ),
			'grouped'     => array( 'price', 'categories', 'tags', 'images', 'children' ),
			'external'    => array( 'price', 'categories', 'tags', 'images', 'external_url' ),
			'subscription' => array( 'price', 'stock', 'categories', 'tags', 'images', 'recurring', 'trial' ),
			'bundle'      => array( 'price', 'stock', 'categories', 'tags', 'images', 'bundled_items' ),
		);

		if ( ! isset( $features_by_type[ $type ] ) ) {
			return false;
		}

		return in_array( $feature, $features_by_type[ $type ], true );
	}

	/**
	 * Get product type label.
	 *
	 * @param string $type Product type slug.
	 * @return string Product type label.
	 */
	public static function get_type_label( $type ) {
		$types = self::get_supported_types();
		return isset( $types[ $type ] ) ? $types[ $type ] : $type;
	}

	/**
	 * Validate product data based on type.
	 *
	 * @param array  $data Product data.
	 * @param string $type Product type.
	 * @return bool|WP_Error True if valid, WP_Error on failure.
	 */
	public static function validate_product_data( $data, $type ) {
		// External products require external URL.
		if ( 'external' === $type ) {
			if ( empty( $data['external_url'] ) ) {
				return new WP_Error(
					'missing_external_url',
					__( 'External products require an external URL.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		// Variable products require attributes.
		if ( in_array( $type, array( 'variable', 'variable-subscription' ), true ) ) {
			if ( empty( $data['attributes'] ) ) {
				return new WP_Error(
					'missing_attributes',
					__( 'Variable products require attributes.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		// Grouped products require children.
		if ( 'grouped' === $type ) {
			if ( empty( $data['children'] ) ) {
				return new WP_Error(
					'missing_children',
					__( 'Grouped products require child products.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		return true;
	}
}
