<?php
/**
 * EZuite WP-CLI Commands.
 *
 * Registers WP-CLI commands for managing the EZuite Inventory Sync Pro Toolkit.
 * Provides status checks, manual sync triggers, cache management,
 * connection testing, and low-stock reporting.
 *
 * Usage:
 *   wp ezuite status
 *   wp ezuite trigger
 *   wp ezuite clear-cache [--force]
 *   wp ezuite test-connection [--id=<connection_id>]
 *   wp ezuite low-stock-report
 *
 * @package WP_MCP_AI_Pro
 * @since 1.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * EZuite WP-CLI Commands.
 *
 * @since 1.9.0
 */
class WP_MCP_AI_EZuite_CLI {

	/**
	 * Show EZuite sync status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ezuite status
	 *     wp ezuite status --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( $args, $assoc_args ) {
		if ( ! class_exists( 'WP_MCP_AI_EZuite_CCT_Manager' ) ) {
			WP_CLI::error( 'EZuite CCT Manager is not available.' );
			return;
		}

		$cct_manager = new WP_MCP_AI_EZuite_CCT_Manager();
		$last_sync   = $cct_manager->get_last_sync_time();
		$row_count   = $cct_manager->get_row_count();
		$is_fresh    = $cct_manager->is_fresh();
		$last_error  = get_option( 'wp_mcp_ai_ezuite_last_sync_error', '' );
		$cct_slug    = $cct_manager->get_cct_slug();

		// Get next scheduled sync.
		$next_sync = '';
		if ( function_exists( 'as_next_scheduled_action' ) && class_exists( 'WP_MCP_AI_EZuite_Sync_Engine' ) ) {
			$timestamp = as_next_scheduled_action(
				WP_MCP_AI_EZuite_Sync_Engine::HOOK_FULL_SYNC,
				array(),
				WP_MCP_AI_EZuite_Sync_Engine::GROUP
			);
			$next_sync = $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : __( 'Not scheduled', 'mcp-ai-wpoos-pro' );
		}

		// API usage stats.
		$requests_today     = absint( get_option( 'wp_mcp_ai_ezuite_api_requests_today', 0 ) );
		$rate_limit_hits    = absint( get_option( 'wp_mcp_ai_ezuite_api_rate_limit_hits', 0 ) );
		$last_sync_duration = get_option( 'wp_mcp_ai_ezuite_last_sync_duration', '' );

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
	 * Trigger a full sync and WC sync from EZuite API.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ezuite trigger
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments (reserved for future options).
	 */
	public function trigger( $args, $assoc_args ) {
		unset( $assoc_args ); // Reserved for future options.

		if ( ! class_exists( 'WP_MCP_AI_EZuite_Sync_Engine' ) ) {
			WP_CLI::error( 'EZuite Sync Engine is not available.' );
			return;
		}

		WP_CLI::log( __( 'Dispatching full sync from EZuite API...', 'mcp-ai-wpoos-pro' ) );

		// Dispatch full sync via Action Scheduler.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				WP_MCP_AI_EZuite_Sync_Engine::HOOK_FULL_SYNC,
				array(),
				WP_MCP_AI_EZuite_Sync_Engine::GROUP
			);
			WP_CLI::log( __( 'Full sync action enqueued.', 'mcp-ai-wpoos-pro' ) );
		} else {
			// Fallback: run synchronously.
			WP_MCP_AI_EZuite_Sync_Engine::run_full_sync();
			WP_CLI::log( __( 'Full sync executed synchronously.', 'mcp-ai-wpoos-pro' ) );
		}

		WP_CLI::log( __( 'Dispatching WC sync...', 'mcp-ai-wpoos-pro' ) );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action(
				WP_MCP_AI_EZuite_Sync_Engine::HOOK_WC_SYNC,
				array(),
				WP_MCP_AI_EZuite_Sync_Engine::GROUP_WC
			);
			WP_CLI::log( __( 'WC sync action enqueued.', 'mcp-ai-wpoos-pro' ) );
		} else {
			WP_MCP_AI_EZuite_Sync_Engine::run_wc_sync();
			WP_CLI::log( __( 'WC sync executed synchronously.', 'mcp-ai-wpoos-pro' ) );
		}

		WP_CLI::success( __( 'Sync dispatched. Actions will be picked up by Action Scheduler.', 'mcp-ai-wpoos-pro' ) );
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
	 *     wp ezuite clear-cache --force
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function clear_cache( $args, $assoc_args ) {
		unset( $args );

		if ( ! isset( $assoc_args['force'] ) ) {
			WP_CLI::confirm( __( 'Are you sure you want to clear the EZuite CCT cache? This will delete all cached inventory data.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_EZuite_CCT_Manager' ) ) {
			WP_CLI::error( 'EZuite CCT Manager is not available.' );
			return;
		}

		$cct_manager = new WP_MCP_AI_EZuite_CCT_Manager();
		$result      = $cct_manager->truncate();

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		delete_option( 'wp_mcp_ai_ezuite_last_sync' );
		WP_CLI::success( __( 'CCT cache cleared. Run `wp ezuite trigger` to repopulate.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Test an EZuite ERP connection.
	 *
	 * ## OPTIONS
	 *
	 * [--id=<connection_id>]
	 * : Remote Sites connection ID to test.
	 *
	 * ## EXAMPLES
	 *
	 *     wp ezuite test-connection
	 *     wp ezuite test-connection --id=conn_abc123
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function test_connection( $args, $assoc_args ) {
		unset( $args );

		$connection_id = isset( $assoc_args['id'] ) ? sanitize_text_field( $assoc_args['id'] ) : '';

		WP_CLI::log( __( 'Testing EZuite ERP connection...', 'mcp-ai-wpoos-pro' ) );

		if ( empty( $connection_id ) ) {
			// Try to get the first available connection from settings.
			$settings      = get_option( 'wp_mcp_ai_ezuite_toolkit_settings', array() );
			$connection_id = isset( $settings['connection_id'] ) ? $settings['connection_id'] : '';

			if ( empty( $connection_id ) ) {
				WP_CLI::error( __( 'No connection ID provided. Use --id=<connection_id> or configure one in EZuite Toolkit Settings.', 'mcp-ai-wpoos-pro' ) );
				return;
			}
		}

		// Use the ezuite_erp connector if available.
		if ( class_exists( 'WP_MCP_AI_EZuite_Connector' ) ) {
			$connector = new WP_MCP_AI_EZuite_Connector( $connection_id );
			$result    = $connector->test_connection();

			if ( is_wp_error( $result ) ) {
				WP_CLI::error( sprintf( 'Connection failed: %s', $result->get_error_message() ) );
				return;
			}

			WP_CLI::success( __( 'EZuite ERP connection is healthy.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		// Fallback: test via settings-based API call.
		$settings = get_option( 'wp_mcp_ai_ezuite_toolkit_settings', array() );
		$api_url  = isset( $settings['api_url'] ) ? $settings['api_url'] : '';

		if ( empty( $api_url ) ) {
			WP_CLI::error( __( 'No API URL configured in EZuite Toolkit Settings.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		$response = wp_remote_get(
			$api_url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept' => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			WP_CLI::error( sprintf( 'Connection failed: %s', $response->get_error_message() ) );
			return;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( $status_code >= 200 && $status_code < 300 ) {
			WP_CLI::success(
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'EZuite ERP connection is healthy (HTTP %d).', 'mcp-ai-wpoos-pro' ),
					$status_code
				)
			);
		} else {
			WP_CLI::error(
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Connection returned HTTP %d.', 'mcp-ai-wpoos-pro' ),
					$status_code
				)
			);
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
	 *     wp ezuite low-stock-report
	 *     wp ezuite low-stock-report --threshold=10
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function low_stock_report( $args, $assoc_args ) {
		unset( $args );

		if ( ! class_exists( 'WP_MCP_AI_EZuite_CCT_Manager' ) ) {
			WP_CLI::error( 'EZuite CCT Manager is not available.' );
			return;
		}

		$settings      = get_option( 'wp_mcp_ai_ezuite_toolkit_settings', array() );
		$low_threshold = isset( $assoc_args['threshold'] ) ? absint( $assoc_args['threshold'] ) : absint( isset( $settings['low_stock_threshold'] ) ? $settings['low_stock_threshold'] : 5 );

		$cct_manager = new WP_MCP_AI_EZuite_CCT_Manager();
		$items       = $cct_manager->get_cached_items( array( 'per_page' => 100 ) );

		$low_items = array();
		$out_items = array();

		foreach ( $items as $item ) {
			$qty = absint( isset( $item['quantity'] ) ? $item['quantity'] : 0 );

			if ( $qty <= 0 ) {
				$out_items[] = array(
					'SKU'         => isset( $item['sku'] ) ? $item['sku'] : '',
					'Name'        => isset( $item['product_name'] ) ? $item['product_name'] : '',
					'Qty'         => '0 (OUT)',
					'Warehouse'   => isset( $item['location_name'] ) ? $item['location_name'] : '',
					'Reorder Pt.' => isset( $item['reorder_point'] ) ? (string) $item['reorder_point'] : '',
				);
			} elseif ( $qty < $low_threshold ) {
				$low_items[] = array(
					'SKU'         => isset( $item['sku'] ) ? $item['sku'] : '',
					'Name'        => isset( $item['product_name'] ) ? $item['product_name'] : '',
					'Qty'         => (string) $qty,
					'Warehouse'   => isset( $item['location_name'] ) ? $item['location_name'] : '',
					'Reorder Pt.' => isset( $item['reorder_point'] ) ? (string) $item['reorder_point'] : '',
				);
			}
		}

		if ( empty( $low_items ) && empty( $out_items ) ) {
			WP_CLI::success(
				sprintf(
					/* translators: %d: threshold value */
					__( 'No items below threshold %d.', 'mcp-ai-wpoos-pro' ),
					$low_threshold
				)
			);
			return;
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		if ( ! empty( $out_items ) ) {
			WP_CLI::log(
				sprintf(
					/* translators: %d: number of out of stock items */
					__( '=== OUT OF STOCK (%d items) ===', 'mcp-ai-wpoos-pro' ),
					count( $out_items )
				)
			);
			WP_CLI\Utils\format_items( $format, $out_items, array( 'SKU', 'Name', 'Qty', 'Warehouse', 'Reorder Pt.' ) );
			WP_CLI::log( '' );
		}

		if ( ! empty( $low_items ) ) {
			WP_CLI::log(
				sprintf(
					/* translators: 1: threshold value, 2: number of low stock items */
					__( '=== LOW STOCK (below %1$d, %2$d items) ===', 'mcp-ai-wpoos-pro' ),
					$low_threshold,
					count( $low_items )
				)
			);
			WP_CLI\Utils\format_items( $format, $low_items, array( 'SKU', 'Name', 'Qty', 'Warehouse', 'Reorder Pt.' ) );
		}

		WP_CLI::log(
			sprintf(
				/* translators: 1: out of stock count, 2: low stock count, 3: total analyzed */
				__( 'Total: %1$d out of stock, %2$d low stock, %3$d total items analyzed.', 'mcp-ai-wpoos-pro' ),
				count( $out_items ),
				count( $low_items ),
				count( $items )
			)
		);
	}
}

// Register commands.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'ezuite status', array( 'WP_MCP_AI_EZuite_CLI', 'status' ) );
	WP_CLI::add_command( 'ezuite trigger', array( 'WP_MCP_AI_EZuite_CLI', 'trigger' ) );
	WP_CLI::add_command( 'ezuite clear-cache', array( 'WP_MCP_AI_EZuite_CLI', 'clear_cache' ) );
	WP_CLI::add_command( 'ezuite test-connection', array( 'WP_MCP_AI_EZuite_CLI', 'test_connection' ) );
	WP_CLI::add_command( 'ezuite low-stock-report', array( 'WP_MCP_AI_EZuite_CLI', 'low_stock_report' ) );
}
