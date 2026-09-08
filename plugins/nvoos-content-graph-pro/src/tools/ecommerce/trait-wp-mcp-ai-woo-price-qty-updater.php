<?php
/**
 * WooCommerce Price & Quantity Update Trait (ecosystem port — Wave F2, e-commerce data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/ecommerce/trait-wp-mcp-ai-woo-price-qty-updater.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `WP_MCP_AI_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; path/URL/version constants resolve from
 * `NVOOS_CONTENT_GRAPH_PRO_*`. The init's four admin-page requires are
 * file-gated — the pages land with the F2 admin slice (wave-proof).
 *
 * @package NvoosContentGraphPro
 * @since 2.2.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( trait_exists( 'WP_MCP_AI_Woo_Price_Qty_Updater' ) ) {
	return;
}

/**
 * Shared WooCommerce price/quantity update helpers.
 *
 * @since 2.2.0
 */
trait WP_MCP_AI_Woo_Price_Qty_Updater {

	/**
	 * Resolve the product objects an update applies to for a given scope.
	 *
	 * @param WC_Product $product       Product being updated (parent or variation).
	 * @param string     $scope         One of: product, variations, all.
	 * @param string     $field_context 'price' or 'stock' — used for error wording.
	 * @return WC_Product[]|WP_Error Targets, or an error for invalid scopes.
	 */
	protected function resolve_update_targets( $product, $scope, $field_context = 'price' ) {
		$type = $product->get_type();

		// Variable parents have no own price/stock — everything lives on variations.
		if ( 'variable' === $type && 'product' === $scope ) {
			return new WP_Error(
				'invalid_scope',
				sprintf(
					/* translators: %s: price or stock */
					__( 'Variable products store their %s on variations. Use scope "variations" or "all" instead.', 'nvoos-content-graph-pro' ),
					$field_context
				)
			);
		}

		// Grouped parents have no own price/stock — children (simple products) do.
		if ( 'grouped' === $type && 'product' === $scope ) {
			return new WP_Error(
				'invalid_scope',
				sprintf(
					/* translators: %s: price or stock */
					__( 'Grouped products store their %s on child products. Use scope "all" instead.', 'nvoos-content-graph-pro' ),
					$field_context
				)
			);
		}

		switch ( $scope ) {
			case 'product':
				return array( $product );

			case 'variations':
				if ( 'variable' !== $type ) {
					return new WP_Error(
						'invalid_scope',
						__( 'Scope "variations" only applies to variable products.', 'nvoos-content-graph-pro' )
					);
				}
				return $this->get_child_products( $product );

			case 'all':
				if ( in_array( $type, array( 'variable', 'grouped' ), true ) ) {
					return $this->get_child_products( $product );
				}
				return array( $product );

			default:
				return new WP_Error(
					'invalid_scope',
					__( 'Invalid scope specified. Use "product", "variations", or "all".', 'nvoos-content-graph-pro' )
				);
		}
	}

	/**
	 * Load the child products of a variable or grouped product.
	 *
	 * @param WC_Product $product Parent product.
	 * @return WC_Product[]|WP_Error Child products, or an error when there are none.
	 */
	protected function get_child_products( $product ) {
		$children = array();

		foreach ( (array) $product->get_children() as $child_id ) {
			$child = wc_get_product( $child_id );
			if ( $child ) {
				$children[] = $child;
			}
		}

		if ( empty( $children ) ) {
			return new WP_Error(
				'no_children',
				__( 'Product has no child products to update.', 'nvoos-content-graph-pro' )
			);
		}

		return $children;
	}

	/**
	 * Normalise a price value using WooCommerce decimal formatting.
	 *
	 * @param mixed $value Raw price value.
	 * @return string Formatted decimal string.
	 */
	protected function normalise_price( $value ) {
		return wc_format_decimal( (string) $value );
	}

