<?php
/**
 * WP-CLI bulk-tool / massive-data operations command.
 *
 * Phase 5 of the massive-data hardening plan. Provides operational tooling
 * for site owners to introspect and exercise the Phase 1–4 infrastructure:
 *
 *   - `wp mcp-ai bulk audit` — list registered bulk-interface tools, their
 *     auto-async dispatch eligibility, and the resolved threshold filter.
 *   - `wp mcp-ai bulk status` — summarise the async job queue (counts by
 *     status, oldest queued row, AS bridge availability).
 *   - `wp mcp-ai bulk dispatch <slug>` — explicitly enqueue a bulk tool with
 *     a JSON payload (useful for retro-running migrations and load tests).
 *   - `wp mcp-ai bulk cleanup-artifacts` — purge expired artifact
 *     transients written by `WP_MCP_AI_Tool_Artifact_Helper`.
 *
 * @package WP_MCP_AI
 * @subpackage CLI
 * @since 1.2.0
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
 * Inspect and operate on the massive-data hardening pipeline.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_CLI_Bulk_Command extends WP_MCP_AI_CLI_Base_Command {

	/**
	 * List bulk-interface tools and their auto-async dispatch eligibility.
	 *
	 * Walks the tool registry, picks out every tool implementing
	 * `WP_MCP_AI_Tool_Bulk_Operation_Interface`, and reports the threshold
	 * resolved by the `wp_mcp_ai_bulk_async_threshold` filter.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in the given format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List every bulk-interface tool.
	 *     $ wp mcp-ai bulk audit
	 *
	 *     # Pipe to jq for filtering.
	 *     $ wp mcp-ai bulk audit --format=json | jq '.[] | select(.threshold>500)'
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function audit( $args, $assoc_args ) {
		unset( $args );

		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$this->error( __( 'Tool registry unavailable.', 'mcp-ai-wpoos' ) );
			return;
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tools    = $registry->get_tools();
		$rows     = array();

		foreach ( $tools as $slug => $tool ) {
			if ( ! ( $tool instanceof WP_MCP_AI_Tool_Bulk_Operation_Interface ) ) {
				continue;
			}

			$threshold = (int) apply_filters( 'wp_mcp_ai_bulk_async_threshold', 1000, $slug );
			$rows[]    = array(
				'slug'      => $slug,
				'class'     => get_class( $tool ),
				'threshold' => $threshold,
			);
		}

		if ( empty( $rows ) ) {
			$this->info( __( 'No bulk-interface tools registered.', 'mcp-ai-wpoos' ) );
			return;
		}

		$format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$this->format_output( $rows, $format, array( 'slug', 'class', 'threshold' ) );
	}

	/**
	 * Show async job queue status and Action Scheduler bridge availability.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Render output in the given format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Summarise queue state.
	 *     $ wp mcp-ai bulk status
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		unset( $args );
		global $wpdb;

		if ( ! class_exists( 'WP_MCP_AI_Async_Job_Queue' ) ) {
			$this->error( __( 'Async job queue unavailable.', 'mcp-ai-wpoos' ) );
			return;
		}

		$table = $wpdb->prefix . WP_MCP_AI_Async_Job_Queue::TABLE_NAME;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$counts = $wpdb->get_results(
			"SELECT status, COUNT(*) AS total FROM $table GROUP BY status",
			ARRAY_A
		);
		$oldest = $wpdb->get_var(
			"SELECT created_at FROM $table WHERE status = 'queued' ORDER BY created_at ASC LIMIT 1"
		);
		// phpcs:enable

		$summary = array(
			'queued'    => 0,
			'running'   => 0,
			'completed' => 0,
			'failed'    => 0,
			'cancelled' => 0,
			'paused'    => 0,
		);
		if ( is_array( $counts ) ) {
			foreach ( $counts as $row ) {
				$key = isset( $row['status'] ) ? (string) $row['status'] : '';
				if ( '' !== $key && array_key_exists( $key, $summary ) ) {
					$summary[ $key ] = (int) $row['total'];
				}
			}
		}

		$bridge_available = class_exists( 'WP_MCP_AI_Async_Scheduler_Bridge' )
			&& WP_MCP_AI_Async_Scheduler_Bridge::is_available();

		$rows = array(
			array(
				'metric' => 'queued',
				'value'  => $summary['queued'],
			),
			array(
				'metric' => 'running',
				'value'  => $summary['running'],
			),
			array(
				'metric' => 'completed',
				'value'  => $summary['completed'],
			),
			array(
				'metric' => 'failed',
				'value'  => $summary['failed'],
			),
			array(
				'metric' => 'cancelled',
				'value'  => $summary['cancelled'],
			),
			array(
				'metric' => 'paused',
				'value'  => $summary['paused'],
			),
			array(
				'metric' => 'oldest_queued_at',
				'value'  => $oldest ? (string) $oldest : '-',
			),
			array(
				'metric' => 'action_scheduler_bridge',
				'value'  => $bridge_available ? 'available' : 'unavailable',
			),
		);

		$format = WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );
		$this->format_output( $rows, $format, array( 'metric', 'value' ) );
	}

	/**
	 * Enqueue a bulk-interface tool with a JSON payload.
	 *
	 * Useful for retro-running a migration without crafting an assistant
	 * conversation. The payload is queued through the same path the
	 * agentic loop uses, so it benefits from the Action Scheduler bridge
	 * when available.
	 *
	 * ## OPTIONS
	 *
	 * <slug>
	 * : The tool slug to dispatch (e.g. `media_library_optimizer`).
	 *
	 * [--args=<json>]
	 * : JSON-encoded tool arguments.
	 * ---
	 * default: {}
	 * ---
	 *
	 * [--dry-run]
	 * : Resolve the tool and validate the payload without queuing.
	 *
	 * ## EXAMPLES
	 *
	 *     # Enqueue a bulk tool with default arguments.
	 *     $ wp mcp-ai bulk dispatch media_library_optimizer
	 *
	 *     # Pass arguments and dry-run first.
	 *     $ wp mcp-ai bulk dispatch export_fhir_data \
	 *         --args='{"resource":"medication","limit":5000}' --dry-run
	 *
	 * @when after_wp_load
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function dispatch( $args, $assoc_args ) {
		$slug = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';
		if ( '' === $slug ) {
			$this->error( __( 'Tool slug is required.', 'mcp-ai-wpoos' ) );
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$this->error( __( 'Tool registry unavailable.', 'mcp-ai-wpoos' ) );
			return;
		}

		$payload_raw = WP_CLI\Utils\get_flag_value( $assoc_args, 'args', '{}' );
		$arguments   = json_decode( (string) $payload_raw, true );
		if ( ! is_array( $arguments ) ) {
			$this->error( __( '--args must be a JSON object.', 'mcp-ai-wpoos' ) );
			return;
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( $slug );
		if ( ! $tool ) {
			/* translators: %s: tool slug */
			$this->error( sprintf( __( 'Tool not found: %s', 'mcp-ai-wpoos' ), $slug ) );
			return;
		}

		if ( ! ( $tool instanceof WP_MCP_AI_Tool_Bulk_Operation_Interface ) ) {
			/* translators: %s: tool slug */
			$this->error( sprintf( __( '%s does not implement the bulk-operation interface.', 'mcp-ai-wpoos' ), $slug ) );
			return;
		}

		$estimate = (int) $tool->estimate_total( $arguments );

		if ( $this->is_dry_run( $assoc_args ) ) {
			$this->dry_run_notice();
			$this->info(
				sprintf(
					/* translators: 1: tool slug, 2: estimated rows */
					__( 'Would enqueue %1$s for %2$d estimated rows.', 'mcp-ai-wpoos' ),
					$slug,
					$estimate
				)
			);
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Async_Job_Queue' ) ) {
			$this->error( __( 'Async job queue unavailable.', 'mcp-ai-wpoos' ) );
			return;
		}

		$job_id = WP_MCP_AI_Async_Job_Queue::queue_job(
			array(
				'job_type' => 'tool',
				'job_data' => array(
					'tool_slug'      => $slug,
					'arguments'      => $arguments,
					'checkpoint_key' => $tool->get_checkpoint_key( $arguments ),
					'estimated_rows' => $estimate,
				),
			)
		);

		if ( is_wp_error( $job_id ) ) {
			$this->error( $job_id->get_error_message() );
			return;
		}

		$this->success(
			sprintf(
				/* translators: 1: tool slug, 2: job id, 3: estimated rows */
				__( 'Enqueued %1$s as job #%2$d (%3$d estimated rows).', 'mcp-ai-wpoos' ),
				$slug,
				(int) $job_id,
				$estimate
			)
		);
	}

	/**
	 * Purge expired artifact transients written by Phase 1+2 spill helpers.
	 *
	 * Walks the options table for `_transient_timeout_wp_mcp_ai_artifact_*`
	 * rows whose timeout is in the past and deletes both the timeout and
	 * value rows. Safe to run repeatedly.
	 *
	 * ## OPTIONS
	 *
	 * [--dry-run]
	 * : Report rows that would be deleted without touching the database.
	 *
	 * ## EXAMPLES
	 *
	 *     # Show what would be removed.
	 *     $ wp mcp-ai bulk cleanup-artifacts --dry-run
	 *
	 *     # Actually remove expired artifacts.
	 *     $ wp mcp-ai bulk cleanup-artifacts
	 *
	 * @when after_wp_load
	 *
	 * @subcommand cleanup-artifacts
	 *
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function cleanup_artifacts( $args, $assoc_args ) {
		unset( $args );
		global $wpdb;

		$timeout_prefix = '_transient_timeout_';
		$prefix         = $timeout_prefix . WP_MCP_AI_Tool_Artifact_Helper::TRANSIENT_PREFIX;
		$like           = $wpdb->esc_like( $prefix ) . '%';
		$now            = time();
		$dry_run        = $this->is_dry_run( $assoc_args );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s AND option_value < %d",
				$like,
				$now
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( empty( $rows ) ) {
			$this->info( __( 'No expired artifact transients found.', 'mcp-ai-wpoos' ) );
			return;
		}

		if ( $dry_run ) {
			$this->dry_run_notice();
		}

		$deleted = 0;
		foreach ( $rows as $row ) {
			$timeout_key = $row['option_name'];
			$value_key   = '_transient_' . substr( $timeout_key, strlen( $timeout_prefix ) );

			if ( $dry_run ) {
				$this->info( '  - ' . $value_key );
				continue;
			}

			// phpcs:disable WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( $wpdb->options, array( 'option_name' => $timeout_key ) );
			$wpdb->delete( $wpdb->options, array( 'option_name' => $value_key ) );
			// phpcs:enable
			++$deleted;
		}

		if ( $dry_run ) {
			$this->info(
				sprintf(
					/* translators: %d: number of rows */
					__( 'Would delete %d expired artifact transients.', 'mcp-ai-wpoos' ),
					count( $rows )
				)
			);
			return;
		}

		$this->success(
			sprintf(
				/* translators: %d: number of rows */
				__( 'Deleted %d expired artifact transients.', 'mcp-ai-wpoos' ),
				$deleted
			)
		);
	}
}

WP_CLI::add_command( 'mcp-ai bulk', 'WP_MCP_AI_CLI_Bulk_Command' );
