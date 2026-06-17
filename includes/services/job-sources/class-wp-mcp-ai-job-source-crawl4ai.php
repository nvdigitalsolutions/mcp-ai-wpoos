<?php
/**
 * Job-Source adapter: Crawl4AI
 *
 * Bridges WP_MCP_AI_Crawler into the cron-status Tasks Drawer by
 * implementing Interface_WP_MCP_AI_Cron_Status_Job_Source.
 *
 * Crawl4AI jobs are stored as transients keyed by a hash of the task_id
 * (see WP_MCP_AI_Crawler::get_storage_key()). The adapter scans the options
 * table for the prefix and reads each transient to build the normalized list.
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
 * Crawl4AI job-source adapter.
 *
 * @since 1.9.3
 * @implements Interface_WP_MCP_AI_Cron_Status_Job_Source
 */
class WP_MCP_AI_Job_Source_Crawl4AI implements Interface_WP_MCP_AI_Cron_Status_Job_Source {

	/**
	 * Maximum number of transients to scan per request.
	 */
	const SCAN_LIMIT = 50;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'crawl4ai';
	}

	/**
	 * {@inheritdoc}
	 *
	 * Scans transients whose key starts with WP_MCP_AI_Crawler::JOB_STORAGE_PREFIX,
	 * filters to the requesting user (or all jobs for admins), and returns
	 * normalized records keyed by task_id (used as job_id).
	 *
	 * @param int             $user_id      Requesting user (0 = current).
	 * @param int|string|null $assistant_id Optional assistant scope.
	 * @return array<string,array<string,mixed>>
	 */
	public function get_jobs( $user_id = 0, $assistant_id = null ) {
		if ( ! class_exists( 'WP_MCP_AI_Crawler' ) ) {
			return array();
		}

		global $wpdb;

		$prefix   = WP_MCP_AI_Crawler::JOB_STORAGE_PREFIX;
		$is_multi = is_multisite();

		// Multisite stores in site_transients (sitemeta table).
		if ( $is_multi ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded LIKE scan; no WP API equivalent for site-transient enumeration.
			$transient_keys = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT REPLACE(meta_key, '_site_transient_', '') AS transient_key
					FROM {$wpdb->sitemeta}
					WHERE meta_key LIKE %s
					LIMIT %d",
					$wpdb->esc_like( '_site_transient_' . $prefix ) . '%',
					self::SCAN_LIMIT
				)
			);
		} else {
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
		}

		if ( empty( $transient_keys ) ) {
			return array();
		}

		$user_id  = $user_id > 0 ? (int) $user_id : (int) get_current_user_id();
		$is_admin = user_can( $user_id, 'manage_options' );
		$records  = array();

		foreach ( $transient_keys as $transient_key ) {
			if ( $is_multi ) {
				$job = get_site_transient( $transient_key );
			} else {
				$job = get_transient( $transient_key );
			}

			if ( ! is_array( $job ) || empty( $job['task_id'] ) ) {
				continue;
			}

			$context  = isset( $job['context'] ) && is_array( $job['context'] ) ? $job['context'] : array();
			$job_user = isset( $context['user_id'] ) ? (int) $context['user_id'] : 0;

			// User-scope filtering — admins see all jobs.
			if ( ! $is_admin && $job_user !== $user_id ) {
				continue;
			}

			$job_assistant = isset( $context['assistant_id'] ) ? (string) $context['assistant_id'] : '';
			$task_id       = (string) $job['task_id'];
			$status        = isset( $job['status'] ) ? (string) $job['status'] : 'pending';

			// Map Crawl4AI-specific statuses to the normalized contract.
			$status_map = array(
				'pending'   => 'pending',
				'running'   => 'running',
				'polling'   => 'polling',
				'completed' => 'completed',
				'failed'    => 'failed',
				'cancelled' => 'cancelled',
			);
			if ( ! isset( $status_map[ $status ] ) ) {
				$status = 'pending';
			}

			$records[ $task_id ] = array(
				'job_id'       => $task_id,
				'kind'         => 'crawl4ai',
				'status'       => $status,
				'created_by'   => $job_user,
				'assistant_id' => $job_assistant,
				'started_at'   => isset( $job['created_at'] ) ? (int) $job['created_at'] : 0,
				'updated_at'   => isset( $job['updated_at'] ) ? (int) $job['updated_at'] : 0,
				'eta'          => null,
				'progress'     => null,
				'message'      => isset( $job['base_url'] ) ? (string) $job['base_url'] : '',
				'cancellable'  => false,
				'retryable'    => false,
				'source'       => 'crawl4ai',
			);
		}

		return $records;
	}
}
