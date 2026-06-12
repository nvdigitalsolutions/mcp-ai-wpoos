<?php
/**
 * Chat Transcript Retention Manager
 *
 * Provides automated retention policies for stored chat transcripts with
 * user-facing transparency and GDPR-compliant data lifecycle management.
 *
 * Features:
 *  - Daily cron prunes transcripts older than configurable retention period
 *  - Per-user cap enforcement (oldest transcripts pruned first)
 *  - Separate shorter retention for guest (non-logged-in) transcripts
 *  - Safety-capped batch deletes to avoid long-running queries
 *  - User-accessible transcript deletion via REST endpoint
 *  - Retention statistics for admin dashboard
 *
 * Industry references:
 *  - GDPR Art 5(1)(c) — data minimisation: only keep what's needed
 *  - GDPR Art 17 — right to erasure: users must be able to delete their data
 *  - ChatNexus retention policies: 30-365 days for conversation messages
 *  - Algolia privacy-first AI: separate operational data from user data
 *
 * @package WP_MCP_AI
 * @since  2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Transcript retention manager.
 *
 * @since 2.9.0
 */
class WP_MCP_AI_Transcript_Retention {

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
	 * @since 2.9.0
	 */
	public static function init() {
		// Register cron handler.
		add_action( self::CRON_HOOK, array( __CLASS__, 'run_retention_sweep' ) );

		// Schedule on init.
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 40 );

		// Register REST endpoint for per-transcript deletion (GDPR Art 17).
		add_action( 'rest_api_init', array( __CLASS__, 'register_delete_endpoint' ) );

