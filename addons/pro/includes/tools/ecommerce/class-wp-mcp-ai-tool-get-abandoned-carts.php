<?php
/**
 * Get Abandoned Carts Tool
 *
 * Retrieves abandoned WooCommerce shopping carts, optionally filtered
 * by date range, minimum cart value, or customer status.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.8.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for querying abandoned WooCommerce carts.
 *
 * Queries WooCommerce session data or dedicated abandoned cart storage
 * and returns carts matching the supplied filters.
 *
 * @since 2.8.0
 */
class WP_MCP_AI_Tool_Get_Abandoned_Carts implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_abandoned_carts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Abandoned Carts', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves abandoned WooCommerce shopping carts, optionally filtered by date range, minimum cart value, or customer status (guest, registered, or all). Queries WooCommerce session data and returns cart contents, totals, and customer information.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'date_from'     => array(
					'type'        => 'string',
					'description' => __( 'Start date for abandoned cart lookup (YYYY-MM-DD format).', 'mcp-ai-wpoos-pro' ),
					'format'      => 'date',
				),
				'date_to'       => array(
					'type'        => 'string',
					'description' => __( 'End date for abandoned cart lookup (YYYY-MM-DD format).', 'mcp-ai-wpoos-pro' ),
					'format'      => 'date',
				),
				'min_value'     => array(
					'type'        => 'number',
					'description' => __( 'Minimum cart value to include. Default: 0 (all carts).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
					'default'     => 0,
				),
				'customer_type' => array(
					'type'        => 'string',
					'description' => __( 'Filter by customer type: "guest", "registered", or "all".', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'guest', 'registered', 'all' ),
					'default'     => 'all',
				),
				'limit'         => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of carts to return. Default: 50.', 'mcp-ai-wpoos-pro' ),
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 500,
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_woocommerce';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'ecommerce',
			'post_type'             => 'shop_order',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'administrator', 'shop_manager' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * Get capability flags for this tool.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
			'local-only',
			'requires-capability',
			'requires-plugin',
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * Requires the E-commerce Toolkit to be enabled and WooCommerce to be active.
	 *
	 * @since 2.8.0
	 * @return bool
	 */
	public static function is_available() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		return function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && wp_mcp_ai_is_ecommerce_toolkit_enabled();
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.8.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return __( 'The Get Abandoned Carts tool requires WooCommerce to be installed and activated.', 'mcp-ai-wpoos-pro' );
		}

		if ( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
			return __( 'E-commerce toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Get Abandoned Carts tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check permissions.
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// phpcs:ignore WordPress.WP.Capabilities.Unknown -- manage_woocommerce is a WooCommerce capability.
		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_woocommerce' ) ) {
			if ( ! user_can( $current_user_id, 'edit_posts' ) ) {
				return new WP_Error(
					'wp_mcp_ai_forbidden',
					__( 'You do not have permission to view abandoned carts.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		// Check if the tool is available.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$date_from     = isset( $arguments['date_from'] ) ? sanitize_text_field( $arguments['date_from'] ) : '';
		$date_to       = isset( $arguments['date_to'] ) ? sanitize_text_field( $arguments['date_to'] ) : '';
		$min_value     = isset( $arguments['min_value'] ) ? floatval( $arguments['min_value'] ) : 0;
		$customer_type = isset( $arguments['customer_type'] ) ? sanitize_text_field( $arguments['customer_type'] ) : 'all';
		$limit         = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 50;

		global $wpdb;

		// Build the base query against WooCommerce sessions table.
		$where_clauses = array();
		$where_args    = array();

		// Date range filter.
		if ( ! empty( $date_from ) ) {
			$from_timestamp  = strtotime( $date_from . ' 00:00:00' );
			$where_clauses[] = 's.session_expiry >= %d';
			$where_args[]    = $from_timestamp;
		}

		if ( ! empty( $date_to ) ) {
			$to_timestamp    = strtotime( $date_to . ' 23:59:59' );
			$where_clauses[] = 's.session_expiry <= %d';
			$where_args[]    = $to_timestamp;
		}

		// Only sessions that have already expired (i.e. carts that are abandoned).
		$where_clauses[] = 's.session_expiry < %d';
		$where_args[]    = time();

		$where_sql = '';
		if ( ! empty( $where_clauses ) ) {
			$where_sql = 'WHERE ' . implode( ' AND ', $where_clauses );
		}

		$table_name = $wpdb->prefix . 'woocommerce_sessions';

		// Check if the sessions table exists.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
		if ( ! $table_exists ) {
			// Fallback: query user meta for persistent cart data.
			return $this->query_persistent_carts( $date_from, $date_to, $min_value, $customer_type, $limit );
		}

		$query        = "SELECT s.session_key, s.session_value, s.session_expiry
			FROM {$table_name} s
			{$where_sql}
			ORDER BY s.session_expiry DESC
			LIMIT %d";
		$where_args[] = $limit;

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is safe, query built with %d placeholders.
		$prepared_query = $wpdb->prepare( $query, $where_args );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$sessions = $wpdb->get_results( $prepared_query );

		$abandoned_carts = array();

		foreach ( $sessions as $session_data ) {
			$session = maybe_unserialize( $session_data->session_value );
			if ( ! is_array( $session ) ) {
				continue;
			}

			$cart = isset( $session['cart'] ) ? maybe_unserialize( $session['cart'] ) : array();
			if ( empty( $cart ) ) {
				continue;
			}

			$customer         = isset( $session['customer'] ) ? $session['customer'] : array();
			$customer_email   = isset( $customer['email'] ) ? sanitize_email( $customer['email'] ) : '';
			$customer_user_id = isset( $customer['id'] ) ? absint( $customer['id'] ) : 0;

			// Apply customer type filter.
			if ( 'guest' === $customer_type && $customer_user_id > 0 ) {
				continue;
			}
			if ( 'registered' === $customer_type && 0 === $customer_user_id ) {
				continue;
			}

			// Calculate cart total and build items list.
			$cart_total = 0;
			$items      = array();

			foreach ( $cart as $cart_item_key => $cart_item ) {
				$product_id = isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
				$quantity   = isset( $cart_item['quantity'] ) ? absint( $cart_item['quantity'] ) : 0;
				$variation  = isset( $cart_item['variation_id'] ) ? absint( $cart_item['variation_id'] ) : 0;

				$product = wc_get_product( $variation ? $variation : $product_id );
				if ( ! $product ) {
					continue;
				}

				$price       = floatval( $product->get_price() );
				$item_total  = $price * $quantity;
				$cart_total += $item_total;

				$items[] = array(
					'product_id'   => $product_id,
					'variation_id' => $variation,
					'product_name' => $product->get_name(),
					'sku'          => $product->get_sku(),
					'quantity'     => $quantity,
					'price'        => wc_format_decimal( $price, 2 ),
					'total'        => wc_format_decimal( $item_total, 2 ),
				);
			}

			// Apply minimum value filter.
			if ( $cart_total < $min_value ) {
				continue;
			}

			$abandoned_carts[] = array(
				'session_key'      => sanitize_text_field( $session_data->session_key ),
				'customer_email'   => $customer_email,
				'customer_user_id' => $customer_user_id,
				'customer_type'    => $customer_user_id > 0 ? 'registered' : 'guest',
				'cart_total'       => wc_format_decimal( $cart_total, 2 ),
				'items_count'      => count( $items ),
				'items'            => $items,
				'session_expiry'   => absint( $session_data->session_expiry ),
				'abandoned_at'     => gmdate( 'Y-m-d H:i:s', absint( $session_data->session_expiry ) ),
				'hours_abandoned'  => round( ( time() - absint( $session_data->session_expiry ) ) / 3600, 1 ),
			);
		}

		return array(
			'success'         => true,
			'total_found'     => count( $abandoned_carts ),
			'filters'         => array(
				'date_from'     => $date_from ? $date_from : null,
				'date_to'       => $date_to ? $date_to : null,
				'min_value'     => $min_value,
				'customer_type' => $customer_type,
				'limit'         => $limit,
			),
			'abandoned_carts' => $abandoned_carts,
			'message'         => sprintf(
				/* translators: %d: Number of abandoned carts found */
				__( 'Found %d abandoned carts matching criteria.', 'mcp-ai-wpoos-pro' ),
				count( $abandoned_carts )
			),
		);
	}

	/**
	 * Fallback: query persistent carts stored in user meta when
	 * the WooCommerce sessions table is unavailable.
	 *
	 * @since 2.8.0
	 *
	 * @param string $date_from     Start date filter.
	 * @param string $date_to       End date filter.
	 * @param float  $min_value     Minimum cart value.
	 * @param string $customer_type Customer type filter.
	 * @param int    $limit         Maximum results.
	 * @return array Query results.
	 */
	protected function query_persistent_carts( $date_from, $date_to, $min_value, $customer_type, $limit ) {
		global $wpdb;

		$meta_key = '_woocommerce_persistent_cart_1';
		$user_ids = array();

		// Query users who have a persistent cart.
		$user_query = new WP_User_Query(
			array(
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_key'     => $meta_key,
				'meta_compare' => 'EXISTS',
				'number'       => $limit,
				'fields'       => 'ID',
			)
		);

		$user_ids = $user_query->get_results();
		if ( empty( $user_ids ) ) {
			return array(
				'success'         => true,
				'total_found'     => 0,
				'filters'         => array(
					'date_from'     => $date_from ? $date_from : null,
					'date_to'       => $date_to ? $date_to : null,
					'min_value'     => $min_value,
					'customer_type' => $customer_type,
					'limit'         => $limit,
				),
				'abandoned_carts' => array(),
				'message'         => __( 'No abandoned carts found.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$abandoned_carts = array();

		foreach ( $user_ids as $user_id ) {
			if ( 'guest' === $customer_type ) {
				continue;
			}

			$persistent_cart = get_user_meta( $user_id, $meta_key, true );
			if ( empty( $persistent_cart['cart'] ) ) {
				continue;
			}

			$user = get_userdata( $user_id );

			$cart_total = 0;
			$items      = array();

			foreach ( $persistent_cart['cart'] as $cart_item_key => $cart_item ) {
				$product_id = isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
				$quantity   = isset( $cart_item['quantity'] ) ? absint( $cart_item['quantity'] ) : 0;
				$variation  = isset( $cart_item['variation_id'] ) ? absint( $cart_item['variation_id'] ) : 0;

				$product = wc_get_product( $variation ? $variation : $product_id );
				if ( ! $product ) {
					continue;
				}

				$price       = floatval( $product->get_price() );
				$item_total  = $price * $quantity;
				$cart_total += $item_total;

				$items[] = array(
					'product_id'   => $product_id,
					'variation_id' => $variation,
					'product_name' => $product->get_name(),
					'sku'          => $product->get_sku(),
					'quantity'     => $quantity,
					'price'        => wc_format_decimal( $price, 2 ),
					'total'        => wc_format_decimal( $item_total, 2 ),
				);
			}

			if ( $cart_total < $min_value ) {
				continue;
			}

			$abandoned_carts[] = array(
				'session_key'      => 'persistent_' . $user_id,
				'customer_email'   => $user ? $user->user_email : '',
				'customer_user_id' => $user_id,
				'customer_type'    => 'registered',
				'cart_total'       => wc_format_decimal( $cart_total, 2 ),
				'items_count'      => count( $items ),
				'items'            => $items,
				'session_expiry'   => 0,
				'abandoned_at'     => '',
				'hours_abandoned'  => 0,
			);
		}

		return array(
			'success'         => true,
			'total_found'     => count( $abandoned_carts ),
			'filters'         => array(
				'date_from'     => $date_from ? $date_from : null,
				'date_to'       => $date_to ? $date_to : null,
				'min_value'     => $min_value,
				'customer_type' => $customer_type,
				'limit'         => $limit,
			),
			'data_source'     => 'persistent_cart',
			'abandoned_carts' => $abandoned_carts,
			'message'         => sprintf(
				/* translators: %d: Number of abandoned carts found */
				__( 'Found %d abandoned carts via persistent cart storage.', 'mcp-ai-wpoos-pro' ),
				count( $abandoned_carts )
			),
		);
	}
}
