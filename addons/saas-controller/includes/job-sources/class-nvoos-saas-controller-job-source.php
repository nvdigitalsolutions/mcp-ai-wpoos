<?php
/**
 * Job-Source adapter: SaaS Controller Apply Jobs
 *
 * Bridges NVOOS_SaaS_Controller_Apply_Job into the cron-status Tasks Drawer
 * by implementing Interface_WP_MCP_AI_Cron_Status_Job_Source.
 *
 * Apply jobs are stored as transients keyed by
 * NVOOS_SaaS_Controller_Apply_Job::STATE_PREFIX . $job_id.
 * The adapter scans the options table for the prefix and returns the
 * normalized records.
 *
 * @package   NVOOS_SaaS_Controller
 * @since     1.9.3
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * SaaS Apply job-source adapter.
 *
 * @since 1.9.3
 * @implements Interface_WP_MCP_AI_Cron_Status_Job_Source
 */
class NVOOS_SaaS_Controller_Job_Source implements Interface_WP_MCP_AI_Cron_Status_Job_Source {

	/**
	 * Maximum number of transients to scan per request.
	 */
	const SCAN_LIMIT = 50;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'saas_apply';
	}

	/**
	 * {@inheritdoc}
	 *
	 * Scans transients whose key starts with
	 * NVOOS_SaaS_Controller_Apply_Job::STATE_PREFIX, filters to the
	 * requesting user (or all for admins), and returns normalized records
	 * keyed by job_id.
	 *
	 * @param int             $user_id      Requesting user (0 = current).
	 * @param int|string|null $assistant_id Optional assistant scope (not stored on apply jobs).
	 * @return array<string,array<string,mixed>>
	 */
	public function get_jobs( $user_id = 0, $assistant_id = null ) {
		if ( ! class_exists( 'NVOOS_SaaS_Controller_Apply_Job' ) ) {
			return array();
		}

		global $wpdb;

		$prefix = NVOOS_SaaS_Controller_Apply_Job::STATE_PREFIX;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded LIKE scan; no WP API equivalent for transient enumeration.
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

			$job_user = isset( $state['user_id'] ) ? (int) $state['user_id'] : 0;

			// User-scope filtering — admins see all jobs.
			if ( ! $is_admin && $job_user !== $user_id ) {
				continue;
			}

			$total     = isset( $state['total'] ) ? (int) $state['total'] : 0;
			$processed = isset( $state['processed'] ) ? (int) $state['processed'] : 0;
			$progress  = $total > 0 ? min( 100, (int) floor( ( $processed * 100 ) / $total ) ) : null;
			$job_id    = (string) $state['id'];
			$status    = isset( $state['status'] ) ? (string) $state['status'] : 'queued';

			$records[ $job_id ] = array(
				'job_id'       => $job_id,
				'kind'         => 'saas_apply',
				'status'       => $status,
				'created_by'   => $job_user,
				'assistant_id' => '',
				'started_at'   => isset( $state['created_at'] ) ? (int) $state['created_at'] : 0,
				'updated_at'   => isset( $state['updated_at'] ) ? (int) $state['updated_at'] : 0,
				'eta'          => null,
				'progress'     => $progress,
				'message'      => isset( $state['last_message'] ) ? (string) $state['last_message'] : '',
				'cancellable'  => in_array( $status, array( 'queued', 'running' ), true ),
				'retryable'    => false,
				'source'       => 'saas_apply',
			);
		}

		return $records;
	}
}
