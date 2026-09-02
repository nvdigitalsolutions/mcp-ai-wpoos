<?php
/**
 * Chat Transcript Retention Manager.
 *
 * Provides automated retention policies for stored chat transcripts with
 * user-facing transparency and GDPR-compliant data lifecycle management.
 *
 * Ported 1:1 from the base plugin's
 * `includes/class-wp-mcp-ai-transcript-retention.php` (behaviour-preserving;
 * base copy is retained permanently — ecosystem port plan D-NOBASE).
 *
 * Decoupling (documented, additive):
 * - Settings reads go through `get_setting()` — the base's
 *   `WP_MCP_AI_Settings_Registry` in monolith installs, the CG-AI settings
 *   store (`nvoos_content_graph_settings`) in standalone installs. Defaults
 *   for the four retention keys live in `Plugin::addDefaultSettings()`.
 * - Transcript storage goes through `get_transcript_table()` — the base
 *   repository in monolith installs; empty in standalone installs until the
 *   transcript-storage subsystem is ported, which makes every sweep a
 *   graceful no-op and stats report `available => false` (same shape as the
 *   base without its repository).
 * - The cron hook (`wp_mcp_ai_transcript_retention_sweep`) and the REST
 *   route (`mcp-ai/v1/chat-transcripts/{id}` DELETE) are byte-identical;
 *   `init()` is wired standalone-only by `Plugin.php` (the base owns both
 *   in monolith installs — double registration would double-sweep).
 *
 * @package NvoosContentGraphAi\Chat
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Chat;

use NvoosContentGraphAi\CoreBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transcript retention manager.
 *
 * @since 1.1.0
 */
class TranscriptRetention {

	/**
	 * Cron hook for daily retention sweep.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'wp_mcp_ai_transcript_retention_sweep';

	/**
	 * Default transcript retention in days.
	 *
	 * @var int
	 */
	const DEFAULT_RETENTION_DAYS = 90;

	/**
	 * Default guest retention in days (shorter for non-logged-in users).
	 *
	 * @var int
	 */
	const DEFAULT_GUEST_RETENTION_DAYS = 7;

	/**
	 * Default max transcripts per user.
	 *
	 * @var int
	 */
	const DEFAULT_PER_USER_MAX = 500;

	/**
	 * Safety cap on deletes per cron run.
	 *
	 * @var int
	 */
	const MAX_DELETES_PER_RUN = 1000;

	/**
	 * Initialize hooks and scheduling.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Register cron handler.
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_retention_sweep' ) );

		// Schedule on init.
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 40 );

		// Register REST endpoint for per-transcript deletion (GDPR Art 17).
		add_action( 'rest_api_init', array( __CLASS__, 'register_delete_endpoint' ) );
	}

	/**
	 * Schedule daily retention sweep if not already scheduled.
	 *
	 * @return void
	 */
	public static function maybe_schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 02:00:00' ), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Read a retention setting from the active settings store.
	 *
	 * Monolith installs delegate to the base plugin's settings registry;
	 * standalone installs read the CG-AI settings store.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Default value.
	 * @return mixed
	 */
	protected static function get_setting( $key, $default ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Settings_Registry' ) ) {
			return \WP_MCP_AI_Settings_Registry::get_setting( $key, $default );
		}

