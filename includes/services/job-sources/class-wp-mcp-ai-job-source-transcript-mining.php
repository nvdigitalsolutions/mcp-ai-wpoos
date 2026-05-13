<?php
/**
 * Job-Source adapter: Transcript Mining
 *
 * Bridges WP_MCP_AI_Transcript_Mining_Job into the cron-status Tasks Drawer
 * by implementing Interface_WP_MCP_AI_Cron_Status_Job_Source.
 *
 * Each transient prefixed with WP_MCP_AI_Transcript_Mining_Job::STATE_PREFIX
 * represents one mining job. The adapter performs a bounded LIKE scan (max 50)
 * on the options table and returns the normalized job records.
 *
 * @package   WP_MCP_AI
 * @since     1.9.3
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transcript-mining job-source adapter.
 *
 * @since 1.9.3
 * @implements Interface_WP_MCP_AI_Cron_Status_Job_Source
 */
class WP_MCP_AI_Job_Source_Transcript_Mining implements Interface_WP_MCP_AI_Cron_Status_Job_Source {

	/**
	 * Maximum number of transients to scan per request.
	 *
	 * Mirrors the LIMIT used by the built-in async-tool scanner in
	 * WP_MCP_AI_Cron_Status_Service to keep the overhead bounded.
	 */
	const SCAN_LIMIT = 50;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'transcript_mining';
	}

	/**
	 * {@inheritdoc}
	 *
	 * Scans transients whose key starts with
	 * WP_MCP_AI_Transcript_Mining_Job::STATE_PREFIX, filters to the
	 * requesting user (or all jobs for admins), and returns normalized
	 * records keyed by job_id.
	 *
	 * @param int             $user_id      Requesting user (0 = current).
	 * @param int|string|null $assistant_id Optional assistant scope.
	 * @return array<string,array<string,mixed>>
	 */
	public function get_jobs( $user_id = 0, $assistant_id = null ) {
		if ( ! class_exists( 'WP_MCP_AI_Transcript_Mining_Job' ) ) {
			return array();
		}

		global $wpdb;

		$prefix = WP_MCP_AI_Transcript_Mining_Job::STATE_PREFIX;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded LIKE scan required to enumerate per-job transients; no WP API equivalent.
		$transient_keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT REPLACE(option_name, '_transient_', '') AS transient_key
				FROM {$wpdb->options}
				WHERE option_name LIKE %s
				LIMIT %d",
				$wpdb->esc_like( '_transient_' . $prefix ) . '%',
				self::SCAN_LIMIT
			)
		);

		if ( empty( $transient_keys ) ) {
			return array();
		}

		$user_id  = $user_id > 0 ? (int) $user_id : (int) get_current_user_id();
		$is_admin = user_can( $user_id, 'manage_options' );
		$records  = array();

		foreach ( $transient_keys as $transient_key ) {
			$state = get_transient( $transient_key );

			if ( ! is_array( $state ) || empty( $state['id'] ) ) {
				continue;
			}

			// User-scope filtering — admins see all jobs.
			$job_user = isset( $state['user_id'] ) ? (int) $state['user_id'] : 0;
			if ( ! $is_admin && $job_user !== $user_id ) {
				continue;
			}

			$total     = isset( $state['total'] ) ? (int) $state['total'] : 0;
			$processed = isset( $state['processed'] ) ? (int) $state['processed'] : 0;
			$progress  = $total > 0 ? min( 100, (int) floor( ( $processed * 100 ) / $total ) ) : null;
			$message   = isset( $state['last_message'] ) ? (string) $state['last_message'] : '';
			$job_id    = (string) $state['id'];

			// Derive assistant_id from the stored agent_id when available. The
			// transcript-mining job stores the assistant post ID as `agent_id`
			// (the tool's parameter name). Cast to string for uniform comparison.
			$job_assistant = isset( $state['agent_id'] ) ? (string) $state['agent_id'] : '';

			$records[ $job_id ] = array(
				'job_id'       => $job_id,
				'kind'         => 'transcript_mine',
				'status'       => isset( $state['status'] ) ? (string) $state['status'] : 'pending',
				'created_by'   => $job_user,
				'assistant_id' => $job_assistant,
				'started_at'   => isset( $state['created_at'] ) ? (int) $state['created_at'] : 0,
				'updated_at'   => isset( $state['updated_at'] ) ? (int) $state['updated_at'] : 0,
				'eta'          => null,
				'progress'     => $progress,
				'message'      => $message,
				'cancellable'  => in_array(
					isset( $state['status'] ) ? (string) $state['status'] : '',
					array( 'queued', 'running' ),
					true
				),
				'retryable'    => false,
				'source'       => 'transcript_mining',
			);
		}

		return $records;
	}
}
