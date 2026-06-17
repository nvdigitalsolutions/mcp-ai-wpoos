<?php
/**
 * Shipping Box Packer Tool
 *
 * Packs items into optimal shipping boxes using a 3D bin-packing algorithm.
 * Supports custom cubic boxes and USPS flat-rate boxes with automatic best-fit
 * selection, weight limits, and USPS cubic pricing tier calculation.
 *
 * Inspired by the nv-boxpacker plugin (dvdoug/boxpacker).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for packing items into optimal shipping boxes.
 *
 * Supports:
 * - 3D bin-packing with best-fit decreasing algorithm
 * - Custom box definitions (cubic and flat-rate)
 * - USPS cubic pricing tier calculation
 * - Multi-item packing across multiple boxes
 * - Weight and dimension constraints
 * - Keep-flat item support
 * - WooCommerce order packing
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Shipping_Box_Packer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Default box definitions for USPS shipping.
	 *
	 * Dimensions in inches, weight in ounces, max_weight in pounds.
	 *
	 * @var array
	 */
	const DEFAULT_BOXES = array(
		array(
			'reference'    => 'Cubic Small',
			'package_code' => 'package',
			'package_name' => 'Custom Cubic Small',
			'box_type'     => 'cubic',
			'outer_length' => 8,
			'outer_width'  => 8,
			'outer_depth'  => 6,
			'inner_length' => 8,
			'inner_width'  => 8,
			'inner_depth'  => 6,
			'empty_weight' => 3,
			'max_weight'   => 20,
		),
		array(
			'reference'    => 'Cubic Medium',
			'package_code' => 'package',
			'package_name' => 'Custom Cubic Medium',
			'box_type'     => 'cubic',
			'outer_length' => 12,
			'outer_width'  => 10,
			'outer_depth'  => 8,
			'inner_length' => 12,
			'inner_width'  => 10,
			'inner_depth'  => 8,
			'empty_weight' => 5,
			'max_weight'   => 20,
		),
		array(
			'reference'    => 'Cubic Large',
			'package_code' => 'package',
			'package_name' => 'Custom Cubic Large',
			'box_type'     => 'cubic',
			'outer_length' => 14,
			'outer_width'  => 12,
			'outer_depth'  => 10,
			'inner_length' => 14,
			'inner_width'  => 12,
			'inner_depth'  => 10,
			'empty_weight' => 7,
			'max_weight'   => 20,
		),
		array(
			'reference'    => 'USPS Small Flat Rate',
			'package_code' => 'small_flat_rate_box',
			'package_name' => 'USPS Small Flat Rate Box',
			'box_type'     => 'flat_rate',
			'outer_length' => 9,
			'outer_width'  => 6,
			'outer_depth'  => 2,
			'inner_length' => 9,
			'inner_width'  => 6,
			'inner_depth'  => 2,
			'empty_weight' => 4,
			'max_weight'   => 70,
		),
		array(
			'reference'    => 'USPS Medium Flat Rate',
			'package_code' => 'medium_flat_rate_box',
			'package_name' => 'USPS Medium Flat Rate Box',
			'box_type'     => 'flat_rate',
			'outer_length' => 14,
			'outer_width'  => 12,
			'outer_depth'  => 3,
			'inner_length' => 14,
			'inner_width'  => 12,
			'inner_depth'  => 3,
			'empty_weight' => 6,
			'max_weight'   => 70,
		),
		array(
			'reference'    => 'USPS Large Flat Rate',
			'package_code' => 'large_flat_rate_box',
			'package_name' => 'USPS Large Flat Rate Box',
			'box_type'     => 'flat_rate',
			'outer_length' => 12,
			'outer_width'  => 12,
			'outer_depth'  => 6,
			'inner_length' => 12,
			'inner_width'  => 12,
			'inner_depth'  => 6,
			'empty_weight' => 8,
			'max_weight'   => 70,
		),
	);

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True if WooCommerce is active and toolkit is enabled.
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
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.2.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return __( 'Shipping box packer requires WooCommerce to be installed and activated.', 'mcp-ai-wpoos-pro' );
		}

		if ( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
			return __( 'E-commerce toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Shipping box packer tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'shipping_box_packer';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Shipping Box Packer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Pack items into optimal shipping boxes using a 3D bin-packing algorithm. Supports custom cubic boxes and USPS flat-rate boxes with automatic best-fit selection, weight limits, and USPS cubic pricing tier calculation. Can pack from a WooCommerce order or from manually specified items and box definitions.', 'mcp-ai-wpoos-pro' );
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
				'action'   => array(
					'type'        => 'string',
					'description' => __( 'Packing action to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'pack_items', 'pack_order', 'list_boxes' ),
					'default'     => 'pack_items',
				),
				'order_id' => array(
					'type'        => 'integer',
					'description' => __( 'WooCommerce order ID (required for pack_order action).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'items'    => array(
					'type'        => 'array',
					'description' => __( 'Items to pack (required for pack_items action). Each item needs name, dimensions (inches), weight (ounces), and optionally quantity and keep_flat.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'      => array(
								'type'        => 'string',
								'description' => __( 'Item name or description.', 'mcp-ai-wpoos-pro' ),
							),
							'length'    => array(
								'type'        => 'number',
								'description' => __( 'Item length in inches.', 'mcp-ai-wpoos-pro' ),
								'minimum'     => 0.1,
							),
							'width'     => array(
								'type'        => 'number',
								'description' => __( 'Item width in inches.', 'mcp-ai-wpoos-pro' ),
								'minimum'     => 0.1,
							),
							'height'    => array(
								'type'        => 'number',
								'description' => __( 'Item height in inches.', 'mcp-ai-wpoos-pro' ),
								'minimum'     => 0.1,
							),
							'weight_oz' => array(
								'type'        => 'number',
								'description' => __( 'Item weight in ounces.', 'mcp-ai-wpoos-pro' ),
								'minimum'     => 0.1,
							),
							'quantity'  => array(
								'type'        => 'integer',
								'description' => __( 'Number of this item (default: 1).', 'mcp-ai-wpoos-pro' ),
								'minimum'     => 1,
								'default'     => 1,
							),
							'keep_flat' => array(
								'type'        => 'boolean',
								'description' => __( 'Whether this item must remain flat during packing.', 'mcp-ai-wpoos-pro' ),
								'default'     => false,
							),
						),
						'required'   => array( 'name', 'length', 'width', 'height', 'weight_oz' ),
					),
				),
				'boxes'    => array(
					'type'        => 'array',
					'description' => __( 'Custom box definitions (optional, uses defaults if not provided). Each box needs reference, dimensions (inches), empty_weight (oz), max_weight (lbs), and box_type (cubic or flat_rate).', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'reference'    => array(
								'type'        => 'string',
								'description' => __( 'Box name/label.', 'mcp-ai-wpoos-pro' ),
							),
							'package_code' => array(
								'type'        => 'string',
								'description' => __( 'Carrier package code (e.g. "package", "small_flat_rate_box").', 'mcp-ai-wpoos-pro' ),
								'default'     => 'package',
							),
							'package_name' => array(
								'type'        => 'string',
								'description' => __( 'Display name for the box.', 'mcp-ai-wpoos-pro' ),
							),
							'box_type'     => array(
								'type'        => 'string',
								'description' => __( 'Box type: "cubic" for custom boxes or "flat_rate" for USPS flat-rate.', 'mcp-ai-wpoos-pro' ),
								'enum'        => array( 'cubic', 'flat_rate' ),
								'default'     => 'cubic',
							),
							'outer_length' => array(
								'type'        => 'number',
								'description' => __( 'Outer length in inches.', 'mcp-ai-wpoos-pro' ),
							),
							'outer_width'  => array(
								'type'        => 'number',
								'description' => __( 'Outer width in inches.', 'mcp-ai-wpoos-pro' ),
							),
							'outer_depth'  => array(
								'type'        => 'number',
								'description' => __( 'Outer depth/height in inches.', 'mcp-ai-wpoos-pro' ),
							),
							'inner_length' => array(
								'type'        => 'number',
								'description' => __( 'Inner usable length in inches.', 'mcp-ai-wpoos-pro' ),
							),
							'inner_width'  => array(
								'type'        => 'number',
								'description' => __( 'Inner usable width in inches.', 'mcp-ai-wpoos-pro' ),
							),
							'inner_depth'  => array(
								'type'        => 'number',
								'description' => __( 'Inner usable depth in inches.', 'mcp-ai-wpoos-pro' ),
							),
							'empty_weight' => array(
								'type'        => 'number',
								'description' => __( 'Empty box weight in ounces.', 'mcp-ai-wpoos-pro' ),
								'default'     => 0,
							),
							'max_weight'   => array(
								'type'        => 'number',
								'description' => __( 'Maximum payload weight in pounds.', 'mcp-ai-wpoos-pro' ),
								'default'     => 70,
							),
						),
						'required'   => array( 'reference', 'outer_length', 'outer_width', 'outer_depth' ),
					),
				),
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
			'requires-plugin',
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
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_woocommerce' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use the shipping box packer.', 'mcp-ai-wpoos-pro' )
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : 'pack_items';

		switch ( $action ) {
			case 'pack_items':
				return $this->handle_pack_items( $arguments );

			case 'pack_order':
				return $this->handle_pack_order( $arguments );

			case 'list_boxes':
				return $this->handle_list_boxes( $arguments );

			default:
				return new WP_Error(
					'wp_mcp_ai_invalid_action',
					/* translators: %s: action name */
					sprintf( __( 'Invalid action: %s. Use pack_items, pack_order, or list_boxes.', 'mcp-ai-wpoos-pro' ), $action )
				);
		}
	}

	/**
	 * Handle the pack_items action.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_pack_items( array $arguments ) {
		if ( empty( $arguments['items'] ) || ! is_array( $arguments['items'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_items',
				__( 'Items array is required for the pack_items action.', 'mcp-ai-wpoos-pro' )
			);
		}

		$items = $this->expand_items( $arguments['items'] );

		if ( empty( $items ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_items',
				__( 'No valid items found after processing. Each item needs name, length, width, height, and weight_oz.', 'mcp-ai-wpoos-pro' )
			);
		}

		$boxes    = $this->get_boxes( $arguments );
		$packages = $this->pack_items( $items, $boxes );

		return array(
			'success'         => true,
			'message'         => sprintf(
				/* translators: 1: number of items, 2: number of packages */
				__( 'Successfully packed %1$d items into %2$d package(s).', 'mcp-ai-wpoos-pro' ),
				count( $items ),
				count( $packages )
			),
			'total_items'     => count( $items ),
			'total_packages'  => count( $packages ),
			'total_weight_oz' => array_sum( array_column( $packages, 'weight_oz' ) ),
			'packages'        => $packages,
		);
	}

	/**
	 * Handle the pack_order action.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_pack_order( array $arguments ) {
		if ( empty( $arguments['order_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_order_id',
				__( 'order_id is required for the pack_order action.', 'mcp-ai-wpoos-pro' )
			);
		}

		$order = wc_get_order( absint( $arguments['order_id'] ) );

		if ( ! $order instanceof WC_Order ) {
			return new WP_Error(
				'wp_mcp_ai_order_not_found',
				/* translators: %d: order ID */
				sprintf( __( 'Order #%d not found.', 'mcp-ai-wpoos-pro' ), absint( $arguments['order_id'] ) )
			);
		}

		$items = $this->get_shippable_items_from_order( $order );

		if ( empty( $items ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_shippable_items',
				__( 'No shippable items found in this order.', 'mcp-ai-wpoos-pro' )
			);
		}

		$boxes    = $this->get_boxes( $arguments );
		$packages = $this->pack_items( $items, $boxes );

		return array(
			'success'         => true,
			'message'         => sprintf(
				/* translators: 1: order number, 2: number of items, 3: number of packages */
				__( 'Order #%1$s: packed %2$d items into %3$d package(s).', 'mcp-ai-wpoos-pro' ),
				$order->get_order_number(),
				count( $items ),
				count( $packages )
			),
			'order_id'        => $order->get_id(),
			'order_number'    => $order->get_order_number(),
			'total_items'     => count( $items ),
			'total_packages'  => count( $packages ),
			'total_weight_oz' => array_sum( array_column( $packages, 'weight_oz' ) ),
			'packages'        => $packages,
		);
	}

	/**
	 * Handle the list_boxes action.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array
	 */
	protected function handle_list_boxes( array $arguments ) {
		$boxes = $this->get_boxes( $arguments );

		$enriched = array();
		foreach ( $boxes as $box ) {
			$volume_cubic_inches = (float) $box['inner_length'] * (float) $box['inner_width'] * (float) $box['inner_depth'];
			$volume_cubic_feet   = $volume_cubic_inches / 1728.0;

			$enriched[] = array_merge(
				$box,
				array(
					'volume_cubic_inches' => round( $volume_cubic_inches, 2 ),
					'volume_cubic_feet'   => round( $volume_cubic_feet, 4 ),
					'cubic_eligible'      => $this->is_cubic_eligible(
						array(
							'length' => (float) $box['outer_length'],
							'width'  => (float) $box['outer_width'],
							'height' => (float) $box['outer_depth'],
						),
						0
					),
					'cubic_tier'          => 'cubic' === $box['box_type']
						? $this->get_cubic_tier(
							array(
								'length' => (float) $box['outer_length'],
								'width'  => (float) $box['outer_width'],
								'height' => (float) $box['outer_depth'],
							)
						)
						: '',
				)
			);
		}

		return array(
			'success'   => true,
			'message'   => sprintf(
				/* translators: %d: number of boxes */
				__( '%d box definition(s) available.', 'mcp-ai-wpoos-pro' ),
				count( $enriched )
			),
			'box_count' => count( $enriched ),
			'boxes'     => $enriched,
		);
	}

	/**
	 * Expand raw item input into a flat list with one entry per unit.
	 *
	 * @param array $raw_items Raw item rows.
	 * @return array Expanded flat list of item arrays.
	 */
	protected function expand_items( array $raw_items ) {
		$items = array();

		foreach ( $raw_items as $index => $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$name      = isset( $raw['name'] ) ? sanitize_text_field( $raw['name'] ) : '';
			$length    = isset( $raw['length'] ) ? (float) $raw['length'] : 0;
			$width     = isset( $raw['width'] ) ? (float) $raw['width'] : 0;
			$height    = isset( $raw['height'] ) ? (float) $raw['height'] : 0;
			$weight_oz = isset( $raw['weight_oz'] ) ? (float) $raw['weight_oz'] : 0;

			// Skip items with no valid dimensions.
			if ( $length <= 0 || $width <= 0 || $height <= 0 || $weight_oz <= 0 ) {
				continue;
			}

			if ( '' === $name ) {
				/* translators: %d: item row number (1-based). */
				$name = sprintf( __( 'Item %d', 'mcp-ai-wpoos-pro' ), $index + 1 );
			}

			$qty       = isset( $raw['quantity'] ) ? max( 1, absint( $raw['quantity'] ) ) : 1;
			$keep_flat = ! empty( $raw['keep_flat'] );

			$item = array(
				'name'      => $name,
				'length'    => max( 0.1, $length ),
				'width'     => max( 0.1, $width ),
				'height'    => max( 0.1, $height ),
				'weight_oz' => max( 0.1, $weight_oz ),
				'keep_flat' => $keep_flat,
			);

			for ( $i = 0; $i < $qty; $i++ ) {
				$items[] = $item;
			}
		}

		return $items;
	}

	/**
	 * Extract shippable items from a WooCommerce order.
	 *
	 * @param WC_Order $order The WooCommerce order.
	 * @return array Array of shippable item data.
	 */
	protected function get_shippable_items_from_order( $order ) {
		$items = array();

		foreach ( $order->get_items() as $item_id => $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();

			if ( ! $product || ! $product->needs_shipping() ) {
				continue;
			}

			$raw_length = $product->get_length( 'edit' );
			$raw_width  = $product->get_width( 'edit' );
			$raw_height = $product->get_height( 'edit' );
			$raw_weight = $product->get_weight( 'edit' );
			$length     = (float) wc_get_dimension( $raw_length ? $raw_length : 1, 'in' );
			$width      = (float) wc_get_dimension( $raw_width ? $raw_width : 1, 'in' );
			$height     = (float) wc_get_dimension( $raw_height ? $raw_height : 1, 'in' );
			$weight     = (float) wc_get_weight( $raw_weight ? $raw_weight : 0.1, 'oz' );
			$qty        = max( 1, (int) $item->get_quantity() );

			for ( $i = 0; $i < $qty; $i++ ) {
				$items[] = array(
					'item_id'    => $item_id,
					'product_id' => $product->get_id(),
					'name'       => $item->get_name(),
					'length'     => $length,
					'width'      => $width,
					'height'     => $height,
					'weight_oz'  => $weight,
					'sku'        => $product->get_sku(),
					'keep_flat'  => false,
				);
			}
		}

		return $items;
	}

	/**
	 * Get box definitions from arguments or use defaults.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Box definitions.
	 */
	protected function get_boxes( array $arguments ) {
		if ( ! empty( $arguments['boxes'] ) && is_array( $arguments['boxes'] ) ) {
			return $this->sanitize_boxes( $arguments['boxes'] );
		}

		/**
		 * Filter the default box definitions for the shipping box packer.
		 *
		 * @since 1.2.0
		 *
		 * @param array $boxes Default box definitions.
		 */
		return apply_filters( 'wp_mcp_ai_shipping_packer_boxes', self::DEFAULT_BOXES );
	}

	/**
	 * Sanitize user-provided box definitions.
	 *
	 * @param array $raw_boxes Raw box definitions.
	 * @return array Sanitized box definitions.
	 */
	protected function sanitize_boxes( array $raw_boxes ) {
		$boxes = array();

		foreach ( $raw_boxes as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}

			$outer_length = isset( $raw['outer_length'] ) ? (float) $raw['outer_length'] : 0;
			$outer_width  = isset( $raw['outer_width'] ) ? (float) $raw['outer_width'] : 0;
			$outer_depth  = isset( $raw['outer_depth'] ) ? (float) $raw['outer_depth'] : 0;

			if ( $outer_length <= 0 || $outer_width <= 0 || $outer_depth <= 0 ) {
				continue;
			}

			$box_type = 'cubic';
			if ( isset( $raw['box_type'] ) && in_array( $raw['box_type'], array( 'cubic', 'flat_rate' ), true ) ) {
				$box_type = $raw['box_type'];
			}

			$boxes[] = array(
				'reference'    => isset( $raw['reference'] ) ? sanitize_text_field( $raw['reference'] ) : 'Custom Box',
				'package_code' => isset( $raw['package_code'] ) ? sanitize_text_field( $raw['package_code'] ) : 'package',
				'package_name' => isset( $raw['package_name'] ) ? sanitize_text_field( $raw['package_name'] ) : sanitize_text_field( $raw['reference'] ?? 'Custom Box' ),
				'box_type'     => $box_type,
				'outer_length' => $outer_length,
				'outer_width'  => $outer_width,
				'outer_depth'  => $outer_depth,
				'inner_length' => isset( $raw['inner_length'] ) ? (float) $raw['inner_length'] : $outer_length,
				'inner_width'  => isset( $raw['inner_width'] ) ? (float) $raw['inner_width'] : $outer_width,
				'inner_depth'  => isset( $raw['inner_depth'] ) ? (float) $raw['inner_depth'] : $outer_depth,
				'empty_weight' => isset( $raw['empty_weight'] ) ? max( 0, (float) $raw['empty_weight'] ) : 0,
				'max_weight'   => isset( $raw['max_weight'] ) ? max( 1, (float) $raw['max_weight'] ) : 70,
			);
		}

		return ! empty( $boxes ) ? $boxes : self::DEFAULT_BOXES;
	}

	/**
	 * Pack items into boxes using best-fit decreasing algorithm.
	 *
	 * Sorts items by volume (largest first), then for each item finds the
	 * smallest box it fits into. Multiple items may share a box if they fit
	 * within the weight and volume constraints.
	 *
	 * @param array $items Items to pack.
	 * @param array $boxes Available box definitions.
	 * @return array Packed packages.
	 */
	protected function pack_items( array $items, array $boxes ) {
		if ( empty( $items ) ) {
			return array();
		}

		// Sort boxes by inner volume (smallest first) for best-fit selection.
		usort(
			$boxes,
			function ( $a, $b ) {
				$vol_a = (float) $a['inner_length'] * (float) $a['inner_width'] * (float) $a['inner_depth'];
				$vol_b = (float) $b['inner_length'] * (float) $b['inner_width'] * (float) $b['inner_depth'];
				return $vol_a <=> $vol_b;
			}
		);

		// Sort items by volume (largest first) for best-fit decreasing.
		usort(
			$items,
			function ( $a, $b ) {
				$vol_a = $a['length'] * $a['width'] * $a['height'];
				$vol_b = $b['length'] * $b['width'] * $b['height'];
				return $vol_b <=> $vol_a;
			}
		);

		$open_packages = array(); // Packages currently being filled.
		$packages      = array();

		foreach ( $items as $item ) {
			$placed = false;

			// Try to fit into an existing open package.
			foreach ( $open_packages as &$open_pkg ) {
				if ( $this->item_fits_in_package( $item, $open_pkg ) ) {
					$open_pkg['items'][]    = $item;
					$open_pkg['weight_oz'] += (float) $item['weight_oz'];
					$placed                 = true;
					break;
				}
			}
			unset( $open_pkg );

			if ( ! $placed ) {
				// Find the smallest box this item fits into.
				$selected_box = $this->find_best_box( $item, $boxes );

				$new_package = array(
					'box'       => $selected_box,
					'items'     => array( $item ),
					'weight_oz' => (float) $item['weight_oz'],
				);

				$open_packages[] = $new_package;
			}
		}

		// Build final package results.
		$package_number = 0;
		foreach ( $open_packages as $pkg ) {
			++$package_number;
			$box          = $pkg['box'];
			$total_weight = $pkg['weight_oz'] + (float) $box['empty_weight'];

			$dimensions = array(
				'length' => (float) $box['outer_length'],
				'width'  => (float) $box['outer_width'],
				'height' => (float) $box['outer_depth'],
			);

			$packing_list = $this->build_packing_list( $pkg['items'] );
			$cubic_tier   = 'cubic' === $box['box_type'] ? $this->get_cubic_tier( $dimensions ) : '';

			$packages[] = array(
				'package_number' => $package_number,
				'box_reference'  => $box['reference'],
				'box_type'       => $box['box_type'],
				'package_code'   => $box['package_code'],
				'package_name'   => $box['package_name'],
				'dimensions'     => $dimensions,
				'weight_oz'      => round( $total_weight, 2 ),
				'item_weight_oz' => round( $pkg['weight_oz'], 2 ),
				'box_weight_oz'  => (float) $box['empty_weight'],
				'item_count'     => count( $pkg['items'] ),
				'packing_list'   => $packing_list,
				'cubic_eligible' => $this->is_cubic_eligible( $dimensions, $total_weight ),
				'cubic_tier'     => $cubic_tier,
				'items'          => $pkg['items'],
			);
		}

		return $packages;
	}

	/**
	 * Check if an item can fit into an existing open package.
	 *
	 * Checks weight constraint and a simple volume heuristic.
	 *
	 * @param array $item    Item to place.
	 * @param array $package Current open package state.
	 * @return bool Whether the item fits.
	 */
	protected function item_fits_in_package( array $item, array $package ) {
		$box = $package['box'];

		// Check weight constraint (max_weight is in pounds, weight_oz in ounces).
		$new_total_weight = $package['weight_oz'] + (float) $item['weight_oz'] + (float) $box['empty_weight'];
		if ( $new_total_weight > ( (float) $box['max_weight'] * 16 ) ) {
			return false;
		}

		// Simple volume check: total item volume must not exceed inner box volume.
		$box_volume = (float) $box['inner_length'] * (float) $box['inner_width'] * (float) $box['inner_depth'];

		$used_volume = 0;
		foreach ( $package['items'] as $packed_item ) {
			$used_volume += $packed_item['length'] * $packed_item['width'] * $packed_item['height'];
		}
		$used_volume += $item['length'] * $item['width'] * $item['height'];

		return $used_volume <= $box_volume;
	}

	/**
	 * Find the smallest box that fits a single item.
	 *
	 * @param array $item  Item data.
	 * @param array $boxes Available box definitions (pre-sorted by volume).
	 * @return array Matched box definition or a generated fallback.
	 */
	protected function find_best_box( array $item, array $boxes ) {
		// Sort item dimensions for orientation-independent matching.
		$item_dims = array( $item['length'], $item['width'], $item['height'] );
		rsort( $item_dims );

		foreach ( $boxes as $box ) {
			$box_dims = array( (float) $box['inner_length'], (float) $box['inner_width'], (float) $box['inner_depth'] );
			rsort( $box_dims );

			if (
				$item_dims[0] <= $box_dims[0] &&
				$item_dims[1] <= $box_dims[1] &&
				$item_dims[2] <= $box_dims[2] &&
				$item['weight_oz'] <= ( (float) $box['max_weight'] * 16 )
			) {
				return $box;
			}
		}

		// Fallback: generate a minimal box for this item.
		return array(
			'reference'    => __( 'Fallback Package', 'mcp-ai-wpoos-pro' ),
			'package_code' => 'package',
			'package_name' => __( 'Fallback Package', 'mcp-ai-wpoos-pro' ),
			'box_type'     => 'cubic',
			'outer_length' => max( 1, (int) ceil( $item['length'] ) ),
			'outer_width'  => max( 1, (int) ceil( $item['width'] ) ),
			'outer_depth'  => max( 1, (int) ceil( $item['height'] ) ),
			'inner_length' => max( 1, (int) ceil( $item['length'] ) ),
			'inner_width'  => max( 1, (int) ceil( $item['width'] ) ),
			'inner_depth'  => max( 1, (int) ceil( $item['height'] ) ),
			'empty_weight' => 0,
			'max_weight'   => 70,
		);
	}

	/**
	 * Build a human-readable packing list from packed items.
	 *
	 * @param array $items Packed items.
	 * @return array Human-readable packing list lines (e.g. "2x Widget").
	 */
	protected function build_packing_list( array $items ) {
		$list = array();

		foreach ( $items as $item ) {
			$key = $item['name'];

			if ( ! isset( $list[ $key ] ) ) {
				$list[ $key ] = 0;
			}
			++$list[ $key ];
		}

		$output = array();
		foreach ( $list as $name => $qty ) {
			$output[] = sprintf( '%dx %s', $qty, $name );
		}

		return $output;
	}

	/**
	 * Check whether dimensions and weight qualify for USPS cubic pricing.
	 *
	 * Rules: volume ≤ 0.5 cubic feet, longest side ≤ 18 inches, weight ≤ 20 lbs (320 oz).
	 *
	 * @param array $dimensions Package dimensions (length, width, height in inches).
	 * @param float $weight_oz  Total package weight in ounces.
	 * @return bool Whether eligible for USPS cubic pricing.
	 */
	protected function is_cubic_eligible( array $dimensions, float $weight_oz ) {
		$sides = array_values( $dimensions );
		rsort( $sides );
		$cubic_feet = ( $dimensions['length'] * $dimensions['width'] * $dimensions['height'] ) / 1728.0;

		return $cubic_feet <= 0.5 && $weight_oz <= 320 && $sides[0] <= 18;
	}

	/**
	 * Determine the USPS cubic tier for given dimensions.
	 *
	 * @param array $dimensions Package dimensions (length, width, height in inches).
	 * @return string Cubic tier value (0.1 through 0.5) or empty string.
	 */
	protected function get_cubic_tier( array $dimensions ) {
		$cubic_feet = ( $dimensions['length'] * $dimensions['width'] * $dimensions['height'] ) / 1728.0;

		if ( $cubic_feet <= 0.1 ) {
			return '0.1';
		}

		if ( $cubic_feet <= 0.2 ) {
			return '0.2';
		}

		if ( $cubic_feet <= 0.3 ) {
			return '0.3';
		}

		if ( $cubic_feet <= 0.4 ) {
			return '0.4';
		}

		if ( $cubic_feet <= 0.5 ) {
			return '0.5';
		}

		return '';
	}
}
