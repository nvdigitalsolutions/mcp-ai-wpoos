<?php
/**
 * Food & Beverage Management Toolkit — Native-storage Retention.
 *
 * Daily cron that prunes the five transactional native-storage CPTs down to a
 * configurable retention window, mirroring the Health Records vitals
 * `run_retention_sweep` pattern (bounded batches, one daily hook) but operating
 * on WordPress posts (`wp_delete_post`) instead of a raw JetEngine CCT table.
 *
 * Disabled in two ways, both intentional: the cron is only scheduled when
 * `enable_fnb_native_storage` is on, and a retention window of 0 means "keep
 * forever" (no sweep runs).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-fnb-settings.php';
require_once __DIR__ . '/class-wp-mcp-ai-fnb-cpt-reader.php';

/**
 * Native-storage retention sweep for the F&B transactional CPTs.
 *
 * @since 1.6.0
 */
class WP_MCP_AI_Fnb_Ingest_Retention {

	const CRON_HOOK       = 'wp_mcp_ai_fnb_native_retention_sweep';
	const PRUNE_BATCH     = 500;
	const PRUNE_MAX_BATCH = 20;

	/**
	 * Hook the retention cron on `init` (after the CPTs register at priority 12).
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_retention_sweep' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule_retention' ), 20 );
	}

	/**
	 * Schedule the daily sweep if native storage is on and it isn't scheduled.
	 *
	 * @return void
	 */
	public static function maybe_schedule_retention() {
		if ( ! WP_MCP_AI_Fnb_Settings::is_native_storage_enabled() ) {
			return;
		}
		if ( WP_MCP_AI_Fnb_Settings::retention_days() < 1 ) {
			return; // Keep forever — no sweep needed.
		}
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Clear the scheduled sweep (used on deactivation/toggle-off).
	 *
	 * @return void
	 */
	public static function clear_schedule() {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Run the retention sweep: delete native-storage posts older than the
	 * retention window, in bounded batches so one run never issues a huge query.
	 *
	 * @return int Number of rows deleted.
	 */
	public static function run_retention_sweep() {
		$days = WP_MCP_AI_Fnb_Settings::retention_days();
		if ( $days < 1 ) {
			return 0; // Keep forever.
		}
		if ( ! WP_MCP_AI_Fnb_Settings::is_native_storage_enabled() ) {
			return 0;
		}

		$cutoff  = strtotime( '-' . $days . ' days' );
		$deleted = 0;

		foreach ( WP_MCP_AI_Fnb_Cpt_Reader::native_tables() as $entry ) {
			$post_type = $entry['post_type'];
			if ( ! post_type_exists( $post_type ) ) {
				continue;
			}

			for ( $batch = 0; $batch < self::PRUNE_MAX_BATCH; $batch++ ) {
				$posts = get_posts(
					array(
						'post_type'        => $post_type,
						'post_status'      => 'publish',
						'numberposts'      => self::PRUNE_BATCH,
						'fields'           => 'ids',
						'no_found_rows'    => true,
						'suppress_filters' => true,
						'date_query'       => array(
							array(
								'column' => 'post_date',
								'before' => gmdate( 'Y-m-d H:i:s', $cutoff ),
							),
						),
						'orderby'          => 'date',
						'order'            => 'ASC',
					)
				);

				if ( empty( $posts ) ) {
					break;
				}

				foreach ( $posts as $post_id ) {
					if ( wp_delete_post( (int) $post_id, true ) ) {
						++$deleted;
					}
				}

				if ( count( $posts ) < self::PRUNE_BATCH ) {
					break;
				}
			}
		}

		return $deleted;
	}
}
