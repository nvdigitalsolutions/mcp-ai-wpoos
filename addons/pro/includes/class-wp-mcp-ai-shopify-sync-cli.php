<?php
/**
 * Shopify Sync WP-CLI Commands.
 *
 * Registers WP-CLI commands for managing the Shopify Sync Pro Toolkit.
 * Provides status checks, manual sync triggers, cache management,
 * webhook registration, and cost reporting.
 *
 * Usage:
 *   wp shopify-sync status [--connection=<id>]
 *   wp shopify-sync trigger <connection_id>
 *   wp shopify-sync clear-cache <connection_id> [--force]
 *   wp shopify-sync register-webhooks <connection_id>
 *   wp shopify-sync unregister-webhooks <connection_id>
 *   wp shopify-sync cost-report [--connection=<id>] [--days=7]
 *   wp shopify-sync list-connections
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Only register when WP-CLI is available.
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Shopify Sync WP-CLI Commands.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Shopify_Sync_CLI {

	/**
	 * Show sync status for one or all connections.
	 *
	 * ## OPTIONS
	 *
	 * [--connection=<id>]
	 * : Specific connection ID to show. If omitted, shows all sync connections.
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
	 * ---
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp shopify-sync status
	 *     wp shopify-sync status --connection=conn_abc123
	 *     wp shopify-sync status --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( $args, $assoc_args ) {
		$settings         = get_option( 'wp_mcp_ai_shopify_sync_toolkit_settings', array() );
		$sync_connections = isset( $settings['sync_connections'] ) ? $settings['sync_connections'] : array();

		$specific_conn = isset( $assoc_args['connection'] ) ? sanitize_key( $assoc_args['connection'] ) : '';

		if ( ! empty( $specific_conn ) ) {
			if ( ! in_array( $specific_conn, $sync_connections, true ) ) {
				WP_CLI::error( sprintf( 'Connection "%s" is not configured for sync.', $specific_conn ) );
				return;
			}
			$sync_connections = array( $specific_conn );
		}

		if ( empty( $sync_connections ) ) {
			WP_CLI::log( 'No Shopify connections are configured for sync.' );
			return;
		}

		$rows = array();

		foreach ( $sync_connections as $conn_id ) {
			if ( ! class_exists( 'WP_MCP_AI_Shopify_Sync_CCT_Manager' ) ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-sync-cct-manager.php';
			}
			if ( ! class_exists( 'WP_MCP_AI_Shopify_Sync_Engine' ) ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-sync-engine.php';
			}

			$cct_manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $conn_id );
			$engine      = new WP_MCP_AI_Shopify_Sync_Engine( $conn_id );
			$last_sync   = $cct_manager->get_last_sync_time();
			$row_count   = $cct_manager->get_row_count();
			$is_fresh    = $cct_manager->is_fresh();
			$cost_report = $engine->get_cost_report();
			$last_error  = get_option( 'wp_mcp_ai_shopify_last_sync_error_' . $conn_id, '' );
			$webhook_ok  = get_option( 'wp_mcp_ai_shopify_webhook_registered_' . $conn_id, false );

			// Get connection name.
			$conn_name = $conn_id;
			if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				$conn_data = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $conn_id );
				if ( $conn_data && ! empty( $conn_data['name'] ) ) {
					$conn_name = $conn_data['name'];
				}
			}

			$rows[] = array(
				'Connection'    => $conn_name,
				'Connection ID' => $conn_id,
				'Last Sync'     => ! empty( $last_sync ) ? $last_sync : 'Never',
				'Fresh'         => $is_fresh ? 'Yes' : 'No',
				'CCT Rows'      => $row_count,
				'Cost Used'     => $cost_report['used'] . ' / ' . $cost_report['limit'],
				'Webhooks'      => $webhook_ok ? 'Registered' : 'Not Registered',
				'Last Error'    => ! empty( $last_error ) ? $last_error : 'None',
			);
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		WP_CLI\Utils\format_items(
			$format,
			$rows,
			array(
				'Connection',
				'Connection ID',
				'Last Sync',
				'Fresh',
				'CCT Rows',
				'Cost Used',
				'Webhooks',
				'Last Error',
			)
		);
	}

	/**
	 * Trigger a full sync for a specific connection.
	 *
	 * ## OPTIONS
	 *
	 * <connection_id>
	 * : The Remote Sites connection ID to sync.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shopify-sync trigger conn_abc123
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function trigger( $args, $assoc_args ) {
		unset( $assoc_args ); // Reserved for future options.
		$connection_id = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';

		if ( empty( $connection_id ) ) {
			WP_CLI::error( 'connection_id is required.' );
			return;
		}

		$settings         = get_option( 'wp_mcp_ai_shopify_sync_toolkit_settings', array() );
		$sync_connections = isset( $settings['sync_connections'] ) ? $settings['sync_connections'] : array();

		if ( ! in_array( $connection_id, $sync_connections, true ) ) {
			WP_CLI::error( sprintf( 'Connection "%s" is not configured for sync.', $connection_id ) );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Shopify_Sync_CCT_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-sync-cct-manager.php';
		}
		if ( ! class_exists( 'WP_MCP_AI_Shopify_Sync_Engine' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-sync-engine.php';
		}

		WP_CLI::log( sprintf( 'Starting full sync for connection %s...', $connection_id ) );

		$cct_manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $connection_id );
		$result      = $cct_manager->sync_from_bulk_operation(
			function ( $current, $total, $inserted, $updated, $skipped ) {
				$pct = round( ( $current / $total ) * 100, 1 );
				WP_CLI::log( sprintf( '  Progress: %d/%d (%s%%) — %d inserted, %d updated, %d skipped', $current, $total, $pct, $inserted, $updated, $skipped ) );
			}
		);

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		// Track cost.
		$engine = new WP_MCP_AI_Shopify_Sync_Engine( $connection_id );
		$engine->track_sync_cost( 10 );

		WP_CLI::success(
			sprintf(
				'Sync completed: %d inserted, %d updated, %d skipped, %d errors, %d total (took %ss).',
				isset( $result['inserted'] ) ? $result['inserted'] : 0,
				isset( $result['updated'] ) ? $result['updated'] : 0,
				isset( $result['skipped'] ) ? $result['skipped'] : 0,
				isset( $result['errors'] ) ? $result['errors'] : 0,
				isset( $result['total'] ) ? $result['total'] : 0,
				isset( $result['duration'] ) ? $result['duration'] : 0
			)
		);
	}

	/**
	 * Clear the CCT cache for a specific connection.
	 *
	 * ## OPTIONS
	 *
	 * <connection_id>
	 * : The Remote Sites connection ID.
	 *
	 * [--force]
	 * : Required to confirm cache clearing.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shopify-sync clear-cache conn_abc123 --force
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function clear_cache( $args, $assoc_args ) {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Reserved for future options.
		$connection_id = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';

		if ( empty( $connection_id ) ) {
			WP_CLI::error( 'connection_id is required.' );
			return;
		}

		if ( ! isset( $assoc_args['force'] ) ) {
			WP_CLI::confirm( sprintf( 'Are you sure you want to clear the CCT cache for connection %s? This will delete all cached inventory data.', $connection_id ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Shopify_Sync_CCT_Manager' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-sync-cct-manager.php';
		}

		$cct_manager = new WP_MCP_AI_Shopify_Sync_CCT_Manager( $connection_id );
		$result      = $cct_manager->truncate();

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		delete_option( 'wp_mcp_ai_shopify_last_sync_' . $connection_id );

		WP_CLI::success( sprintf( 'CCT cache cleared for connection %s. Run `wp shopify-sync trigger %s` to repopulate.', $connection_id, $connection_id ) );
	}

	/**
	 * Register Shopify webhooks for a connection.
	 *
	 * ## OPTIONS
	 *
	 * <connection_id>
	 * : The Remote Sites connection ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shopify-sync register-webhooks conn_abc123
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function register_webhooks( $args, $assoc_args ) {
		unset( $assoc_args ); // Reserved for future options.
		$connection_id = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';

		if ( empty( $connection_id ) ) {
			WP_CLI::error( 'connection_id is required.' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Shopify_Sync_Webhook_Handler' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-sync-webhook-handler.php';
		}

		WP_CLI::log( sprintf( 'Registering webhooks for connection %s...', $connection_id ) );

		$result = WP_MCP_AI_Shopify_Sync_Webhook_Handler::register_webhooks( $connection_id );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		WP_CLI::log( sprintf( 'Webhook URL: %s', $result['webhook_url'] ) );
		WP_CLI::log( '' );

		foreach ( $result['results'] as $topic => $r ) {
			if ( 'registered' === $r['status'] ) {
				WP_CLI::log( sprintf( '  ✓ %s (%s)', $topic, $r['subscription_id'] ) );
			} else {
				WP_CLI::log( sprintf( '  ✗ %s: %s', $topic, $r['error'] ) );
			}
		}

		if ( $result['all_success'] ) {
			WP_CLI::success( 'All webhooks registered successfully.' );
		} else {
			WP_CLI::warning( 'Some webhooks failed to register. Check the errors above.' );
		}
	}

	/**
	 * Unregister Shopify webhooks for a connection.
	 *
	 * ## OPTIONS
	 *
	 * <connection_id>
	 * : The Remote Sites connection ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shopify-sync unregister-webhooks conn_abc123
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function unregister_webhooks( $args, $assoc_args ) {
		unset( $assoc_args ); // Reserved for future options.
		$connection_id = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';

		if ( empty( $connection_id ) ) {
			WP_CLI::error( 'connection_id is required.' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Shopify_Sync_Webhook_Handler' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-sync-webhook-handler.php';
		}

		WP_CLI::log( sprintf( 'Unregistering webhooks for connection %s...', $connection_id ) );

		$result = WP_MCP_AI_Shopify_Sync_Webhook_Handler::unregister_webhooks( $connection_id );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
			return;
		}

		WP_CLI::success( sprintf( 'Deleted %d webhook subscriptions (found %d total).', $result['deleted'], $result['total_found'] ) );
	}

	/**
	 * Show GraphQL cost report for one or all connections.
	 *
	 * ## OPTIONS
	 *
	 * [--connection=<id>]
	 * : Specific connection ID. If omitted, shows all sync connections.
	 *
	 * [--days=7]
	 * : Number of days of history to show. Default: 7.
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shopify-sync cost-report
	 *     wp shopify-sync cost-report --connection=conn_abc123
	 *     wp shopify-sync cost-report --days=30 --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cost_report( $args, $assoc_args ) {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- Reserved for future options.
		$settings         = get_option( 'wp_mcp_ai_shopify_sync_toolkit_settings', array() );
		$sync_connections = isset( $settings['sync_connections'] ) ? $settings['sync_connections'] : array();

		$specific_conn = isset( $assoc_args['connection'] ) ? sanitize_key( $assoc_args['connection'] ) : '';

		if ( ! empty( $specific_conn ) ) {
			if ( ! in_array( $specific_conn, $sync_connections, true ) ) {
				WP_CLI::error( sprintf( 'Connection "%s" is not configured for sync.', $specific_conn ) );
				return;
			}
			$sync_connections = array( $specific_conn );
		}

		if ( empty( $sync_connections ) ) {
			WP_CLI::log( 'No Shopify connections are configured for sync.' );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Shopify_Sync_Engine' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-shopify-sync-engine.php';
		}

		$rows = array();

		foreach ( $sync_connections as $conn_id ) {
			$engine      = new WP_MCP_AI_Shopify_Sync_Engine( $conn_id );
			$cost_report = $engine->get_cost_report();

			$conn_name = $conn_id;
			if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				$conn_data = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $conn_id );
				if ( $conn_data && ! empty( $conn_data['name'] ) ) {
					$conn_name = $conn_data['name'];
				}
			}

			$status = $cost_report['is_low']
				? sprintf( '⚠ LOW (%s%%)', $cost_report['pct_remaining'] )
				: sprintf( '✓ OK (%s%%)', $cost_report['pct_remaining'] );

			$rows[] = array(
				'Connection'  => $conn_name,
				'Used'        => $cost_report['used'],
				'Remaining'   => $cost_report['remaining'],
				'Budget'      => $cost_report['limit'],
				'Status'      => $status,
				'Refill Est.' => $cost_report['refill_at'],
			);
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		WP_CLI\Utils\format_items(
			$format,
			$rows,
			array(
				'Connection',
				'Used',
				'Remaining',
				'Budget',
				'Status',
				'Refill Est.',
			)
		);

		// Show summary.
		$total_used = array_sum( wp_list_pluck( $rows, 'Used' ) );
		WP_CLI::log( '' );
		WP_CLI::log( sprintf( 'Total points used today across all connections: %d', $total_used ) );
	}

	/**
	 * List all sync-enabled Shopify connections.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp shopify-sync list-connections
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list_connections( $args, $assoc_args ) {
		unset( $assoc_args ); // Reserved for future options.
		$settings         = get_option( 'wp_mcp_ai_shopify_sync_toolkit_settings', array() );
		$sync_connections = isset( $settings['sync_connections'] ) ? $settings['sync_connections'] : array();

		if ( empty( $sync_connections ) ) {
			WP_CLI::log( 'No Shopify connections are configured for sync.' );
			return;
		}

		$rows = array();

		foreach ( $sync_connections as $conn_id ) {
			$conn_name = $conn_id;
			$conn_url  = '';

			if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
				$conn_data = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $conn_id );
				if ( $conn_data ) {
					$conn_name = isset( $conn_data['name'] ) ? $conn_data['name'] : $conn_id;
					$conn_url  = isset( $conn_data['url'] ) ? $conn_data['url'] : '';
				}
			}

			$rows[] = array(
				'Connection ID' => $conn_id,
				'Name'          => $conn_name,
				'Store URL'     => $conn_url,
			);
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		WP_CLI\Utils\format_items( $format, $rows, array( 'Connection ID', 'Name', 'Store URL' ) );
	}
}

// Register commands with WP-CLI.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'shopify-sync status', array( 'WP_MCP_AI_Shopify_Sync_CLI', 'status' ) );
	WP_CLI::add_command( 'shopify-sync trigger', array( 'WP_MCP_AI_Shopify_Sync_CLI', 'trigger' ) );
	WP_CLI::add_command( 'shopify-sync clear-cache', array( 'WP_MCP_AI_Shopify_Sync_CLI', 'clear_cache' ) );
	WP_CLI::add_command( 'shopify-sync register-webhooks', array( 'WP_MCP_AI_Shopify_Sync_CLI', 'register_webhooks' ) );
	WP_CLI::add_command( 'shopify-sync unregister-webhooks', array( 'WP_MCP_AI_Shopify_Sync_CLI', 'unregister_webhooks' ) );
	WP_CLI::add_command( 'shopify-sync cost-report', array( 'WP_MCP_AI_Shopify_Sync_CLI', 'cost_report' ) );
	WP_CLI::add_command( 'shopify-sync list-connections', array( 'WP_MCP_AI_Shopify_Sync_CLI', 'list_connections' ) );
}
