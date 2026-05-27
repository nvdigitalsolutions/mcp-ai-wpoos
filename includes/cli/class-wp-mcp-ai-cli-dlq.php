<?php
/**
 * WP-CLI Commands for Dead Letter Queue management.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/class-wp-mcp-ai-cli-base-command.php';

/**
 * Manage the Dead Letter Queue for failed jobs and webhooks.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_CLI_DLQ extends WP_MCP_AI_CLI_Base_Command {
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
	 * @when after_wp_load
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list_items( $args, $assoc_args ) {
		if ( ! class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
			$this->error( __( 'Dead Letter Queue class not found.', 'mcp-ai-wpoos' ) );
		}

		$filters = array();

		if ( isset( $assoc_args['type'] ) ) {
			$filters['type'] = sanitize_key( $assoc_args['type'] );
		}

		if ( isset( $assoc_args['dismissed'] ) ) {
			$filters['dismissed'] = ( 'yes' === $assoc_args['dismissed'] );
		}

		$items = WP_MCP_AI_Dead_Letter_Queue::get_all( $filters );

		if ( empty( $items ) ) {
			$this->info( __( 'No items in dead letter queue.', 'mcp-ai-wpoos' ) );
			return;
		}

		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

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
	 * @when after_wp_load
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
	 * @when after_wp_load
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function retry( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for CLI flags.
		if ( ! class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
			$this->error( __( 'Dead Letter Queue class not found.', 'mcp-ai-wpoos' ) );
		}

		if ( empty( $args[0] ) ) {
			$this->error( __( 'Item ID is required.', 'mcp-ai-wpoos' ) );
		}

		$item_id = sanitize_key( $args[0] );
		$item    = WP_MCP_AI_Dead_Letter_Queue::get( $item_id );

		if ( ! $item ) {
			/* translators: %s: item ID */
			$this->error( sprintf( __( "Item '%s' not found in dead letter queue.", 'mcp-ai-wpoos' ), $item_id ) );
		}

		/* translators: 1: item type, 2: item identifier */
		$this->info( sprintf( __( 'Retrying item: %1$s - %2$s', 'mcp-ai-wpoos' ), $item['type'], $item['identifier'] ) );

		$result = WP_MCP_AI_Dead_Letter_Queue::retry( $item_id );

		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		}

		$this->success( __( 'Item successfully retried and removed from queue.', 'mcp-ai-wpoos' ) );
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
	 * @when after_wp_load
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function dismiss( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for CLI flags.
		if ( ! class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
			$this->error( __( 'Dead Letter Queue class not found.', 'mcp-ai-wpoos' ) );
		}

		if ( empty( $args[0] ) ) {
			$this->error( __( 'Item ID is required.', 'mcp-ai-wpoos' ) );
		}

		$item_id = sanitize_key( $args[0] );
		$item    = WP_MCP_AI_Dead_Letter_Queue::get( $item_id );

		if ( ! $item ) {
			/* translators: %s: item ID */
			$this->error( sprintf( __( "Item '%s' not found in dead letter queue.", 'mcp-ai-wpoos' ), $item_id ) );
		}

		$result = WP_MCP_AI_Dead_Letter_Queue::dismiss( $item_id );

		if ( ! $result ) {
			$this->error( __( 'Failed to dismiss item.', 'mcp-ai-wpoos' ) );
		}

		$this->success( __( 'Item dismissed.', 'mcp-ai-wpoos' ) );
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
	 * @when after_wp_load
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function delete( $args, $assoc_args ) {
		if ( ! class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
			$this->error( __( 'Dead Letter Queue class not found.', 'mcp-ai-wpoos' ) );
		}

		if ( empty( $args[0] ) ) {
			$this->error( __( 'Item ID is required.', 'mcp-ai-wpoos' ) );
		}

		$item_id = sanitize_key( $args[0] );
		$item    = WP_MCP_AI_Dead_Letter_Queue::get( $item_id );

		if ( ! $item ) {
			/* translators: %s: item ID */
			$this->error( sprintf( __( "Item '%s' not found in dead letter queue.", 'mcp-ai-wpoos' ), $item_id ) );
		}

		// Confirm deletion unless --yes flag is provided.
		$yes = \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false );
		if ( ! $yes ) {
			/* translators: %s: item ID */
			WP_CLI::confirm( sprintf( __( "Are you sure you want to delete item '%s'?", 'mcp-ai-wpoos' ), $item_id ), $assoc_args );
		}

		$result = WP_MCP_AI_Dead_Letter_Queue::remove( $item_id );

		if ( ! $result ) {
			$this->error( __( 'Failed to remove item.', 'mcp-ai-wpoos' ) );
		}

		$this->success( __( 'Item removed from dead letter queue.', 'mcp-ai-wpoos' ) );
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
	 * [--dry-run]
	 * : Preview items that would be purged without removing them.
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
	 *     # Preview what would be purged
	 *     $ wp mcp-ai dlq purge --dry-run
	 *
	 *     # Purge without confirmation
	 *     $ wp mcp-ai dlq purge --yes
	 *
	 * @when after_wp_load
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function purge( $args, $assoc_args ) {
		if ( ! class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
			$this->error( __( 'Dead Letter Queue class not found.', 'mcp-ai-wpoos' ) );
		}

		$days    = absint( \WP_CLI\Utils\get_flag_value( $assoc_args, 'days', 30 ) );
		$yes     = \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false );
		$dry_run = $this->is_dry_run( $assoc_args );

		if ( $dry_run ) {
			$this->dry_run_notice();
			/* translators: %d: number of days */
			$this->info( sprintf( __( 'Would purge items older than %d days.', 'mcp-ai-wpoos' ), $days ) );
			return;
		}

		// Confirm purge unless --yes flag is provided.
		if ( ! $yes ) {
			/* translators: %d: number of days */
			WP_CLI::confirm( sprintf( __( 'Are you sure you want to purge items older than %d days?', 'mcp-ai-wpoos' ), $days ), $assoc_args );
		}

		/* translators: %d: number of days */
		$this->info( sprintf( __( 'Purging items older than %d days...', 'mcp-ai-wpoos' ), $days ) );

		$purged = WP_MCP_AI_Dead_Letter_Queue::purge_old( $days );

		/* translators: %d: number of purged items */
		$this->success( sprintf( __( 'Purged %d items from dead letter queue.', 'mcp-ai-wpoos' ), $purged ) );
	}

	/**
	 * Clear all items from the dead letter queue.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Preview how many items would be cleared without removing them.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Clear all items
	 *     $ wp mcp-ai dlq clear --yes
	 *
	 *     # Preview what would be cleared
	 *     $ wp mcp-ai dlq clear --dry-run
	 *
	 * @when after_wp_load
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function clear( $args, $assoc_args ) {
		if ( ! class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
			$this->error( __( 'Dead Letter Queue class not found.', 'mcp-ai-wpoos' ) );
		}

		$yes     = \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false );
		$dry_run = $this->is_dry_run( $assoc_args );

		$items = WP_MCP_AI_Dead_Letter_Queue::get_all();
		$count = count( $items );

		if ( $dry_run ) {
			$this->dry_run_notice();
			/* translators: %d: number of items */
			$this->info( sprintf( __( 'Would clear %d items from dead letter queue.', 'mcp-ai-wpoos' ), $count ) );
			return;
		}

		// Confirm clear unless --yes flag is provided.
		if ( ! $yes ) {
			WP_CLI::confirm( __( 'Are you sure you want to clear ALL items from the dead letter queue?', 'mcp-ai-wpoos' ), $assoc_args );
		}

		foreach ( $items as $item ) {
			WP_MCP_AI_Dead_Letter_Queue::remove( $item['id'] );
		}

		/* translators: %d: number of cleared items */
		$this->success( sprintf( __( 'Cleared %d items from dead letter queue.', 'mcp-ai-wpoos' ), $count ) );
	}
}
