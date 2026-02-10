<?php
/**
 * Track Inventory Movement Tool
 *
 * Track stock movements and changes with audit trail, movement history,
 * and location tracking.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for tracking inventory movements.
 *
 * Supports:
 * - Stock movement tracking
 * - Audit trail maintenance
 * - Movement history reports
 * - Location-based tracking
 * - Transaction logging
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Track_Inventory_Movement implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if WooCommerce is active and toolkit is enabled.
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

		// Check if e-commerce toolkit is enabled.
		return function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && wp_mcp_ai_is_ecommerce_toolkit_enabled();
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return __( 'Inventory movement tracking requires WooCommerce to be installed and activated.', 'mcp-ai-wpoos-pro' );
		}

		if ( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
			return __( 'E-commerce toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Inventory movement tracking tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'track_inventory_movement';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Track Inventory Movement', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Track and audit inventory movements across your store. Monitor stock changes, location transfers, order fulfillment, and manual adjustments. Maintains complete audit trail for compliance and analysis.', 'mcp-ai-wpoos-pro' );
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
				'action'        => array(
					'type'        => 'string',
					'description' => __( 'Action to perform', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'log', 'get_history', 'get_report', 'get_summary' ),
					'default'     => 'get_history',
				),
				'product_id'    => array(
					'type'        => 'integer',
					'description' => __( 'Product ID to track', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'movement'      => array(
					'type'        => 'object',
					'description' => __( 'Movement data to log', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'product_id'    => array(
							'type'        => 'integer',
							'description' => __( 'Product ID', 'mcp-ai-wpoos-pro' ),
						),
						'quantity'      => array(
							'type'        => 'integer',
							'description' => __( 'Quantity moved (positive for increase, negative for decrease)', 'mcp-ai-wpoos-pro' ),
						),
						'movement_type' => array(
							'type'        => 'string',
							'description' => __( 'Type of movement', 'mcp-ai-wpoos-pro' ),
							'enum'        => array( 'sale', 'return', 'restock', 'adjustment', 'transfer', 'damage', 'loss' ),
						),
						'from_location' => array(
							'type'        => 'string',
							'description' => __( 'Source location', 'mcp-ai-wpoos-pro' ),
						),
						'to_location'   => array(
							'type'        => 'string',
							'description' => __( 'Destination location', 'mcp-ai-wpoos-pro' ),
						),
						'reference_id'  => array(
							'type'        => 'integer',
							'description' => __( 'Reference ID (e.g., order ID)', 'mcp-ai-wpoos-pro' ),
						),
						'notes'         => array(
							'type'        => 'string',
							'description' => __( 'Additional notes', 'mcp-ai-wpoos-pro' ),
						),
					),
				),
				'start_date'    => array(
					'type'        => 'string',
					'description' => __( 'Start date for history (Y-m-d format)', 'mcp-ai-wpoos-pro' ),
				),
				'end_date'      => array(
					'type'        => 'string',
					'description' => __( 'End date for history (Y-m-d format)', 'mcp-ai-wpoos-pro' ),
				),
				'movement_type' => array(
					'type'        => 'string',
					'description' => __( 'Filter by movement type', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'sale', 'return', 'restock', 'adjustment', 'transfer', 'damage', 'loss' ),
				),
				'location'      => array(
					'type'        => 'string',
					'description' => __( 'Filter by location', 'mcp-ai-wpoos-pro' ),
				),
				'limit'         => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of records to return', 'mcp-ai-wpoos-pro' ),
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 500,
				),
			),
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
			'database-read',
			'database-write',
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
		// Check permissions.
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_woocommerce' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to track inventory movements.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if WooCommerce is active.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'woocommerce_not_active',
				self::get_unavailable_reason()
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : 'get_history';

		switch ( $action ) {
			case 'log':
				return $this->log_movement( $arguments );
			case 'get_history':
				return $this->get_movement_history( $arguments );
			case 'get_report':
				return $this->get_movement_report( $arguments );
			case 'get_summary':
				return $this->get_movement_summary( $arguments );
			default:
				return new WP_Error(
					'invalid_action',
					__( 'Invalid action specified.', 'mcp-ai-wpoos-pro' )
				);
		}
	}

	/**
	 * Log inventory movement.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Result.
	 */
	protected function log_movement( $arguments ) {
		$movement = isset( $arguments['movement'] ) && is_array( $arguments['movement'] ) ? $arguments['movement'] : array();

		if ( empty( $movement ) ) {
			return new WP_Error(
				'missing_movement_data',
				__( 'Movement data is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$product_id    = isset( $movement['product_id'] ) ? absint( $movement['product_id'] ) : 0;
		$quantity      = isset( $movement['quantity'] ) ? intval( $movement['quantity'] ) : 0;
		$movement_type = isset( $movement['movement_type'] ) ? sanitize_text_field( $movement['movement_type'] ) : 'adjustment';

		if ( ! $product_id || 0 === $quantity ) {
			return new WP_Error(
				'invalid_movement_data',
				__( 'Valid product ID and non-zero quantity are required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error(
				'product_not_found',
				__( 'Product not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Get current stock.
		$old_stock = $product->get_stock_quantity();
		$new_stock = $old_stock + $quantity;

		// Log the movement.
		$movement_data = array(
			'product_id'    => $product_id,
			'product_name'  => $product->get_name(),
			'old_stock'     => $old_stock,
			'new_stock'     => $new_stock,
			'quantity'      => $quantity,
			'movement_type' => $movement_type,
			'from_location' => isset( $movement['from_location'] ) ? sanitize_text_field( $movement['from_location'] ) : '',
			'to_location'   => isset( $movement['to_location'] ) ? sanitize_text_field( $movement['to_location'] ) : '',
			'reference_id'  => isset( $movement['reference_id'] ) ? absint( $movement['reference_id'] ) : 0,
			'notes'         => isset( $movement['notes'] ) ? sanitize_textarea_field( $movement['notes'] ) : '',
			'user_id'       => get_current_user_id(),
			'timestamp'     => current_time( 'mysql' ),
		);

		// Store movement log.
		$this->store_movement_log( $movement_data );

		// Update product stock.
		$product->set_stock_quantity( $new_stock );
		$product->save();

		return array(
			'success'  => true,
			'action'   => 'log',
			'movement' => $movement_data,
			'message'  => sprintf(
				/* translators: 1: Quantity, 2: Product name */
				__( 'Logged movement of %1$d for %2$s.', 'mcp-ai-wpoos-pro' ),
				$quantity,
				$product->get_name()
			),
		);
	}

	/**
	 * Store movement log.
	 *
	 * @param array $movement_data Movement data.
	 */
	protected function store_movement_log( $movement_data ) {
		// Get existing log.
		$log_option = 'wp_mcp_ai_inventory_movements';
		$movements  = get_option( $log_option, array() );

		if ( ! is_array( $movements ) ) {
			$movements = array();
		}

		// Add new movement.
		$movements[] = $movement_data;

		// Keep only last 1000 movements.
		if ( count( $movements ) > 1000 ) {
			$movements = array_slice( $movements, -1000 );
		}

		update_option( $log_option, $movements );

		// Also store in product meta.
		$product_id  = $movement_data['product_id'];
		$product_log = get_post_meta( $product_id, '_inventory_movement_log', true );
		if ( ! is_array( $product_log ) ) {
			$product_log = array();
		}

		$product_log[] = $movement_data;

		// Keep only last 100 entries per product.
		if ( count( $product_log ) > 100 ) {
			$product_log = array_slice( $product_log, -100 );
		}

		update_post_meta( $product_id, '_inventory_movement_log', $product_log );
	}

	/**
	 * Get movement history.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Result.
	 */
	protected function get_movement_history( $arguments ) {
		$product_id    = isset( $arguments['product_id'] ) ? absint( $arguments['product_id'] ) : 0;
		$start_date    = isset( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : '';
		$end_date      = isset( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : '';
		$movement_type = isset( $arguments['movement_type'] ) ? sanitize_text_field( $arguments['movement_type'] ) : '';
		$location      = isset( $arguments['location'] ) ? sanitize_text_field( $arguments['location'] ) : '';
		$limit         = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 50;

		// Get movements.
		if ( $product_id ) {
			$movements = get_post_meta( $product_id, '_inventory_movement_log', true );
			if ( ! is_array( $movements ) ) {
				$movements = array();
			}
		} else {
			$movements = get_option( 'wp_mcp_ai_inventory_movements', array() );
			if ( ! is_array( $movements ) ) {
				$movements = array();
			}
		}

		// Apply filters.
		$filtered_movements = array();
		foreach ( $movements as $movement ) {
			// Filter by date.
			if ( $start_date && isset( $movement['timestamp'] ) && strtotime( $movement['timestamp'] ) < strtotime( $start_date ) ) {
				continue;
			}
			if ( $end_date && isset( $movement['timestamp'] ) && strtotime( $movement['timestamp'] ) > strtotime( $end_date . ' 23:59:59' ) ) {
				continue;
			}

			// Filter by movement type.
			if ( $movement_type && isset( $movement['movement_type'] ) && $movement['movement_type'] !== $movement_type ) {
				continue;
			}

			// Filter by location.
			if ( $location ) {
				$has_location = ( isset( $movement['from_location'] ) && $movement['from_location'] === $location ) ||
								( isset( $movement['to_location'] ) && $movement['to_location'] === $location );
				if ( ! $has_location ) {
					continue;
				}
			}

			$filtered_movements[] = $movement;
		}

		// Sort by timestamp descending.
		usort(
			$filtered_movements,
			function ( $a, $b ) {
				$time_a = isset( $a['timestamp'] ) ? strtotime( $a['timestamp'] ) : 0;
				$time_b = isset( $b['timestamp'] ) ? strtotime( $b['timestamp'] ) : 0;
				return $time_b - $time_a;
			}
		);

		// Limit results.
		$filtered_movements = array_slice( $filtered_movements, 0, $limit );

		return array(
			'success'       => true,
			'action'        => 'get_history',
			'product_id'    => $product_id,
			'total_records' => count( $filtered_movements ),
			'movements'     => $filtered_movements,
			'message'       => sprintf(
				/* translators: %d: Number of records */
				__( 'Retrieved %d movement records.', 'mcp-ai-wpoos-pro' ),
				count( $filtered_movements )
			),
		);
	}

	/**
	 * Get movement report.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Result.
	 */
	protected function get_movement_report( $arguments ) {
		$history = $this->get_movement_history( $arguments );

		if ( is_wp_error( $history ) ) {
			return $history;
		}

		$movements = $history['movements'];

		// Calculate statistics.
		$by_type     = array();
		$by_location = array();
		$total_in    = 0;
		$total_out   = 0;

		foreach ( $movements as $movement ) {
			$type     = isset( $movement['movement_type'] ) ? $movement['movement_type'] : 'unknown';
			$quantity = isset( $movement['quantity'] ) ? intval( $movement['quantity'] ) : 0;

			// Count by type.
			if ( ! isset( $by_type[ $type ] ) ) {
				$by_type[ $type ] = array(
					'count'    => 0,
					'quantity' => 0,
				);
			}
			++$by_type[ $type ]['count'];
			$by_type[ $type ]['quantity'] += $quantity;

			// Track in/out.
			if ( $quantity > 0 ) {
				$total_in += $quantity;
			} else {
				$total_out += abs( $quantity );
			}

			// Count by location.
			if ( isset( $movement['from_location'] ) && $movement['from_location'] ) {
				$loc = $movement['from_location'];
				if ( ! isset( $by_location[ $loc ] ) ) {
					$by_location[ $loc ] = 0;
				}
				$by_location[ $loc ] += abs( $quantity );
			}
			if ( isset( $movement['to_location'] ) && $movement['to_location'] ) {
				$loc = $movement['to_location'];
				if ( ! isset( $by_location[ $loc ] ) ) {
					$by_location[ $loc ] = 0;
				}
				$by_location[ $loc ] += abs( $quantity );
			}
		}

		return array(
			'success' => true,
			'action'  => 'get_report',
			'report'  => array(
				'total_movements' => count( $movements ),
				'total_in'        => $total_in,
				'total_out'       => $total_out,
				'net_change'      => $total_in - $total_out,
				'by_type'         => $by_type,
				'by_location'     => $by_location,
			),
			'message' => __( 'Movement report generated.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Get movement summary.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Result.
	 */
	protected function get_movement_summary( $arguments ) {
		$product_id = isset( $arguments['product_id'] ) ? absint( $arguments['product_id'] ) : 0;

		if ( ! $product_id ) {
			return new WP_Error(
				'missing_product_id',
				__( 'Product ID is required for movement summary.', 'mcp-ai-wpoos-pro' )
			);
		}

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new WP_Error(
				'product_not_found',
				__( 'Product not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Get recent movements.
		$movements = get_post_meta( $product_id, '_inventory_movement_log', true );
		if ( ! is_array( $movements ) ) {
			$movements = array();
		}

		// Get last 30 days of movements.
		$thirty_days_ago  = strtotime( '-30 days' );
		$recent_movements = array_filter(
			$movements,
			function ( $movement ) use ( $thirty_days_ago ) {
				return isset( $movement['timestamp'] ) && strtotime( $movement['timestamp'] ) >= $thirty_days_ago;
			}
		);

		$total_movements = count( $recent_movements );
		$total_in        = 0;
		$total_out       = 0;

		foreach ( $recent_movements as $movement ) {
			$quantity = isset( $movement['quantity'] ) ? intval( $movement['quantity'] ) : 0;
			if ( $quantity > 0 ) {
				$total_in += $quantity;
			} else {
				$total_out += abs( $quantity );
			}
		}

		return array(
			'success' => true,
			'action'  => 'get_summary',
			'product' => array(
				'id'            => $product->get_id(),
				'name'          => $product->get_name(),
				'current_stock' => $product->get_stock_quantity(),
			),
			'summary' => array(
				'period_days'          => 30,
				'total_movements'      => $total_movements,
				'total_in'             => $total_in,
				'total_out'            => $total_out,
				'net_change'           => $total_in - $total_out,
				'average_daily_change' => $total_movements > 0 ? round( ( $total_in - $total_out ) / 30, 2 ) : 0,
			),
			'message' => __( 'Movement summary generated.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
