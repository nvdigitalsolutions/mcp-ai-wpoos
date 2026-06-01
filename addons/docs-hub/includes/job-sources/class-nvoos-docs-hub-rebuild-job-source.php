<?php
/**
 * Job-Source adapter: Docs Hub Rebuild
 *
 * Bridges NV_oOS_Docs_Hub_Rebuild_Pipeline into the cron-status Tasks Drawer
 * by implementing Interface_WP_MCP_AI_Cron_Status_Job_Source.
 *
 * The Docs Hub rebuild is a single-instance job (only one can run at a
 * time) whose state persists in the `nvoos_docs_hub_rebuild_state` option
 * via NV_oOS_Docs_Hub_Rebuild_State. This adapter surfaces it as a single
 * synthetic job record whenever a rebuild is running or recently completed.
 *
 * @package   NV_oOS_Docs_Hub
 * @since     1.9.3
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Docs Hub rebuild job-source adapter.
 *
 * @since 1.9.3
 * @implements Interface_WP_MCP_AI_Cron_Status_Job_Source
 */
class NV_oOS_Docs_Hub_Rebuild_Job_Source implements Interface_WP_MCP_AI_Cron_Status_Job_Source {

	/**
	 * How many seconds after a rebuild ends to keep the record in the drawer.
	 *
	 * Default: 1 hour, so operators can see the last result without it
	 * persisting forever.
	 */
	const SHOW_AFTER_FINISH_SECONDS = HOUR_IN_SECONDS;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'docs_hub_rebuild';
	}

	/**
	 * {@inheritdoc}
	 *
	 * Returns zero or one record representing the current (or recently
	 * finished) Docs Hub rebuild. Because this is a system-level job with
	 * no per-user scope, it is visible to all admins regardless of the
	 * $user_id and $assistant_id parameters.
	 *
	 * @param int             $user_id      Requesting user (0 = current).
	 * @param int|string|null $assistant_id Optional assistant scope (ignored — rebuild is site-wide).
	 * @return array<string,array<string,mixed>>
	 */
	public function get_jobs( $user_id = 0, $assistant_id = null ) {
		if ( ! class_exists( 'NV_oOS_Docs_Hub_Rebuild_State' ) ) {
			return array();
		}

		// Only admins see this system-level job.
		$user_id = $user_id > 0 ? (int) $user_id : (int) get_current_user_id();
		if ( ! user_can( $user_id, 'manage_options' ) ) {
			return array();
		}

		$state = NV_oOS_Docs_Hub_Rebuild_State::get();

		// Skip the idle/never-run baseline.
		if ( NV_oOS_Docs_Hub_Rebuild_State::PHASE_IDLE === $state['phase'] && 0 === (int) $state['started_at'] ) {
			return array();
		}

		$is_running  = NV_oOS_Docs_Hub_Rebuild_State::is_running( $state );
		$finished_at = (int) $state['finished_at'];

		// For completed/failed/idle rebuilds, only surface them for a while.
		if ( ! $is_running && $finished_at > 0 && ( time() - $finished_at ) > self::SHOW_AFTER_FINISH_SECONDS ) {
			return array();
		}

		$phase     = (string) $state['phase'];
		$total     = (int) $state['total'];
		$processed = (int) $state['processed'];
		$progress  = $total > 0 ? min( 100, (int) round( ( $processed / $total ) * 100 ) ) : null;

		// Map rebuild phase to a normalized status.
		$terminal_phases = array(
			NV_oOS_Docs_Hub_Rebuild_State::PHASE_DONE     => 'completed',
			NV_oOS_Docs_Hub_Rebuild_State::PHASE_FAILED   => 'failed',
			NV_oOS_Docs_Hub_Rebuild_State::PHASE_CANCELED => 'cancelled',
			NV_oOS_Docs_Hub_Rebuild_State::PHASE_IDLE     => 'completed',
		);

		$status = isset( $terminal_phases[ $phase ] ) ? $terminal_phases[ $phase ] : 'running';

		$job_id = '' !== (string) $state['job_id'] ? (string) $state['job_id'] : 'docs_hub_rebuild';

		return array(
			$job_id => array(
				'job_id'       => $job_id,
				'kind'         => 'docs_hub_rebuild',
				'status'       => $status,
				'created_by'   => 0, // System job — no specific user.
				'assistant_id' => '',
				'started_at'   => (int) $state['started_at'],
				'updated_at'   => (int) $state['updated_at'],
				'eta'          => null,
				'progress'     => $progress,
				'message'      => sprintf(
					/* translators: %s: current rebuild phase name */
					__( 'Docs Hub rebuild: %s', 'mcp-ai-wpoos' ),
					$phase
				),
				'cancellable'  => false,
				'retryable'    => false,
				'source'       => 'docs_hub_rebuild',
			),
		);
	}
}