		// Register settings in the Orchestration section.
		add_filter( 'wp_mcp_ai_default_settings', array( __CLASS__, 'add_default_settings' ) );
	}

	/**
	 * Schedule daily retention sweep if not already scheduled.
	 *
	 * @since 2.9.0
	 */
	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 02:00:00' ), 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Add default settings for transcript retention.
	 *
	 * @since 2.9.0
	 * @param array $defaults Existing default settings.
	 * @return array
	 */
	public static function add_default_settings( $defaults ) {
		$defaults['transcript_retention_days']        = self::DEFAULT_RETENTION_DAYS;
		$defaults['transcript_retention_enabled']     = true;
		$defaults['transcript_guest_retention_days']  = self::DEFAULT_GUEST_RETENTION_DAYS;
		$defaults['transcript_per_user_max']          = self::DEFAULT_PER_USER_MAX;
		return $defaults;
	}

	/**
	 * Run the daily retention sweep: prune old transcripts by age and by per-user cap.
	 *
	 * @since 2.9.0
	 */
	public static function run_retention_sweep() {
		$enabled = WP_MCP_AI_Settings_Registry::get_setting( 'transcript_retention_enabled', true );
		if ( ! $enabled ) {
			return;
		}

		$total_pruned = 0;

		// 1. Prune by age (regular users).
		$retention_days = (int) WP_MCP_AI_Settings_Registry::get_setting( 'transcript_retention_days', self::DEFAULT_RETENTION_DAYS );
		if ( $retention_days > 0 ) {
			$total_pruned += self::prune_by_age( $retention_days, false );
		}

		// 2. Prune by age (guest users, shorter retention).
		$guest_retention = (int) WP_MCP_AI_Settings_Registry::get_setting( 'transcript_guest_retention_days', self::DEFAULT_GUEST_RETENTION_DAYS );
		if ( $guest_retention > 0 ) {
			$total_pruned += self::prune_by_age( $guest_retention, true );
		}

		// 3. Enforce per-user cap.
		$per_user_max = (int) WP_MCP_AI_Settings_Registry::get_setting( 'transcript_per_user_max', self::DEFAULT_PER_USER_MAX );
		if ( $per_user_max > 0 ) {
			$total_pruned += self::prune_by_per_user_cap( $per_user_max, $total_pruned );
		}

		// 4. Log summary.
		if ( $total_pruned > 0 && function_exists( 'WP_MCP_AI_Logger' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_event(
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
	 * @since 2.9.0
	 * @param int  $days       Retention period in days.
	 * @param bool $guest_only If true, only prune guest (user_id=0) transcripts.
	 * @return int Number of transcripts pruned.
	 */
	private static function prune_by_age( $days, $guest_only = false ) {
		$table = self::get_transcript_table();
		if ( ! $table ) {
			return 0;
		}

		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );
		$limit  = min( self::MAX_DELETES_PER_RUN, 500 );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery -- CCT table has no WP API; low-traffic daily cron.

		if ( $guest_only ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name is validated via get_transcript_table().
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
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$deleted = $wpdb->query(
				$wpdb->prepare(
					"DELETE FROM `{$table}` WHERE cct_created < %s AND cct_author_id = 0 LIMIT %d",
					$cutoff,
					$limit
				)
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 * @since 2.9.0
	 * @param int $max_per_user  Maximum transcripts per user.
	 * @param int $already_done  Already pruned count (to respect overall cap).
	 * @return int Number pruned.
	 */
	private static function prune_by_per_user_cap( $max_per_user, $already_done = 0 ) {
		$table = self::get_transcript_table();
		if ( ! $table ) {
			return 0;
		}

		global $wpdb;
		$remaining = self::MAX_DELETES_PER_RUN - $already_done;
		if ( $remaining <= 0 ) {
			return 0;
		}

		// Find users who exceed the cap.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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

			$user_id       = (int) $row->cct_author_id;
			$excess_count  = (int) $row->cnt - $max_per_user;
			$to_delete     = min( $excess_count, $remaining - $total_pruned, 100 );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 * @since 2.9.0
	 * @return string Table name or empty string if unavailable.
	 */
	private static function get_transcript_table() {
		if ( ! class_exists( 'WP_MCP_AI_Transcript_Repository' ) ) {
			return '';
		}

		$repository = wp_mcp_ai_get_transcript_repository();
		if ( ! $repository instanceof WP_MCP_AI_Transcript_Repository ) {
			return '';
		}

		return $repository->get_table_name();
	}

	/**
	 * Register REST endpoint for per-transcript deletion (GDPR right to erasure).
	 *
	 * @since 2.9.0
	 */
	public static function register_delete_endpoint() {
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
	 * @since 2.9.0
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_delete_transcript( $request ) {
		$transcript_id = (int) $request->get_param( 'transcript_id' );
		$user_id       = get_current_user_id();

		$table = self::get_transcript_table();
		if ( ! $table ) {
			return new WP_Error( 'no_storage', __( 'Transcript storage is not available.', 'mcp-ai-wpoos' ), array( 'status' => 500 ) );
		}

		global $wpdb;

		// Verify the transcript belongs to this user.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$owner = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT cct_author_id FROM `{$table}` WHERE _ID = %d",
				$transcript_id
			)
		);
		// phpcs:enable

		if ( ! $owner || ( $owner !== $user_id && ! current_user_can( 'manage_options' ) ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to delete this transcript.', 'mcp-ai-wpoos' ), array( 'status' => 403 ) );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->delete( $table, array( '_ID' => $transcript_id ), array( '%d' ) );
		// phpcs:enable

		if ( false === $deleted ) {
			return new WP_Error( 'delete_failed', __( 'Failed to delete transcript.', 'mcp-ai-wpoos' ), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'success'        => true,
				'transcript_id'  => $transcript_id,
				'message'        => __( 'Transcript deleted successfully.', 'mcp-ai-wpoos' ),
			),
			200
		);
	}

	/**
	 * Get retention statistics for admin display.
	 *
	 * @since 2.9.0
	 * @return array Stats including total transcripts, oldest, newest, by user type.
	 */
	public static function get_retention_stats() {
		$table = self::get_transcript_table();
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

		$retention_days = (int) WP_MCP_AI_Settings_Registry::get_setting( 'transcript_retention_days', self::DEFAULT_RETENTION_DAYS );

		return array(
			'available'        => true,
			'total'             => $total,
			'guest_total'       => $guest_total,
			'user_total'        => $total - $guest_total,
			'unique_users'      => $user_count,
			'oldest_date'       => $oldest,
			'retention_days'    => $retention_days,
			'retention_enabled' => (bool) WP_MCP_AI_Settings_Registry::get_setting( 'transcript_retention_enabled', true ),
			'per_user_max'      => (int) WP_MCP_AI_Settings_Registry::get_setting( 'transcript_per_user_max', self::DEFAULT_PER_USER_MAX ),
		);
	}
}

// Initialize.
add_action( 'plugins_loaded', array( 'WP_MCP_AI_Transcript_Retention', 'init' ), 30 );
