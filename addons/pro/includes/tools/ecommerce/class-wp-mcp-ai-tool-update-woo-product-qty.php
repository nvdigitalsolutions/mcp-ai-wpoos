<?php
/**
 * WooCommerce Product Quantity Update Tool
 *
 * Pro add-on tool for updating WooCommerce stock quantities across all
 * stock-managed product types: simple, variation, variable (via
 * variations), and grouped (via child products).
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage Ecommerce_Toolkit
 * @since      2.2.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the shared price/quantity updater trait (guarded for load-order independence).
if ( ! trait_exists( 'WP_MCP_AI_Woo_Price_Qty_Updater' ) ) {
	require_once WP_MCP_AI_PRO_PATH . 'includes/tools/ecommerce/trait-wp-mcp-ai-woo-price-qty-updater.php';
}

/**
 * Tool for updating WooCommerce product stock quantities.
 *
 * Uses WooCommerce's canonical wc_update_product_stock() path so stock
 * status stays in sync and low-stock / no-stock notifications fire.
 * Supports set, increase, and decrease operations — the same signature
 * used by leading WooCommerce MCP integrations.
 *
 * Requires WooCommerce and the E-commerce toolkit to be active.
 *
 * @since 2.2.0
 */
class WP_MCP_AI_Tool_Update_Woo_Product_Qty implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Safety_Profile_Interface {

	use WP_MCP_AI_Woo_Price_Qty_Updater;
	use WP_MCP_AI_Tool_Safety_Profile;

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if this tool is available.
	 *
	 * @since 2.2.0
	 *
	 * @return bool True if WooCommerce is active and the toolkit is enabled.
	 */
	public static function is_available() {
		// Check if WooCommerce is active.
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		// Check if base version.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		// Check if e-commerce toolkit is enabled (fall back to the raw option
		// when the toolkit bootstrap has not loaded the shared helper yet).
		if ( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) ) {
			return wp_mcp_ai_is_ecommerce_toolkit_enabled();
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_ecommerce_toolkit'] );
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 2.2.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return __( 'WooCommerce product quantity updates require WooCommerce to be installed and activated.', 'mcp-ai-wpoos-pro' );
		}

