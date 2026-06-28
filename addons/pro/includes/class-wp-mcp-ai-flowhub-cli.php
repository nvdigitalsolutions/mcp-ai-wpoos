<?php
/**
 * FlowHub WP-CLI Commands.
 *
 * Registers WP-CLI commands for managing the FlowHub Inventory Sync Pro Toolkit.
 * Provides status checks, manual sync triggers, cache management,
 * connection testing, and compliance reporting.
 *
 * Usage:
 *   wp flowhub status
 *   wp flowhub trigger
 *   wp flowhub clear-cache [--force]
 *   wp flowhub test-connection
 *   wp flowhub compliance-report [--days=7]
 *   wp flowhub low-stock-report
 *
 * @package WP_MCP_AI_Pro
 * @since 1.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * FlowHub WP-CLI Commands.
 *
 * @since 1.4.0
 */
class WP_MCP_AI_FlowHub_CLI {

	/**
	 * Show FlowHub sync status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp flowhub status
	 *     wp flowhub status --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( $args, $assoc_args ) {
		if ( ! class_exists( 'WP_MCP_AI_FlowHub_CCT_Manager' ) ) {
			WP_CLI::error( 'FlowHub CCT Manager is not available.' );
			return;
		}

		$cct_manager = new WP_MCP_AI_FlowHub_CCT_Manager();
		$last_sync   = $cct_manager->get_last_sync_time();
		$row_count   = $cct_manager->get_row_count();
		$is_fresh    = $cct_manager->is_fresh();
		$last_error  = get_option( 'wp_mcp_ai_flowhub_last_sync_error', '' );
		$cct_slug    = $cct_manager->get_cct_slug();

		// Get next scheduled sync.
		$next_sync = '';
		if ( function_exists( 'as_next_scheduled_action' ) && class_exists( 'WP_MCP_AI_FlowHub_Sync_Engine' ) ) {
			$timestamp = as_next_scheduled_action(
				WP_MCP_AI_FlowHub_Sync_Engine::HOOK_FULL_SYNC,
				array(),
				WP_MCP_AI_FlowHub_Sync_Engine::GROUP
			);
			$next_sync = $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : __( 'Not scheduled', 'mcp-ai-wpoos-pro' );
		}

		// API usage stats.
		$requests_today     = absint( get_option( 'wp_mcp_ai_flowhub_api_requests_today', 0 ) );
		$rate_limit_hits    = absint( get_option( 'wp_mcp_ai_flowhub_api_rate_limit_hits', 0 ) );
		$last_sync_duration = get_option( 'wp_mcp_ai_flowhub_last_sync_duration', '' );

		$rows = array(
			array(
				'Metric' => 'CCT Slug',
				'Value'  => $cct_slug,
			),
			array(
				'Metric' => 'Last Sync',
				'Value'  => ! empty( $last_sync ) ? $last_sync : 'Never',
			),
			array(
				'Metric' => 'Next Sync',
				'Value'  => $next_sync,
			),
			array(
				'Metric' => 'CCT Rows',
				'Value'  => (string) $row_count,
			),
			array(
				'Metric' => 'Fresh',
				'Value'  => $is_fresh ? 'Yes' : 'No',
			),
			array(
				'Metric' => 'API Requests Today',
				'Value'  => (string) $requests_today,
			),
			array(
				'Metric' => 'Rate Limit Hits',
				'Value'  => (string) $rate_limit_hits,
			),
			array(
				'Metric' => 'Last Sync Duration',
				'Value'  => ! empty( $last_sync_duration ) ? $last_sync_duration . 's' : 'N/A',
			),
			array(
				'Metric' => 'Last Error',
				'Value'  => ! empty( $last_error ) ? $last_error : 'None',
			),
		);

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		WP_CLI\Utils\format_items( $format, $rows, array( 'Metric', 'Value' ) );
	}

	/**
	 * Trigger a full sync from FlowHub API.
	 *
	 * ## EXAMPLES
	 *
	 *     wp flowhub trigger
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments (reserved for future options).
	 */
	public function trigger( $args, $assoc_args ) {
		unset( $assoc_args ); // Reserved for future options.

		if ( ! class_exists( 'WP_MCP_AI_FlowHub_CCT_Manager' ) ) {
			WP_CLI::error( 'FlowHub CCT Manager is not available.' );
			return;
		}

		WP_CLI::log( 'Starting full sync from FlowHub API...' );

		$cct_manager = new WP_MCP_AI_FlowHub_CCT_Manager();
		$result      = $cct_manager->sync_from_api(
			true,
			function ( $page, $total_pages, $item_count ) {
				$pct = $total_pages > 0 ? round( ( $page / $total_pages ) * 100, 1 ) : 0;
				WP_CLI::log( sprintf( '  Page %d/%d (%s%%) — %d items', $page, $total_pages, $pct, $item_count ) );
			}
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		WP_CLI::success(
			sprintf(
				'Sync completed: %d items across %d locations (took %ss). %d errors.',
				isset( $result['item_count'] ) ? $result['item_count'] : 0,
				isset( $result['location_count'] ) ? $result['location_count'] : 0,
				isset( $result['duration'] ) ? $result['duration'] : 0,
				isset( $result['error_count'] ) ? $result['error_count'] : 0
			)
		);
	}

	/**
	 * Clear the CCT cache.
	 *
	 * ## OPTIONS
	 *
	 * [--force]
	 * : Required to confirm cache clearing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp flowhub clear-cache --force
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments (reserved for future options).
	 */
	public function clear_cache( $args, $assoc_args ) {
		unset( $assoc_args );

		if ( ! isset( $assoc_args['force'] ) ) {
			WP_CLI::confirm( 'Are you sure you want to clear the FlowHub CCT cache? This will delete all cached inventory data.' );
		}

		if ( ! class_exists( 'WP_MCP_AI_FlowHub_CCT_Manager' ) ) {
			WP_CLI::error( 'FlowHub CCT Manager is not available.' );
			return;
		}

		$cct_manager = new WP_MCP_AI_FlowHub_CCT_Manager();
		$result      = $cct_manager->truncate();

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		delete_option( 'wp_mcp_ai_flowhub_last_sync' );
		WP_CLI::success( 'CCT cache cleared. Run `wp flowhub trigger` to repopulate.' );
	}

	/**
	 * Test the FlowHub API connection.
	 *
	 * ## EXAMPLES
	 *
	 *     wp flowhub test-connection
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments (reserved for future options).
	 */
	public function test_connection( $args, $assoc_args ) {
		unset( $assoc_args );

		if ( ! class_exists( 'WP_MCP_AI_FlowHub_Client' ) ) {
			WP_CLI::error( 'FlowHub Client is not available.' );
			return;
		}

		WP_CLI::log( 'Testing FlowHub API connection...' );

		$client = WP_MCP_AI_FlowHub_Client::from_settings();

		if ( is_wp_error( $client ) ) {
			WP_CLI::error( $client->get_error_message() );
			return;
		}

		$result = $client->check_connection();

		if ( is_wp_error( $result ) ) {
			$code = $client->get_last_response_code();
			WP_CLI::error( sprintf( 'Connection failed (HTTP %s): %s', ! empty( $code ) ? $code : 'N/A', $result->get_error_message() ) );
			return;
		}

		WP_CLI::success( 'FlowHub API connection is healthy.' );
	}

	/**
	 * Show a compliance audit report.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<number>]
	 * : Number of days of history. Default: 7.
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp flowhub compliance-report
	 *     wp flowhub compliance-report --days=30 --format=csv
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function compliance_report( $args, $assoc_args ) {
		if ( ! class_exists( 'WP_MCP_AI_FlowHub_CCT_Manager' ) ) {
			WP_CLI::error( 'FlowHub CCT Manager is not available.' );
			return;
		}

		$cct_manager = new WP_MCP_AI_FlowHub_CCT_Manager();
		$items       = $cct_manager->get_cached_items(
			array(
				'per_page' => 100,
				'orderby'  => 'last_updated',
			)
		);

		if ( empty( $items ) ) {
			WP_CLI::log( 'No inventory data in cache. Run a sync first.' );
			return;
		}

		$settings      = get_option( 'wp_mcp_ai_flowhub_toolkit_settings', array() );
		$low_threshold = isset( $settings['low_stock_threshold'] ) ? absint( $settings['low_stock_threshold'] ) : 5;

		$in_stock     = 0;
		$low_stock    = 0;
		$out_of_stock = 0;
		$total_value  = 0.0;
		$locations    = array();

		foreach ( $items as $item ) {
			$qty          = absint( isset( $item['quantity'] ) ? $item['quantity'] : 0 );
			$price        = floatval( isset( $item['price'] ) ? $item['price'] : 0.0 );
			$total_value += $qty * $price;

			if ( $qty >= $low_threshold ) {
				++$in_stock;
			} elseif ( $qty > 0 ) {
				++$low_stock;
			} else {
				++$out_of_stock;
			}

			$loc = isset( $item['location_name'] ) ? $item['location_name'] : 'Unknown';
			if ( ! isset( $locations[ $loc ] ) ) {
				$locations[ $loc ] = 0;
			}
			++$locations[ $loc ];
		}

		$rows = array(
			array(
				'Metric' => 'Total Items',
				'Value'  => (string) count( $items ),
			),
			array(
				'Metric' => 'Total Value',
				'Value'  => '$' . number_format( $total_value, 2 ),
			),
			array(
				'Metric' => 'In Stock',
				'Value'  => (string) $in_stock,
			),
			array(
				'Metric' => 'Low Stock (below ' . $low_threshold . ')',
				'Value'  => (string) $low_stock,
			),
			array(
				'Metric' => 'Out of Stock',
				'Value'  => (string) $out_of_stock,
			),
			array(
				'Metric' => 'Locations',
				'Value'  => (string) count( $locations ),
			),
			array(
				'Metric' => 'Last Sync',
				'Value'  => $cct_manager->get_last_sync_time() ? $cct_manager->get_last_sync_time() : 'Never',
			),
		);

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		WP_CLI\Utils\format_items( $format, $rows, array( 'Metric', 'Value' ) );

		if ( 'table' === $format ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Location Breakdown:' );
			$loc_rows = array();
			foreach ( $locations as $name => $count ) {
				$loc_rows[] = array(
					'Location' => $name,
					'Items'    => (string) $count,
				);
			}
			WP_CLI\Utils\format_items( 'table', $loc_rows, array( 'Location', 'Items' ) );
		}
	}

	/**
	 * Show low-stock items report.
	 *
	 * ## OPTIONS
	 *
	 * [--threshold=<number>]
	 * : Override the low-stock threshold. Uses configured threshold if omitted.
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp flowhub low-stock-report
	 *     wp flowhub low-stock-report --threshold=10
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function low_stock_report( $args, $assoc_args ) {
		if ( ! class_exists( 'WP_MCP_AI_FlowHub_CCT_Manager' ) ) {
			WP_CLI::error( 'FlowHub CCT Manager is not available.' );
			return;
		}

		$settings      = get_option( 'wp_mcp_ai_flowhub_toolkit_settings', array() );
		$low_threshold = isset( $assoc_args['threshold'] ) ? absint( $assoc_args['threshold'] ) : absint( isset( $settings['low_stock_threshold'] ) ? $settings['low_stock_threshold'] : 5 );

		$cct_manager = new WP_MCP_AI_FlowHub_CCT_Manager();
		$items       = $cct_manager->get_cached_items( array( 'per_page' => 100 ) );

		$low_items = array();
		$out_items = array();

		foreach ( $items as $item ) {
			$qty = absint( isset( $item['quantity'] ) ? $item['quantity'] : 0 );

			if ( $qty <= 0 ) {
				$out_items[] = array(
					'SKU'      => isset( $item['sku'] ) ? $item['sku'] : '',
					'Product'  => isset( $item['product_name'] ) ? $item['product_name'] : '',
					'Location' => isset( $item['location_name'] ) ? $item['location_name'] : '',
					'Qty'      => '0 (OUT)',
					'Category' => isset( $item['category'] ) ? $item['category'] : '',
				);
			} elseif ( $qty < $low_threshold ) {
				$low_items[] = array(
					'SKU'      => isset( $item['sku'] ) ? $item['sku'] : '',
					'Product'  => isset( $item['product_name'] ) ? $item['product_name'] : '',
					'Location' => isset( $item['location_name'] ) ? $item['location_name'] : '',
					'Qty'      => (string) $qty,
					'Category' => isset( $item['category'] ) ? $item['category'] : '',
				);
			}
		}

		if ( empty( $low_items ) && empty( $out_items ) ) {
			WP_CLI::success( sprintf( 'No items below threshold %d.', $low_threshold ) );
			return;
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		if ( ! empty( $out_items ) ) {
			WP_CLI::log( sprintf( '=== OUT OF STOCK (%d items) ===', count( $out_items ) ) );
			WP_CLI\Utils\format_items( $format, $out_items, array( 'SKU', 'Product', 'Location', 'Qty', 'Category' ) );
			WP_CLI::log( '' );
		}

		if ( ! empty( $low_items ) ) {
			WP_CLI::log( sprintf( '=== LOW STOCK (below %d, %d items) ===', $low_threshold, count( $low_items ) ) );
			WP_CLI\Utils\format_items( $format, $low_items, array( 'SKU', 'Product', 'Location', 'Qty', 'Category' ) );
		}

		WP_CLI::log( sprintf( 'Total: %d out of stock, %d low stock, %d total items analyzed.', count( $out_items ), count( $low_items ), count( $items ) ) );
	}
}

// Register commands.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'flowhub status', array( 'WP_MCP_AI_FlowHub_CLI', 'status' ) );
	WP_CLI::add_command( 'flowhub trigger', array( 'WP_MCP_AI_FlowHub_CLI', 'trigger' ) );
	WP_CLI::add_command( 'flowhub clear-cache', array( 'WP_MCP_AI_FlowHub_CLI', 'clear_cache' ) );
	WP_CLI::add_command( 'flowhub test-connection', array( 'WP_MCP_AI_FlowHub_CLI', 'test_connection' ) );
	WP_CLI::add_command( 'flowhub compliance-report', array( 'WP_MCP_AI_FlowHub_CLI', 'compliance_report' ) );
	WP_CLI::add_command( 'flowhub low-stock-report', array( 'WP_MCP_AI_FlowHub_CLI', 'low_stock_report' ) );
}
