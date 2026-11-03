<?php
/**
 * WooCommerce return-URL wiring (Proposal 108 Phase 3).
 *
 * After a hosted checkout completes, Woo redirects to its order-received
 * page. This filter repoints the return destination at the storefront
 * thanks route (`/checkout/thanks/`) with the order reference in the query
 * — the shopper lands back on the headless frontend, never stranded on the
 * WordPress domain.
 *
 * @package NvoosAstroBridge\Woo
 */

namespace NvoosAstroBridge\Woo;

use NvoosAstroBridge\Schema;

/**
 * Return URL filter.
 */
class ReturnUrlFilter {

	/**
	 * Register the filter (safe to register without Woo — the hook only
	 * fires inside Woo's checkout flow).
	 *
	 * @return void
	 */
	public function register(): void {
		add_filter( 'woocommerce_get_return_url', array( $this, 'storefront_return_url' ), 10, 3 );
	}

	/**
	 * Repoint the order-received return at the storefront thanks route.
	 *
	 * @param string    $return_url Woo's default return URL.
	 * @param \WC_Order $order      The completed order.
	 * @return string
	 */
	public function storefront_return_url( $return_url, $order ) {
		$stored = get_option( Schema::OPTION_SHOP, Schema::shop_defaults() );
		$stored = is_array( $stored ) ? $stored : Schema::shop_defaults();

		$target = isset( $stored['returnUrl'] ) ? esc_url_raw( (string) $stored['returnUrl'] ) : '';
		if ( '' === $target ) {
			return $return_url;
		}

		$args = array();
		if ( $order instanceof \WC_Order ) {
			$order_id  = $order->get_id();
			$order_key = $order->get_order_key();
			if ( $order_id ) {
				$args['order_id'] = (string) $order_id;
			}
			if ( $order_key ) {
				$args['order_key'] = $order_key;
			}
		}

		return empty( $args ) ? $target : add_query_arg( $args, $target );
	}
}