		if ( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
			return __( 'E-commerce toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'WooCommerce product quantity update tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'update_woo_product_qty';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Update WooCommerce Product Quantity', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Updates the stock quantity of a WooCommerce product across all stock-managed product types. Handles simple products, variations, variable products (via variations with automatic parent sync), and grouped products (via child products). Supports set, increase, and decrease operations; stock status and low-stock notifications are handled automatically. Set notify to false to suppress low-stock/no-stock notification emails for bulk or automated restock passes. External/affiliate products are not stock-managed and are rejected.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'product_id'   => array(
					'type'        => 'integer',
					'description' => __( 'WooCommerce product ID (or variation ID) to update.', 'mcp-ai-wpoos-pro' ),
				),
				'quantity'     => array(
					'type'        => 'integer',
					'description' => __( 'Stock quantity. Absolute value for "set", or the delta for "increase"/"decrease". Must be 0 or greater.', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'operation'    => array(
					'type'        => 'string',
					'description' => __( 'How to apply the quantity: "set" replaces the current quantity, "increase" adds to it, "decrease" subtracts from it (clamped at 0).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'set', 'increase', 'decrease' ),
					'default'     => 'set',
				),
				'scope'        => array(
					'type'        => 'string',
					'description' => __( 'Which objects to update. "product" updates only the product itself, "variations" updates all variations of a variable product, "all" updates the product plus its variations (variable) or child products (grouped).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'product', 'variations', 'all' ),
					'default'     => 'product',
				),
				'manage_stock' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to enable stock management on the product when it is currently disabled. Default: true.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'notify'       => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to send WooCommerce low-stock/no-stock notification emails for this update. Set to false for bulk or automated restock passes. Stock status sync and the woocommerce_low_stock/woocommerce_no_stock actions are unaffected. Default: true.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'product_id', 'quantity' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',              // Pro tier tool.
			'write',            // State-changing operation.
			'requires-plugin',  // Requires WooCommerce.
			'local-only',       // No external API calls.
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if WooCommerce is active.
		if ( ! class_exists( 'WooCommerce' ) ) {
			return new WP_Error(
				'woocommerce_not_active',
				__( 'WooCommerce is not installed or activated.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Sanitize inputs at entry (two-gate rule, gate one).
		$product_id = isset( $arguments['product_id'] ) ? absint( $arguments['product_id'] ) : 0;
		$scope      = isset( $arguments['scope'] ) ? sanitize_key( $arguments['scope'] ) : 'product';
		$scope      = in_array( $scope, array( 'product', 'variations', 'all' ), true ) ? $scope : 'product';
		$operation  = isset( $arguments['operation'] ) ? sanitize_key( $arguments['operation'] ) : 'set';
		$operation  = in_array( $operation, array( 'set', 'increase', 'decrease' ), true ) ? $operation : 'set';
		$raw_qty    = isset( $arguments['quantity'] ) ? $arguments['quantity'] : null;
		$manage     = isset( $arguments['manage_stock'] ) ? (bool) $arguments['manage_stock'] : true;
		$notify     = isset( $arguments['notify'] ) ? (bool) $arguments['notify'] : true;

		if ( empty( $product_id ) ) {
			return new WP_Error(
				'missing_product_id',
				__( 'Product ID is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( null === $raw_qty ) {
			return new WP_Error(
				'missing_quantity',
				__( 'Quantity is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( ! is_numeric( $raw_qty ) || (float) $raw_qty < 0 ) {
			return new WP_Error(
				'invalid_quantity',
				__( 'Quantity must be a non-negative number.', 'mcp-ai-wpoos-pro' )
			);
		}

		$quantity = absint( $raw_qty );

		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! user_can( $user_id, 'edit_products' ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'You do not have permission to edit products.', 'mcp-ai-wpoos-pro' )
			);
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return new WP_Error(
				'product_not_found',
				__( 'Product not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		$type = $product->get_type();

		// External/affiliate products carry no stock at all.
		if ( 'external' === $type ) {
			return new WP_Error(
				'unsupported_type',
				__( 'External/affiliate products are not stock-managed and cannot have a quantity updated.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Reject non-product post types (e.g. orders passed by mistake).
		if ( ! in_array( $type, array( 'simple', 'variable', 'variation', 'grouped' ), true ) ) {
			return new WP_Error(
				'unsupported_type',
				sprintf(
					/* translators: %s: product type */
					__( 'Product type "%s" is not supported for quantity updates.', 'mcp-ai-wpoos-pro' ),
					$type
				)
			);
		}

		$targets = $this->resolve_update_targets( $product, $scope, 'stock' );

		if ( is_wp_error( $targets ) ) {
			return $targets;
		}

		$updated = array();

		foreach ( $targets as $target ) {
			$result = $this->apply_stock_quantity( $target, $quantity, $operation, $manage, $notify );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$updated[] = array(
				'id'           => $target->get_id(),
				'sku'          => $target->get_sku(),
				'type'         => $target->get_type(),
				'before_qty'   => $result['before'],
				'after_qty'    => $result['after'],
				'stock_status' => $result['stock_status'],
				'low_stock'    => $result['low_stock'],
			);
		}

		// Re-sync variable parents after variation updates (direct variation
		// updates re-sync their parent variable product as well).
		$this->sync_variable_parent( $product );
		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent ) {
				$this->sync_variable_parent( $parent );
			}
		}

		return array(
			'product_id'   => $product_id,
			'product_type' => $type,
			'scope'        => $scope,
			'operation'    => $operation,
			'updated'      => $updated,
			'message'      => sprintf(
				/* translators: %d: number of updated products */
				_n(
					'Quantity updated for %d product.',
					'Quantity updated for %d products.',
					count( $updated ),
					'mcp-ai-wpoos-pro'
				),
				count( $updated )
			),
		);
	}
}
