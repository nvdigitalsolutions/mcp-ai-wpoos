<?php
/**
 * WP-CLI command for managing WordPress cron jobs.
 *
 * @package WP_MCP_AI
 * @since   1.1.30
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/class-wp-mcp-ai-cli-base-command.php';

/**
 * Manage scheduled cron jobs tracked by NV oOS.
 *
 * @since 1.1.30
 */
class WP_MCP_AI_CLI_Cron_Command extends WP_MCP_AI_CLI_Base_Command {

	/**
	 * List all NV oOS-tracked cron jobs.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai cron list
	 *     $ wp mcp-ai cron list --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list_( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$format = $assoc_args['format'] ?? 'table';

		if ( ! class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
			$this->error( __( 'Cron manager not available.', 'mcp-ai-wpoos' ) );
		}

		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();

		if ( empty( $jobs ) ) {
			$this->warning( __( 'No cron jobs found.', 'mcp-ai-wpoos' ) );
			return;
		}

		$items = array();
		foreach ( $jobs as $job_id => $job ) {
			$hook  = $job['hook'] ?? '';
			$args  = isset( $job['args'] ) && is_array( $job['args'] ) ? $job['args'] : array();
			$event = wp_get_scheduled_event( $hook, WP_MCP_AI_Cron_Manager::normalise_args( $args ) );

			$items[] = array(
				'ID'       => mb_strimwidth( $job_id, 0, 16, '…' ),
				'Hook'     => $hook,
				'Schedule' => $job['schedule'] ?? 'single',
				'Next Run' => $event ? wp_date( 'Y-m-d H:i', $event->timestamp ) : __( 'Not scheduled', 'mcp-ai-wpoos' ),
				'Created'  => isset( $job['created_at'] ) ? wp_date( 'Y-m-d H:i', $job['created_at'] ) : '-',
			);
		}

		$this->format_output( $items, $format );
		$this->success(
			sprintf(
				/* translators: %d: number of cron jobs */
				__( 'Found %d cron jobs.', 'mcp-ai-wpoos' ),
				count( $jobs )
			)
		);
	}

	/**
	 * Run a scheduled cron job immediately.
	 *
	 * ## OPTIONS
	 *
	 * <job-id>
	 * : The job ID to run.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai cron run abc123def456
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function run( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$job_id = sanitize_text_field( (string) ( $args[0] ?? '' ) );

		if ( '' === $job_id ) {
			$this->error( __( 'Job ID is required.', 'mcp-ai-wpoos' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
			$this->error( __( 'Cron manager not available.', 'mcp-ai-wpoos' ) );
		}

		$job = WP_MCP_AI_Cron_Manager::get_job( $job_id );

		if ( ! $job ) {
			$this->error(
				sprintf(
					/* translators: %s: job ID */
					__( 'Job "%s" not found.', 'mcp-ai-wpoos' ),
					$job_id
				)
			);
		}

		$hook = $job['hook'] ?? '';
		if ( '' === $hook ) {
			$this->error( __( 'Job has no hook. It may be corrupt.', 'mcp-ai-wpoos' ) );
		}

		$args = isset( $job['args'] ) && is_array( $job['args'] ) ? $job['args'] : array();

		WP_CLI::log(
			sprintf(
				/* translators: %1$s: job ID, %2$s: hook name */
				__( 'Running job "%1$s" (hook: %2$s)…', 'mcp-ai-wpoos' ),
				$job_id,
				$hook
			)
		);

		$start = microtime( true );
		do_action_ref_array( $hook, $args );
		$elapsed = round( ( microtime( true ) - $start ) * 1000, 2 );

		$this->success(
			sprintf(
				/* translators: %s: elapsed time in milliseconds */
				__( 'Job executed in %s ms.', 'mcp-ai-wpoos' ),
				$elapsed
			)
		);
	}

	/**
	 * Delete a tracked cron job.
	 *
	 * ## OPTIONS
	 *
	 * <job-id>
	 * : The job ID to delete.
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai cron delete abc123 --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function delete( $args, $assoc_args ) {
		$job_id = sanitize_text_field( (string) ( $args[0] ?? '' ) );

		if ( '' === $job_id ) {
			$this->error( __( 'Job ID is required.', 'mcp-ai-wpoos' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
			$this->error( __( 'Cron manager not available.', 'mcp-ai-wpoos' ) );
		}

		$job = WP_MCP_AI_Cron_Manager::get_job( $job_id );
		if ( ! $job ) {
			$this->error(
				sprintf(
					/* translators: %s: job ID */
					__( 'Job "%s" not found.', 'mcp-ai-wpoos' ),
					$job_id
				)
			);
		}

		if ( ! $this->confirm(
			sprintf(
				/* translators: %1$s: job ID, %2$s: hook name */
				__( 'Delete cron job "%1$s" (hook: %2$s)?', 'mcp-ai-wpoos' ),
				$job_id,
				$job['hook']
			),
			$assoc_args
		) ) {
			$this->warning( __( 'Operation cancelled.', 'mcp-ai-wpoos' ) );
			return;
		}

		WP_MCP_AI_Cron_Manager::remove_job( $job_id );
		$this->success(
			sprintf(
				/* translators: %s: job ID */
				__( 'Job "%s" deleted.', 'mcp-ai-wpoos' ),
				$job_id
			)
		);
	}

	/**
	 * Clear all tracked cron jobs.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai cron clear --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function clear( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
			$this->error( __( 'Cron manager not available.', 'mcp-ai-wpoos' ) );
		}

		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		if ( empty( $jobs ) ) {
			$this->warning( __( 'No cron jobs to clear.', 'mcp-ai-wpoos' ) );
			return;
		}

		if ( ! $this->confirm(
			sprintf(
				/* translators: %d: number of cron jobs to clear */
				__( 'Clear all %d tracked cron jobs? This cannot be undone.', 'mcp-ai-wpoos' ),
				count( $jobs )
			),
			$assoc_args
		) ) {
			$this->warning( __( 'Operation cancelled.', 'mcp-ai-wpoos' ) );
			return;
		}

		$count = 0;
		foreach ( array_keys( $jobs ) as $id ) {
			WP_MCP_AI_Cron_Manager::remove_job( $id );
			++$count;
		}

		$this->success(
			sprintf(
				/* translators: %d: number of cron jobs cleared */
				__( 'Cleared %d cron jobs.', 'mcp-ai-wpoos' ),
				$count
			)
		);
	}
}

WP_CLI::add_command( 'mcp-ai cron', 'WP_MCP_AI_CLI_Cron_Command' );
