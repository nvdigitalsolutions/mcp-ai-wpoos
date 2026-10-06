<?php
/**
 * WP-CLI `mcp-ai queue` command for NV oOS.
 *
 * Extracted from includes/class-wp-mcp-ai-cli-command.php
 * (Proposal 050, WP-CLI parity & hardening).
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/class-wp-mcp-ai-cli-base-command.php';

if ( ! class_exists( 'WP_MCP_AI_CLI_Queue_Command' ) ) {
	class WP_MCP_AI_CLI_Queue_Command extends WP_MCP_AI_CLI_Base_Command {

		/**
		 * Display queue statistics.
		 *
		 * ## EXAMPLES
		 *
		 *     wp mcp-ai queue stats
		 *
		 * @subcommand stats
		 * @when after_wp_load
		 */
		public function stats() {
			$stats = WP_MCP_AI_Job_Queue_Manager::get_queue_stats();

			WP_CLI::line( 'Job Queue Statistics:' );
			WP_CLI::line( '  Total jobs:   ' . $stats['total'] );
			WP_CLI::line( '  Active jobs:  ' . $stats['active'] );
			WP_CLI::line( '  Pending jobs: ' . $stats['pending'] );
			WP_CLI::line( '  Failed jobs:  ' . $stats['failed'] );
		}

		/**
		 * Process the job queue.
		 *
		 * ## OPTIONS
		 *
		 * [--max-concurrent=<num>]
		 * : Maximum number of concurrent jobs to process. Default: 3
		 *
		 * ## EXAMPLES
		 *
		 *     wp mcp-ai queue process
		 *     wp mcp-ai queue process --max-concurrent=5
		 *
		 * @subcommand process
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 * @when after_wp_load
		 */
		public function process( $args, $assoc_args ) {
			$max_concurrent = isset( $assoc_args['max-concurrent'] ) ? absint( $assoc_args['max-concurrent'] ) : 3;

			$this->require_capability( 'manage_options' );

			WP_CLI::line( 'Processing job queue...' );

			$result = WP_MCP_AI_Job_Queue_Manager::process_queue( $max_concurrent );

			WP_CLI::success(
				sprintf(
					'Processed %d jobs. %d jobs currently active.',
					$result['processed'],
					$result['active']
				)
			);
		}

		/**
		 * Clear the job queue.
		 *
		 * ## EXAMPLES
		 *
		 *     wp mcp-ai queue clear
		 *
		 * @subcommand clear
		 * @when after_wp_load
		 */
		public function clear() {
			$this->require_capability( 'manage_options' );

			WP_CLI::confirm( 'Are you sure you want to clear the entire job queue?' );

			WP_MCP_AI_Job_Queue_Manager::clear_queue();

			WP_CLI::success( 'Job queue cleared.' );
		}

			/**
			 * Retry a failed job from the queue.
			 *
			 * ## OPTIONS
			 *
			 * <job-id>
			 * : The ID of the job to retry.
			 *
			 * ## EXAMPLES
			 *
			 *     wp mcp-ai queue retry job_abc123
			 *
			 * @subcommand retry
			 * @param array $args       Positional arguments.
			 * @param array $assoc_args Associative arguments.
		 * @when after_wp_load
			 */
		public function retry( $args, $assoc_args ) {
			if ( empty( $args ) || ! isset( $args[0] ) ) {
				WP_CLI::error( __( 'Please provide a job ID.', 'mcp-ai-wpoos' ) );
				return;
			}

			$job_id = sanitize_key( $args[0] );

			if ( '' === $job_id ) {
				WP_CLI::error( __( 'Invalid job ID.', 'mcp-ai-wpoos' ) );
				return;
			}

			$this->require_capability( 'manage_options' );

			if ( ! class_exists( 'WP_MCP_AI_Tool_Async_Executor' ) ) {
				WP_CLI::error( __( 'Async executor class is not available.', 'mcp-ai-wpoos' ) );
				return;
			}

			$executor = new WP_MCP_AI_Tool_Async_Executor();
			$result   = $executor->retry_job( $job_id );

			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
				return;
			}

			WP_CLI::success(
				sprintf(
					/* translators: %s: job ID */
					__( 'Job %s has been re-queued for retry.', 'mcp-ai-wpoos' ),
					$job_id
				)
			);
		}

			/**
			 * Show details for a single job in the queue.
			 *
			 * ## OPTIONS
			 *
			 * <job-id>
			 * : The ID of the job to show.
			 *
			 * [--format=<format>]
			 * : Output format.
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
			 *     wp mcp-ai queue show job_abc123
			 *     wp mcp-ai queue show job_abc123 --format=json
			 *
			 * @subcommand show
			 * @param array $args       Positional arguments.
			 * @param array $assoc_args Associative arguments.
		 * @when after_wp_load
			 */
		public function show( $args, $assoc_args ) {
			if ( empty( $args ) || ! isset( $args[0] ) ) {
				WP_CLI::error( __( 'Please provide a job ID.', 'mcp-ai-wpoos' ) );
				return;
			}

			$job_id = sanitize_key( $args[0] );

			if ( '' === $job_id ) {
				WP_CLI::error( __( 'Invalid job ID.', 'mcp-ai-wpoos' ) );
				return;
			}

			$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

			$queue = class_exists( 'WP_MCP_AI_Job_Queue_Manager' )
				? get_option( WP_MCP_AI_Job_Queue_Manager::QUEUE_STATE_OPTION, array() )
				: array();
			if ( ! is_array( $queue ) ) {
				$queue = array();
			}

			if ( ! isset( $queue[ $job_id ] ) ) {
				WP_CLI::error(
					sprintf(
						/* translators: %s: job ID */
						__( 'Job not found: %s', 'mcp-ai-wpoos' ),
						$job_id
					)
				);
				return;
			}

			$job = $queue[ $job_id ];

			$items = array(
				array(
					'metric' => __( 'Job ID', 'mcp-ai-wpoos' ),
					'value'  => $job_id,
				),
				array(
					'metric' => __( 'Status', 'mcp-ai-wpoos' ),
					'value'  => isset( $job['status'] ) ? $job['status'] : __( 'unknown', 'mcp-ai-wpoos' ),
				),
				array(
					'metric' => __( 'Priority', 'mcp-ai-wpoos' ),
					'value'  => isset( $job['priority'] ) ? absint( $job['priority'] ) : 5,
				),
				array(
					'metric' => __( 'SLA Tier', 'mcp-ai-wpoos' ),
					'value'  => isset( $job['sla_tier'] ) ? $job['sla_tier'] : '—',
				),
				array(
					'metric' => __( 'Retry Count', 'mcp-ai-wpoos' ),
					'value'  => isset( $job['retry_count'] ) ? absint( $job['retry_count'] ) : 0,
				),
				array(
					'metric' => __( 'Timeout', 'mcp-ai-wpoos' ),
					'value'  => isset( $job['timeout'] ) ? absint( $job['timeout'] ) . 's' : '300s',
				),
				array(
					'metric' => __( 'Enqueued At', 'mcp-ai-wpoos' ),
					'value'  => isset( $job['enqueued_at'] ) ? gmdate( 'Y-m-d H:i:s', absint( $job['enqueued_at'] ) ) : '—',
				),
				array(
					'metric' => __( 'Last Error', 'mcp-ai-wpoos' ),
					'value'  => isset( $job['last_error'] ) ? $job['last_error'] : '—',
				),
			);

			\WP_CLI\Utils\format_items( $format, $items, array( 'metric', 'value' ) );
		}
	}

}

if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai queue', 'WP_MCP_AI_CLI_Queue_Command' );
}
