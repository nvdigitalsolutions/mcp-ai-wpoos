<?php
/**
 * WP-CLI command for transcript mining management.
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
 * Manage transcript mining jobs.
 *
 * @since 1.1.30
 */
class WP_MCP_AI_CLI_Transcript_Command extends WP_MCP_AI_CLI_Base_Command {

	/**
	 * Start a new transcript mining job.
	 *
	 * ## OPTIONS
	 *
	 * [--assistant=<id>]
	 * : Filter transcripts by assistant post ID.
	 *
	 * [--user=<id>]
	 * : Filter transcripts by user ID.
	 *
	 * [--since=<date>]
	 * : Only mine transcripts after this date (Y-m-d format).
	 *
	 * [--min-messages=<number>]
	 * : Minimum messages per transcript (default: 3).
	 * ---
	 * default: 3
	 * ---
	 *
	 * [--batch-size=<number>]
	 * : Sessions per tick (default: 10).
	 * ---
	 * default: 10
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai transcript mine --assistant=42
	 *     $ wp mcp-ai transcript mine --since=2026-06-01 --min-messages=5
	 *
	 * @when after_wp_load
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function mine( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$this->ensure_transcript_mining_loaded();

		$assistant_id = isset( $assoc_args['assistant'] ) ? absint( $assoc_args['assistant'] ) : 0;
		$user_id      = isset( $assoc_args['user'] ) ? absint( $assoc_args['user'] ) : 0;
		$since        = sanitize_text_field( (string) ( $assoc_args['since'] ?? '' ) );
		$min_messages = absint( $assoc_args['min-messages'] ?? 3 );
		$batch_size   = min( 50, absint( $assoc_args['batch-size'] ?? 10 ) );

		// Build transcript query.
		$transcript_query = array(
			'min_messages'   => $min_messages,
			'posts_per_page' => min( 500, $batch_size * 10 ),
		);

		if ( $assistant_id > 0 ) {
			$transcript_query['assistant_id'] = $assistant_id;
		}
		if ( $user_id > 0 ) {
			$transcript_query['user_id'] = $user_id;
		}
		if ( '' !== $since ) {
			$transcript_query['since'] = $since;
		}

		$args_array = array(
			'source'           => 'transcripts',
			'transcript_query' => $transcript_query,
			'auto_enrich'      => true,
		);

		$config = array(
			'batch_size' => $batch_size,
		);

		$job = WP_MCP_AI_Transcript_Mining_Job::enqueue( $args_array, $config );

		if ( is_wp_error( $job ) ) {
			$this->error( $job->get_error_message() );
		}

		$this->success(
			sprintf(
				/* translators: %s: job ID */
				__( 'Mining job created: %s', 'mcp-ai-wpoos' ),
				$job['job_id'] ?? 'unknown'
			)
		);

		WP_CLI::log(
			sprintf(
				/* translators: %s: job status */
				__( '  Status: %s', 'mcp-ai-wpoos' ),
				$job['status'] ?? ''
			)
		);
		WP_CLI::log(
			sprintf(
				/* translators: %d: number of queued sessions */
				__( '  Sessions queued: %d', 'mcp-ai-wpoos' ),
				$job['total_sessions'] ?? 0
			)
		);
	}

	/**
	 * Check the status of a transcript mining job.
	 *
	 * ## OPTIONS
	 *
	 * <job-id>
	 * : The mining job ID.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai transcript status abc123
	 *
	 * @when after_wp_load
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$job_id = sanitize_text_field( (string) ( $args[0] ?? '' ) );

		if ( '' === $job_id ) {
			$this->error( __( 'Job ID is required.', 'mcp-ai-wpoos' ) );
		}

		$this->ensure_transcript_mining_loaded();

		$state = WP_MCP_AI_Transcript_Mining_Job::get_job_state( $job_id );

		if ( is_wp_error( $state ) ) {
			$this->error( $state->get_error_message() );
		}

		if ( ! $state ) {
			$this->error(
				sprintf(
					/* translators: %s: job ID */
					__( 'Job "%s" not found. It may have expired (TTL: 6h).', 'mcp-ai-wpoos' ),
					$job_id
				)
			);
		}

		$items = array(
			array(
				'Field' => 'Job ID',
				'Value' => $job_id,
			),
			array(
				'Field' => 'Status',
				'Value' => $state['status'] ?? 'unknown',
			),
			array(
				'Field' => 'Progress',
				'Value' => sprintf( '%d / %d', $state['processed_sessions'] ?? 0, $state['total_sessions'] ?? 0 ),
			),
			array(
				'Field' => 'Memories Created',
				'Value' => $state['memories_created'] ?? 0,
			),
			array(
				'Field' => 'Errors',
				'Value' => $state['error_count'] ?? 0,
			),
			array(
				'Field' => 'Created At',
				'Value' => isset( $state['created_at'] ) ? wp_date( 'Y-m-d H:i:s', $state['created_at'] ) : '-',
			),
			array(
				'Field' => 'Last Tick',
				'Value' => isset( $state['last_tick'] ) ? wp_date( 'Y-m-d H:i:s', $state['last_tick'] ) : '-',
			),
		);

		$this->format_output( $items, 'table' );
	}

	/**
	 * Cancel a running transcript mining job.
	 *
	 * ## OPTIONS
	 *
	 * <job-id>
	 * : The mining job ID to cancel.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai transcript cancel abc123
	 *
	 * @when after_wp_load
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function cancel( $args, $assoc_args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$job_id = sanitize_text_field( (string) ( $args[0] ?? '' ) );

		if ( '' === $job_id ) {
			$this->error( __( 'Job ID is required.', 'mcp-ai-wpoos' ) );
		}

		$this->ensure_transcript_mining_loaded();

		$result = WP_MCP_AI_Transcript_Mining_Job::cancel( $job_id );

		if ( is_wp_error( $result ) ) {
			$this->error( $result->get_error_message() );
		}

		$this->success(
			sprintf(
				/* translators: %s: job ID */
				__( 'Job "%s" cancelled.', 'mcp-ai-wpoos' ),
				$job_id
			)
		);
	}

	/**
	 * List available transcripts (alias for "mine").
	 *
	 * Starts a transcript mining job, which processes stored chat
	 * transcripts to extract agent memories.  This is the same as
	 * `wp mcp-ai transcript mine`.
	 *
	 * ## OPTIONS
	 *
	 * [--assistant=<id>]
	 * : Filter transcripts by assistant post ID.
	 *
	 * [--assistant-id=<id>]
	 * : Alias for --assistant.
	 *
	 * [--user=<id>]
	 * : Filter transcripts by user ID.
	 *
	 * [--since=<date>]
	 * : Only mine transcripts after this date (Y-m-d format).
	 *
	 * [--min-messages=<number>]
	 * : Minimum messages per transcript (default: 3).
	 * ---
	 * default: 3
	 * ---
	 *
	 * [--batch-size=<number>]
	 * : Sessions per tick (default: 10).
	 * ---
	 * default: 10
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai transcript list --assistant=42
	 *
	 * @subcommand list
	 * @when after_wp_load
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 */
	public function list( $args, $assoc_args ) {
		// Accept --assistant-id as an alias for --assistant.
		if ( ! isset( $assoc_args['assistant'] ) && isset( $assoc_args['assistant-id'] ) ) {
			$assoc_args['assistant'] = $assoc_args['assistant-id'];
		}
		$this->mine( $args, $assoc_args );
	}

	/**
	 * Ensure the transcript mining class is loaded.
	 */
	private function ensure_transcript_mining_loaded() {
		if ( ! class_exists( 'WP_MCP_AI_Transcript_Mining_Job' ) ) {
			$file = WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-transcript-mining-job.php';
			if ( file_exists( $file ) ) {
				require_once $file;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Transcript_Mining_Job' ) ) {
			$this->error( __( 'Transcript mining service is not available. Ensure the plugin is properly installed.', 'mcp-ai-wpoos' ) );
		}
	}
}

WP_CLI::add_command( 'mcp-ai transcript', 'WP_MCP_AI_CLI_Transcript_Command' );