	/**
	 * Validate and apply price fields to a product (no save).
	 *
	 * @param WC_Product $product Product to update.
	 * @param array      $args    Arguments containing regular_price, sale_price,
	 *                            sale_date_from, sale_date_to, clear_sale.
	 * @param array      $changes Change log (by reference).
	 * @return true|WP_Error True when applied, or a WP_Error describing the failure.
	 */
	protected function apply_price_fields( $product, $args, &$changes ) {
		$changes = is_array( $changes ) ? $changes : array();

		$new_regular = null;
		if ( array_key_exists( 'regular_price', $args ) && '' !== (string) $args['regular_price'] ) {
			$new_regular = $this->normalise_price( $args['regular_price'] );

			if ( (float) $new_regular < 0 ) {
				return new WP_Error(
					'invalid_price',
					__( 'Regular price cannot be negative.', 'nvoos-content-graph-pro' )
				);
			}

			$product->set_regular_price( $new_regular );
			$changes['regular_price'] = $new_regular;
		}

		$clear_sale = ! empty( $args['clear_sale'] );

		if ( $clear_sale ) {
			$product->set_sale_price( '' );
			$product->set_date_on_sale_from( '' );
			$product->set_date_on_sale_to( '' );
			$changes['sale_price'] = '';
		} elseif ( array_key_exists( 'sale_price', $args ) ) {
			if ( '' !== (string) $args['sale_price'] ) {
				$new_sale = $this->normalise_price( $args['sale_price'] );

				if ( (float) $new_sale < 0 ) {
					return new WP_Error(
						'invalid_price',
						__( 'Sale price cannot be negative.', 'nvoos-content-graph-pro' )
					);
				}

				// Compare against the regular price that will be in effect.
				$effective_regular = ( null !== $new_regular )
					? (float) $new_regular
					: (float) $product->get_regular_price();

				if ( (float) $new_sale >= $effective_regular ) {
					return new WP_Error(
						'invalid_sale_price',
						__( 'Sale price must be lower than the regular price.', 'nvoos-content-graph-pro' )
					);
				}

				$product->set_sale_price( $new_sale );
				$changes['sale_price'] = $new_sale;
			} else {
				// An explicit empty string clears the sale price.
				$product->set_sale_price( '' );
				$changes['sale_price'] = '';
			}
		}

		// Validate sale dates before applying (independent of sale price).
		$sale_dates = $this->normalise_sale_dates( $args );
		if ( is_wp_error( $sale_dates ) ) {
			return $sale_dates;
		}

		if ( '' !== $sale_dates['from'] ) {
			$product->set_date_on_sale_from( $sale_dates['from'] );
			$changes['sale_date_from'] = $sale_dates['from'];
		}

		if ( '' !== $sale_dates['to'] ) {
			$product->set_date_on_sale_to( $sale_dates['to'] );
			$changes['sale_date_to'] = $sale_dates['to'];
		}

		return true;
	}

	/**
	 * Validate and normalise sale date range arguments.
	 *
	 * @param array $args Arguments containing sale_date_from / sale_date_to.
	 * @return array{from:string,to:string}|WP_Error Normalised dates or error.
	 */
	protected function normalise_sale_dates( $args ) {
		$dates = array(
			'from' => '',
			'to'   => '',
		);

		if ( ! empty( $args['sale_date_from'] ) ) {
			$timestamp = strtotime( (string) $args['sale_date_from'] );
			if ( false === $timestamp ) {
				return new WP_Error(
					'invalid_sale_dates',
					__( 'Sale date from must be a valid date.', 'nvoos-content-graph-pro' )
				);
			}
			$dates['from'] = gmdate( 'Y-m-d', $timestamp );
		}

		if ( ! empty( $args['sale_date_to'] ) ) {
			$timestamp = strtotime( (string) $args['sale_date_to'] );
			if ( false === $timestamp ) {
				return new WP_Error(
					'invalid_sale_dates',
					__( 'Sale date to must be a valid date.', 'nvoos-content-graph-pro' )
				);
			}
			$dates['to'] = gmdate( 'Y-m-d', $timestamp );
		}

		if ( '' !== $dates['from'] && '' !== $dates['to'] && $dates['from'] > $dates['to'] ) {
			return new WP_Error(
				'invalid_sale_dates',
				__( 'Sale date from must not be after sale date to.', 'nvoos-content-graph-pro' )
			);
		}

		return $dates;
	}

