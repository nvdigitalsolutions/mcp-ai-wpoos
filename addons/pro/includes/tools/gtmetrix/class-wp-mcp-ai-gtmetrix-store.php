<?php
/**
 * GTmetrix Toolkit — Report Store Interface.
 *
 * Storage-agnostic reader contract for report snapshots: the snapshot store
 * (CPT-backed) and the fixture reader implement this interface so the metric
 * and analysis layers never depend on a storage implementation.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.5
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Report snapshot store contract.
 *
 * @since 1.2.5
 */
interface WP_MCP_AI_GTmetrix_Store {

	/**
	 * Save a report snapshot.
	 *
	 * @param array $report Report attributes (see the proposal Appendix A.2).
	 * @return int|WP_Error Snapshot post ID or WP_Error.
	 */
	public function save_report( $report );

	/**
	 * Get a snapshot by GTmetrix report ID.
	 *
	 * @param string $report_id GTmetrix report ID.
	 * @return array|WP_Error
	 */
	public function get_report( $report_id );

	/**
	 * Latest snapshot for a URL.
	 *
	 * @param string $url Page URL.
	 * @return array|WP_Error
	 */
	public function get_latest_for_url( $url );

	/**
	 * Snapshots for a URL, newest first.
	 *
	 * @param string $url   Page URL.
	 * @param int    $limit Maximum entries.
	 * @return array
	 */
	public function list_for_url( $url, $limit = 10 );

	/**
	 * Remove snapshots older than the retention window.
	 *
	 * @param int $retention_days Retention window in days.
	 * @return int Number of removed snapshots.
	 */
	public function prune( $retention_days );
}
