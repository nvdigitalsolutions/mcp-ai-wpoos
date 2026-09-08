<?php
/**
 * WooCommerce Product Price Update Tool (ecosystem port — Wave F2, e-commerce products batch).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-tool-update-woo-product-price.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `WP_MCP_AI_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; the trait/script paths resolve from
 * `NVOOS_CONTENT_GRAPH_PRO_PATH` (`src/tools/ecommerce/` and
 * `scripts/` respectively).
 *
 * @package NvoosContentGraphPro
 * @since 2.2.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the shared price/quantity updater trait (guarded for load-order independence).
if ( ! trait_exists( 'WP_MCP_AI_Woo_Price_Qty_Updater' ) ) {
	require_once NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/ecommerce/trait-wp-mcp-ai-woo-price-qty-updater.php';
}

/**
 * Tool for updating WooCommerce product prices.
 *
 * Supports regular price, sale price, sale date ranges, and sale clearing
 * for every WooCommerce product type. Variable products update through
 * their variations (scope "variations" or "all") with automatic parent
 * re-sync; grouped products update through their child products.
 *
 * Requires WooCommerce and the E-commerce toolkit to be active.
 *
 * @since 2.2.0
 */
class WP_MCP_AI_Tool_Update_Woo_Product_Price implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Safety_Profile_Interface {

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
			return __( 'WooCommerce product price updates require WooCommerce to be installed and activated.', 'nvoos-content-graph-pro' );
		}

		if ( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
			return __( 'E-commerce toolkit is not enabled. Please enable it in plugin settings.', 'nvoos-content-graph-pro' );
		}

		return __( 'WooCommerce product price update tool is not available.', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'update_woo_product_price';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Update WooCommerce Product Price', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Updates the price of a WooCommerce product across all product types. Handles simple, variable (via variations), grouped (via child products), and external products, with regular price, sale price, scheduled sale dates, and sale clearing. For variable products use scope "variations" or "all" — the parent price range is re-synced automatically.', 'nvoos-content-graph-pro' );
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
				'product_id'     => array(
					'type'        => 'integer',
					'description' => __( 'WooCommerce product ID (or variation ID) to update.', 'nvoos-content-graph-pro' ),
				),
				'scope'          => array(
					'type'        => 'string',
					'description' => __( 'Which objects to update. "product" updates only the product itself, "variations" updates all variations of a variable product, "all" updates the product plus its variations (variable) or child products (grouped).', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'product', 'variations', 'all' ),
					'default'     => 'product',
				),
				'regular_price'  => array(
					'type'        => 'string',
					'description' => __( 'New regular price (e.g. "29.99").', 'nvoos-content-graph-pro' ),
				),
				'sale_price'     => array(
					'type'        => 'string',
					'description' => __( 'New sale price (must be lower than the regular price). Pass an empty string to clear the sale price.', 'nvoos-content-graph-pro' ),
				),
				'sale_date_from' => array(
					'type'        => 'string',
					'description' => __( 'Optional date the sale starts (Y-m-d or any valid date).', 'nvoos-content-graph-pro' ),
				),
				'sale_date_to'   => array(
					'type'        => 'string',
					'description' => __( 'Optional date the sale ends (Y-m-d or any valid date).', 'nvoos-content-graph-pro' ),
				),
				'clear_sale'     => array(
					'type'        => 'boolean',
					'description' => __( 'Set true to remove the sale price and sale dates entirely.', 'nvoos-content-graph-pro' ),
					'default'     => false,
				),
			),
			'required'   => array( 'product_id' ),
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
				__( 'WooCommerce is not installed or activated.', 'nvoos-content-graph-pro' )
			);
		}

		// Sanitize inputs at entry (two-gate rule, gate one).
		$product_id = isset( $arguments['product_id'] ) ? absint( $arguments['product_id'] ) : 0;
		$scope      = isset( $arguments['scope'] ) ? sanitize_key( $arguments['scope'] ) : 'product';
		$scope      = in_array( $scope, array( 'product', 'variations', 'all' ), true ) ? $scope : 'product';

		if ( empty( $product_id ) ) {
			return new WP_Error(
				'missing_product_id',
				__( 'Product ID is required.', 'nvoos-content-graph-pro' )
			);
		}

		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! user_can( $user_id, 'edit_products' ) ) {
			return new WP_Error(
				'permission_denied',
				__( 'You do not have permission to edit products.', 'nvoos-content-graph-pro' )
			);
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return new WP_Error(
				'product_not_found',
				__( 'Product not found.', 'nvoos-content-graph-pro' )
			);
		}

		// Reject non-product post types (e.g. orders passed by mistake).
		if ( ! in_array( $product->get_type(), array( 'simple', 'variable', 'variation', 'grouped', 'external' ), true ) ) {
			return new WP_Error(
				'unsupported_type',
				sprintf(
					/* translators: %s: product type */
					__( 'Product type "%s" is not supported for price updates.', 'nvoos-content-graph-pro' ),
					$product->get_type()
				)
			);
		}

		// Require at least one price field.
		$has_price_field = array_key_exists( 'regular_price', $arguments )
			|| array_key_exists( 'sale_price', $arguments )
			|| ! empty( $arguments['clear_sale'] );

		if ( ! $has_price_field ) {
			return new WP_Error(
				'missing_price_field',
				__( 'At least one of regular_price, sale_price, or clear_sale is required.', 'nvoos-content-graph-pro' )
			);
		}

		$targets = $this->resolve_update_targets( $product, $scope, 'price' );

		if ( is_wp_error( $targets ) ) {
			return $targets;
		}

		$updated = array();
		$errors  = array();

		foreach ( $targets as $target ) {
			$before = array(
				'regular_price' => $target->get_regular_price(),
				'sale_price'    => $target->get_sale_price(),
			);

			$changes = array();
			$result  = $this->apply_price_fields( $target, $arguments, $changes );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$target->save();
			wc_delete_product_transients( $target->get_id() );

			$updated[] = array(
				'id'            => $target->get_id(),
				'sku'           => $target->get_sku(),
				'type'          => $target->get_type(),
				'regular_price' => $target->get_regular_price(),
				'sale_price'    => $target->get_sale_price(),
				'on_sale'       => $target->is_on_sale(),
				'before'        => $before,
				'changes'       => $changes,
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
			'product_type' => $product->get_type(),
			'scope'        => $scope,
			'updated'      => $updated,
			'errors'       => $errors,
			'message'      => sprintf(
				/* translators: %d: number of updated products */
				_n(
					'Price updated for %d product.',
					'Price updated for %d products.',
					count( $updated ),
					'nvoos-content-graph-pro'
				),
				count( $updated )
			),
		);
	}
}
