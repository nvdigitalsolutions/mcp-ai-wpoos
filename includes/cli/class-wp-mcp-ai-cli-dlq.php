<?php
/**
 * WP-CLI Commands for Dead Letter Queue management.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manage the Dead Letter Queue for failed jobs and webhooks.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_CLI_DLQ {
	/**
	 * List items in the dead letter queue.
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : Filter by item type (webhook, cron_job, async_tool, job_queue).
	 *
	 * [--dismissed=<dismissed>]
	 * : Filter by dismissed status (yes, no).
	 *
	 * [--format=<format>]
	 * : Output format (table, json, csv, yaml).
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List all DLQ items
	 *     $ wp mcp-ai dlq list
	 *
	 *     # List only webhooks
	 *     $ wp mcp-ai dlq list --type=webhook
	 *
	 *     # List active items (not dismissed)
	 *     $ wp mcp-ai dlq list --dismissed=no
	 *
	 *     # Export to JSON
	 *     $ wp mcp-ai dlq list --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list_items( $args, $assoc_args ) {
		if ( ! class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
			WP_CLI::error( 'Dead Letter Queue class not found.' );
		}

		$filters = array();

		if ( isset( $assoc_args['type'] ) ) {
			$filters['type'] = $assoc_args['type'];
		}

		if ( isset( $assoc_args['dismissed'] ) ) {
			$filters['dismissed'] = ( 'yes' === $assoc_args['dismissed'] );
		}

		$items = WP_MCP_AI_Dead_Letter_Queue::get_all( $filters );

		if ( empty( $items ) ) {
			WP_CLI::success( 'No items in dead letter queue.' );
			return;
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		// Prepare items for display.
		$display_items = array();
		foreach ( $items as $item ) {
			$display_items[] = array(
				'id'             => $item['id'],
				'type'           => $item['type'],
				'identifier'     => $item['identifier'],
				'failure_reason' => substr( $item['failure_reason'], 0, 60 ) . ( strlen( $item['failure_reason'] ) > 60 ? '...' : '' ),
				'retry_count'    => $item['retry_count'],
				'added_at'       => $item['added_at'],
				'dismissed'      => $item['dismissed'] ? 'yes' : 'no',
			);
		}

		WP_CLI\Utils\format_items( $format, $display_items, array( 'id', 'type', 'identifier', 'failure_reason', 'retry_count', 'added_at', 'dismissed' ) );
	}

	/**
	 * Show statistics about the dead letter queue.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format (table, json, yaml).
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Show DLQ statistics
	 *     $ wp mcp-ai dlq stats
	 *
	 *     # Export stats as JSON
	 *     $ wp mcp-ai dlq stats --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function stats( $args, $assoc_args ) {
		if ( ! class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
			WP_CLI::error( 'Dead Letter Queue class not found.' );
		}

		$stats  = WP_MCP_AI_Dead_Letter_Queue::get_stats();
		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

		// Prepare stats for display.
		$display_stats = array(
			array(
				'metric' => 'Total Items',
				'value'  => $stats['total'],
			),
			array(
				'metric' => 'Active Items',
				'value'  => $stats['active'],
			),
			array(
				'metric' => 'Dismissed Items',
				'value'  => $stats['dismissed'],
			),
		);

		if ( ! empty( $stats['by_type'] ) ) {
			foreach ( $stats['by_type'] as $type => $count ) {
				$display_stats[] = array(
					'metric' => ucfirst( str_replace( '_', ' ', $type ) ),
					'value'  => $count,
				);
			}
		}

		if ( $stats['oldest_date'] ) {
			$display_stats[] = array(
				'metric' => 'Oldest Item',
				'value'  => $stats['oldest_date'],
			);
		}

		if ( $stats['newest_date'] ) {
			$display_stats[] = array(
				'metric' => 'Newest Item',
				'value'  => $stats['newest_date'],
			);
		}

		WP_CLI\Utils\format_items( $format, $display_stats, array( 'metric', 'value' ) );
	}

	/**
	 * Retry a failed item from the dead letter queue.
	 *
	 * ## OPTIONS
	 *
	 * <item-id>
	 * : The ID of the item to retry.
	 *
	 * ## EXAMPLES
	 *
	 *     # Retry a specific item
	 *     $ wp mcp-ai dlq retry abc123def456
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function retry( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for CLI flags.
		if ( ! class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
			WP_CLI::error( 'Dead Letter Queue class not found.' );
		}

		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Item ID is required.' );
		}

		$item_id = $args[0];
		$item    = WP_MCP_AI_Dead_Letter_Queue::get( $item_id );

		if ( ! $item ) {
			WP_CLI::error( "Item '{$item_id}' not found in dead letter queue." );
		}

		WP_CLI::log( "Retrying item: {$item['type']} - {$item['identifier']}" );

		$result = WP_MCP_AI_Dead_Letter_Queue::retry( $item_id );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( 'Item successfully retried and removed from queue.' );
	}

	/**
	 * Dismiss an item in the dead letter queue.
	 *
	 * ## OPTIONS
	 *
	 * <item-id>
	 * : The ID of the item to dismiss.
	 *
	 * ## EXAMPLES
	 *
	 *     # Dismiss a specific item
	 *     $ wp mcp-ai dlq dismiss abc123def456
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function dismiss( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for CLI flags.
		if ( ! class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
			WP_CLI::error( 'Dead Letter Queue class not found.' );
		}

		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Item ID is required.' );
		}

		$item_id = $args[0];
		$item    = WP_MCP_AI_Dead_Letter_Queue::get( $item_id );

		if ( ! $item ) {
			WP_CLI::error( "Item '{$item_id}' not found in dead letter queue." );
		}

		$result = WP_MCP_AI_Dead_Letter_Queue::dismiss( $item_id );

		if ( ! $result ) {
			WP_CLI::error( 'Failed to dismiss item.' );
		}

		WP_CLI::success( 'Item dismissed.' );
	}

	/**
	 * Remove an item from the dead letter queue.
	 *
	 * ## OPTIONS
	 *
	 * <item-id>
	 * : The ID of the item to remove.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Remove a specific item
	 *     $ wp mcp-ai dlq delete abc123def456
	 *
	 *     # Remove without confirmation
	 *     $ wp mcp-ai dlq delete abc123def456 --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function delete( $args, $assoc_args ) {
		if ( ! class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
			WP_CLI::error( 'Dead Letter Queue class not found.' );
		}

		if ( empty( $args[0] ) ) {
			WP_CLI::error( 'Item ID is required.' );
		}

		$item_id = $args[0];
		$item    = WP_MCP_AI_Dead_Letter_Queue::get( $item_id );

		if ( ! $item ) {
			WP_CLI::error( "Item '{$item_id}' not found in dead letter queue." );
		}

		// Confirm deletion unless --yes flag is provided.
		if ( ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::confirm( "Are you sure you want to delete item '{$item_id}'?", $assoc_args );
		}

		$result = WP_MCP_AI_Dead_Letter_Queue::remove( $item_id );

		if ( ! $result ) {
			WP_CLI::error( 'Failed to remove item.' );
		}

		WP_CLI::success( 'Item removed from dead letter queue.' );
	}

	/**
	 * Purge old items from the dead letter queue.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Remove items older than this many days (default: 30).
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Purge items older than 30 days
	 *     $ wp mcp-ai dlq purge
	 *
	 *     # Purge items older than 7 days
	 *     $ wp mcp-ai dlq purge --days=7
	 *
	 *     # Purge without confirmation
	 *     $ wp mcp-ai dlq purge --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function purge( $args, $assoc_args ) {
		if ( ! class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
			WP_CLI::error( 'Dead Letter Queue class not found.' );
		}

		$days = isset( $assoc_args['days'] ) ? absint( $assoc_args['days'] ) : 30;

		// Confirm purge unless --yes flag is provided.
		if ( ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::confirm( "Are you sure you want to purge items older than {$days} days?", $assoc_args );
		}

		WP_CLI::log( "Purging items older than {$days} days..." );

		$purged = WP_MCP_AI_Dead_Letter_Queue::purge_old( $days );

		WP_CLI::success( "Purged {$purged} items from dead letter queue." );
	}

	/**
	 * Clear all items from the dead letter queue.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Clear all items
	 *     $ wp mcp-ai dlq clear --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function clear( $args, $assoc_args ) {
		if ( ! class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
			WP_CLI::error( 'Dead Letter Queue class not found.' );
		}

		// Confirm clear unless --yes flag is provided.
		if ( ! isset( $assoc_args['yes'] ) ) {
			WP_CLI::confirm( 'Are you sure you want to clear ALL items from the dead letter queue?', $assoc_args );
		}

		$items = WP_MCP_AI_Dead_Letter_Queue::get_all();
		$count = count( $items );

		foreach ( $items as $item ) {
			WP_MCP_AI_Dead_Letter_Queue::remove( $item['id'] );
		}

		WP_CLI::success( "Cleared {$count} items from dead letter queue." );
	}
}
