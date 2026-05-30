<?php
/**
 * WP-CLI log management commands for NV oOS.
 *
 * @package WP_MCP_AI
 * @subpackage CLI
 * @since 1.3.0
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
 * Inspect and manage NV oOS plugin logs from the command line.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_CLI_Log_Command extends WP_MCP_AI_CLI_Base_Command {

	/**
	 * Show recent error and warning log entries.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : Number of entries to display.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--format=<format>]
	 * : Render output in the given format.
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
	 *     # Show the 10 most recent errors.
	 *     $ wp mcp-ai log errors --limit=10
	 *
	 *     # Export errors as JSON.
	 *     $ wp mcp-ai log errors --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function errors( $args, $assoc_args ) {
		$limit  = absint( \WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 20 ) );
		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_CLI::error( __( 'Logger class is not available.', 'mcp-ai-wpoos' ) );
		}

		$entries = WP_MCP_AI_Logger::get_recent_error_messages( $limit );

		if ( empty( $entries ) ) {
			WP_CLI::log( __( 'No recent error entries found.', 'mcp-ai-wpoos' ) );
			return;
		}

		$items = $this->normalise_log_entries( $entries );

		$this->output_log_items( $items, $format, array( 'time', 'level', 'message' ) );
	}

	/**
	 * Show recent activity log entries (tool executions, chat interactions, etc.).
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : Number of entries to display.
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--type=<type>]
	 * : Filter by activity type (e.g. tool_execution, chat_interaction).
	 *
	 * [--format=<format>]
	 * : Render output in the given format.
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
	 *     # Show the 20 most recent activity entries.
	 *     $ wp mcp-ai log activity
	 *
	 *     # Show only tool executions.
	 *     $ wp mcp-ai log activity --type=tool_execution
	 *
	 *     # Export as JSON.
	 *     $ wp mcp-ai log activity --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function activity( $args, $assoc_args ) {
		$limit  = absint( \WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 20 ) );
		$type   = sanitize_key( \WP_CLI\Utils\get_flag_value( $assoc_args, 'type', '' ) );
		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_CLI::error( __( 'Logger class is not available.', 'mcp-ai-wpoos' ) );
		}

		$types   = $type ? array( $type ) : array();
		$entries = WP_MCP_AI_Logger::get_recent_activity_entries( $limit, $types );

		if ( empty( $entries ) ) {
			WP_CLI::log( __( 'No recent activity entries found.', 'mcp-ai-wpoos' ) );
			return;
		}

		$items = $this->normalise_log_entries( $entries );

		$this->output_log_items( $items, $format, array( 'time', 'type', 'message' ) );
	}

	/**
	 * Prune (truncate) the PHP error log file for this site.
	 *
	 * Only supported when NV oOS can locate a writable PHP error log path.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Prune the error log with confirmation.
	 *     $ wp mcp-ai log prune
	 *
	 *     # Prune without prompting.
	 *     $ wp mcp-ai log prune --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function prune( $args, $assoc_args ) {
		$yes = \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false );

		if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_CLI::error( __( 'Logger class is not available.', 'mcp-ai-wpoos' ) );
		}

		if ( ! WP_MCP_AI_Logger::can_prune_error_log() ) {
			WP_CLI::error( __( 'Cannot prune the error log. The log file may not be writable or its path cannot be determined.', 'mcp-ai-wpoos' ) );
		}

		$log_path = WP_MCP_AI_Logger::get_log_file_path();
		$size     = WP_MCP_AI_Logger::get_log_file_size();

		WP_CLI::log(
			sprintf(
				/* translators: 1: file path, 2: human-readable file size */
				__( 'Log file: %1$s (%2$s)', 'mcp-ai-wpoos' ),
				$log_path,
				size_format( (int) $size )
			)
		);

		if ( ! $yes ) {
			WP_CLI::confirm( __( 'Are you sure you want to prune (empty) the error log?', 'mcp-ai-wpoos' ) );
		}

		$result = WP_MCP_AI_Logger::prune_error_log();

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		WP_CLI::success( __( 'Error log pruned.', 'mcp-ai-wpoos' ) );
	}

	/**
	 * Clear the ring-buffer logs (recent errors and/or activity entries).
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : Which log buffer to clear.
	 * ---
	 * default: all
	 * options:
	 *   - errors
	 *   - activity
	 *   - all
	 * ---
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Clear all log buffers.
	 *     $ wp mcp-ai log clear
	 *
	 *     # Clear only error entries without prompting.
	 *     $ wp mcp-ai log clear --type=errors --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function clear( $args, $assoc_args ) {
		$type = sanitize_key( \WP_CLI\Utils\get_flag_value( $assoc_args, 'type', 'all' ) );
		$yes  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false );

		if ( ! in_array( $type, array( 'errors', 'activity', 'all' ), true ) ) {
			WP_CLI::error( __( 'Invalid --type. Accepted values: errors, activity, all.', 'mcp-ai-wpoos' ) );
		}

		if ( ! $yes ) {
			$type_label = 'all' === $type ? __( 'all log buffers', 'mcp-ai-wpoos' ) : $type;
			WP_CLI::confirm(
				sprintf(
					/* translators: %s: log type label */
					__( 'Are you sure you want to clear %s?', 'mcp-ai-wpoos' ),
					$type_label
				)
			);
		}

		if ( class_exists( 'WP_MCP_AI_Logger' ) && method_exists( 'WP_MCP_AI_Logger', 'clear_log_entries' ) ) {
			WP_MCP_AI_Logger::clear_log_entries( $type );
		} else {
			// Fallback: delete the options directly.
			if ( in_array( $type, array( 'errors', 'all' ), true ) ) {
				delete_option( 'wp_mcp_ai_recent_errors' );
			}
			if ( in_array( $type, array( 'activity', 'all' ), true ) ) {
				delete_option( 'wp_mcp_ai_recent_activity' );
			}
		}

		WP_CLI::success(
			sprintf(
				/* translators: %s: type of log cleared */
				__( 'Log buffer cleared: %s.', 'mcp-ai-wpoos' ),
				$type
			)
		);
	}

	/**
	 * Normalise an array of raw log entries into a consistent display format.
	 *
	 * @param array $entries Raw log entries from the logger.
	 * @return array Normalized entries.
	 */
	protected function normalise_log_entries( $entries ) {
		$items = array();

		foreach ( $entries as $entry ) {
			if ( is_string( $entry ) ) {
				$items[] = array(
					'time'    => '',
					'level'   => '',
					'type'    => '',
					'message' => $entry,
				);
				continue;
			}

			if ( is_array( $entry ) ) {
				$items[] = array(
					'time'    => $entry['time'] ?? ( $entry['timestamp'] ?? '' ),
					'level'   => $entry['level'] ?? '',
					'type'    => $entry['type'] ?? '',
					'message' => $entry['message'] ?? wp_json_encode( $entry ),
				);
				continue;
			}
		}

		return $items;
	}

	/**
	 * Output normalized log items in the requested format.
	 *
	 * @param array  $items   Normalized log items.
	 * @param string $format  Output format (table, json, yaml).
	 * @param array  $fields  Fields to include in table/csv output.
	 * @return void
	 */
	protected function output_log_items( $items, $format, $fields ) {
		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $items, JSON_PRETTY_PRINT ) );
			return;
		}

		if ( 'yaml' === $format ) {
			foreach ( $items as $item ) {
				foreach ( $fields as $field ) {
					if ( isset( $item[ $field ] ) && '' !== $item[ $field ] ) {
						WP_CLI::line( "{$field}: {$item[ $field ]}" );
					}
				}
				WP_CLI::line( '---' );
			}
			return;
		}

		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai log', 'WP_MCP_AI_CLI_Log_Command' );
}