	/**
	 * Apply a stock quantity change with WooCommerce's canonical semantics.
	 *
	 * Positive quantities route through wc_update_product_stock() so
	 * low-stock / no-stock notifications fire and stock status stays in sync.
	 * A resulting quantity of zero uses the CRUD path because
	 * wc_update_product_stock() rejects falsy quantities.
	 *
	 * @param WC_Product $product            Product or variation to update.
	 * @param int        $quantity           Quantity (absolute or delta).
	 * @param string     $operation          One of: set, increase, decrease.
	 * @param bool       $enable_manage_stock Whether to enable stock management.
	 * @return array|WP_Error Change details, or an error on failure.
	 */
	protected function apply_stock_quantity( $product, $quantity, $operation = 'set', $enable_manage_stock = true ) {
		$quantity = absint( $quantity );
		$current  = max( 0, (int) $product->get_stock_quantity() );

		switch ( $operation ) {
			case 'increase':
				$new_qty = $current + $quantity;
				break;
			case 'decrease':
				$new_qty = max( 0, $current - $quantity );
				break;
			default:
				$new_qty = $quantity;
		}

		if ( $enable_manage_stock && ! $product->get_manage_stock() ) {
			$product->set_manage_stock( true );
			$product->save();
		}

		if ( $new_qty > 0 ) {
			$updated = wc_update_product_stock( $product->get_id(), $new_qty, 'set' );

			if ( false === $updated ) {
				return new WP_Error(
					'stock_update_failed',
					__( 'Could not update the stock quantity.', 'nvoos-content-graph-pro' )
				);
			}
		} else {
			$product->set_stock_quantity( 0 );
			$product->save();
			wc_delete_product_transients( $product->get_id() );
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce core hook (byte-identical).
			do_action( 'woocommerce_no_stock', $product );
		}

		// Re-read the product so the reported stock status reflects the save.
		$fresh  = wc_get_product( $product->get_id() );
		$status = $fresh ? $fresh->get_stock_status() : $product->get_stock_status();

		$low_stock = false;
		if ( $product->get_manage_stock() && function_exists( 'wc_get_low_stock_amount' ) ) {
			$threshold = (int) wc_get_low_stock_amount( $product );
			$low_stock = $new_qty <= $threshold;
		}

		return array(
			'before'       => $current,
			'after'        => $new_qty,
			'stock_status' => $status,
			'low_stock'    => $low_stock,
		);
	}

	/**
	 * Re-sync a variable parent after its variations were updated.
	 *
	 * WC_Product_Variable::sync() recalculates the parent's min/max price
	 * and stock status from its variations.
	 *
	 * @param WC_Product $product Parent product.
	 * @return void
	 */
	protected function sync_variable_parent( $product ) {
		if ( ! $product->is_type( 'variable' ) ) {
			return;
		}

		if ( class_exists( 'WC_Product_Variable' ) && method_exists( 'WC_Product_Variable', 'sync' ) ) {
			WC_Product_Variable::sync( $product->get_id() );
		}

		wc_delete_product_transients( $product->get_id() );
	}

	/**
	 * Clear WooCommerce product transients for the given IDs.
	 *
	 * @param int|int[] $ids Product ID or list of IDs.
	 * @return void
	 */
	protected function clear_transients_for( $ids ) {
		foreach ( (array) $ids as $id ) {
			wc_delete_product_transients( (int) $id );
		}
	}
}