		return CoreBridge::instance()->settings->get( $key, $default );
	}

	/**
	 * Run the daily retention sweep: prune old transcripts by age and by per-user cap.
	 *
	 * @return void
	 */
	public static function run_retention_sweep(): void {
		$enabled = static::get_setting( 'transcript_retention_enabled', true );
		if ( ! $enabled ) {
			return;
		}

		$total_pruned = 0;

		// 1. Prune by age (regular users).
		$retention_days = (int) static::get_setting( 'transcript_retention_days', self::DEFAULT_RETENTION_DAYS );
		if ( $retention_days > 0 ) {
			$total_pruned += self::prune_by_age( $retention_days, false );
		}

		// 2. Prune by age (guest users, shorter retention).
		$guest_retention = (int) static::get_setting( 'transcript_guest_retention_days', self::DEFAULT_GUEST_RETENTION_DAYS );
		if ( $guest_retention > 0 ) {
			$total_pruned += self::prune_by_age( $guest_retention, true );
		}

		// 3. Enforce per-user cap.
		$per_user_max = (int) static::get_setting( 'transcript_per_user_max', self::DEFAULT_PER_USER_MAX );
		if ( $per_user_max > 0 ) {
			$total_pruned += self::prune_by_per_user_cap( $per_user_max, $total_pruned );
		}

		// 4. Log summary (monolith logger only).
		if ( $total_pruned > 0 && defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event(
				'info',
				'Transcript Retention: sweep complete',
				array(
					'total_pruned'    => $total_pruned,
					'retention_days'  => $retention_days,
					'guest_retention' => $guest_retention,
					'per_user_max'    => $per_user_max,
				)
			);
		}
	}

	/**
	 * Prune transcripts older than the given number of days.
	 *
	 * @param int  $days       Retention period in days.
	 * @param bool $guest_only If true, only prune guest (user_id=0) transcripts.
	 * @return int Number of transcripts pruned.
	 */
	private static function prune_by_age( $days, $guest_only = false ): int {
		$table = static::get_transcript_table();
		if ( ! $table ) {
			return 0;
		}

		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$limit  = min( self::MAX_DELETES_PER_RUN, 500 );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- CCT table has no WP API; low-traffic daily cron.

		if ( $guest_only ) {
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$table}` WHERE cct_created < %s AND cct_author_id = 0",
					$cutoff
				)
			);
			if ( $count <= 0 ) {
				return 0;
			}
			$limit = min( $limit, $count );
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM `{$table}` WHERE cct_created < %s AND cct_author_id = 0 LIMIT %d",
					$cutoff,
					$limit
				)
			);
		} else {
			$count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$table}` WHERE cct_created < %s AND cct_author_id > 0",
					$cutoff
				)
			);
			if ( $count <= 0 ) {
				return 0;
			}
			$limit = min( $limit, $count );
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM `{$table}` WHERE cct_created < %s AND cct_author_id > 0 LIMIT %d",
					$cutoff,
					$limit
				)
			);
		}

		// phpcs:enable

		return false !== $deleted ? (int) $deleted : 0;
	}

	/**
	 * Enforce per-user transcript cap by deleting the oldest transcripts
	 * for users who exceed the maximum.
	 *
	 * @param int $max_per_user Maximum transcripts per user.
	 * @param int $already_done Already pruned count (to respect overall cap).
	 * @return int Number pruned.
	 */
	private static function prune_by_per_user_cap( $max_per_user, $already_done = 0 ): int {
		$table = static::get_transcript_table();
		if ( ! $table ) {
			return 0;
		}

		global $wpdb;
		$remaining = self::MAX_DELETES_PER_RUN - $already_done;
		if ( $remaining <= 0 ) {
			return 0;
		}

		// Find users who exceed the cap.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$over_limit = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT cct_author_id, COUNT(*) AS cnt FROM `{$table}` WHERE cct_author_id > 0 GROUP BY cct_author_id HAVING cnt > %d ORDER BY cnt DESC LIMIT 50",
				$max_per_user
			)
		);
		// phpcs:enable

		if ( empty( $over_limit ) ) {
			return 0;
		}

		$total_pruned = 0;

		foreach ( $over_limit as $row ) {
			if ( $total_pruned >= $remaining ) {
				break;
			}

			$user_id      = (int) $row->cct_author_id;
			$excess_count = (int) $row->cnt - $max_per_user;
			$to_delete    = min( $excess_count, $remaining - $total_pruned, 100 );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM `{$table}` WHERE cct_author_id = %d ORDER BY cct_created ASC LIMIT %d",
					$user_id,
					$to_delete
				)
			);
			// phpcs:enable

			if ( false !== $deleted ) {
				$total_pruned += (int) $deleted;
			}
		}

		return $total_pruned;
	}

	/**
	 * Get the transcript CCT table name.
	 *
	 * Monolith installs delegate to the base plugin's transcript
	 * repository; standalone installs have no transcript storage yet and
	 * return an empty string (graceful no-op).
	 *
	 * @return string Table name or empty string if unavailable.
	 */
	protected static function get_transcript_table(): string {
		if ( function_exists( 'wp_mcp_ai_get_transcript_repository' ) ) {
			$repository = wp_mcp_ai_get_transcript_repository();
			if ( is_object( $repository ) && is_a( $repository, 'WP_MCP_AI_Transcript_Repository' ) && method_exists( $repository, 'get_table_name' ) ) {
				return (string) $repository->get_table_name();
			}
		}

		return '';
	}

	/**
	 * Register REST endpoint for per-transcript deletion (GDPR right to erasure).
	 *
	 * @return void
	 */
	public static function register_delete_endpoint(): void {
		register_rest_route(
			'mcp-ai/v1',
			'/chat-transcripts/(?P<transcript_id>\\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( __CLASS__, 'handle_delete_transcript' ),
				'permission_callback' => function () {
					return is_user_logged_in();
				},
				'args'                => array(
					'transcript_id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Handle REST DELETE request for a single transcript.
	 *
	 * @param \WP_REST_Request $request REST request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle_delete_transcript( $request ) {
		$transcript_id = (int) $request->get_param( 'transcript_id' );
		$user_id       = get_current_user_id();

		$table = static::get_transcript_table();
		if ( ! $table ) {
			return new \WP_Error( 'no_storage', __( 'Transcript storage is not available.', 'nvoos-content-graph-ai' ), array( 'status' => 500 ) );
		}

		global $wpdb;

		// Verify the transcript belongs to this user.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$owner = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT cct_author_id FROM `{$table}` WHERE _ID = %d",
				$transcript_id
			)
		);
		// phpcs:enable

		if ( ! $owner || ( $owner !== $user_id && ! current_user_can( 'manage_options' ) ) ) {
			return new \WP_Error( 'forbidden', __( 'You do not have permission to delete this transcript.', 'nvoos-content-graph-ai' ), array( 'status' => 403 ) );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->delete( $table, array( '_ID' => $transcript_id ), array( '%d' ) );
		// phpcs:enable

		if ( false === $deleted ) {
			return new \WP_Error( 'delete_failed', __( 'Failed to delete transcript.', 'nvoos-content-graph-ai' ), array( 'status' => 500 ) );
		}

		return new \WP_REST_Response(
			array(
				'success'       => true,
				'transcript_id' => $transcript_id,
				'message'       => __( 'Transcript deleted successfully.', 'nvoos-content-graph-ai' ),
			),
			200
		);
	}

	/**
	 * Get retention statistics for admin display.
	 *
	 * @return array Stats including total transcripts, oldest, newest, by user type.
	 */
	public static function get_retention_stats(): array {
		$table = static::get_transcript_table();
		if ( ! $table ) {
			return array( 'available' => false );
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$guest_total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE cct_author_id = 0" );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$oldest = $wpdb->get_var( "SELECT cct_created FROM `{$table}` ORDER BY cct_created ASC LIMIT 1" );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$user_count = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT cct_author_id) FROM `{$table}` WHERE cct_author_id > 0" );
		// phpcs:enable

		$retention_days = (int) static::get_setting( 'transcript_retention_days', self::DEFAULT_RETENTION_DAYS );

		return array(
			'available'         => true,
			'total'             => $total,
			'guest_total'       => $guest_total,
			'user_total'        => $total - $guest_total,
			'unique_users'      => $user_count,
			'oldest_date'       => $oldest,
			'retention_days'    => $retention_days,
			'retention_enabled' => (bool) static::get_setting( 'transcript_retention_enabled', true ),
			'per_user_max'      => (int) static::get_setting( 'transcript_per_user_max', self::DEFAULT_PER_USER_MAX ),
		);
	}
}
