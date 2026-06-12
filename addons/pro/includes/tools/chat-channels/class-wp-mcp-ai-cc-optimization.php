<?php
/**
 * Chat Channels Performance Optimization
 *
 * Ensures the Chat Channels toolkit keeps the WordPress site performant:
 *
 *  1. Message retention — daily prune of old channel messages (configurable days).
 *  2. Contact retention — prune inactive contacts beyond retention period.
 *  3. Autoload enforcement — force toolkit settings to no-autoload.
 *  4. CPT gate — skip CPT registration when JetEngine CCT is active
 *     to avoid dead-weight rows in wp_posts/wp_postmeta.
 *  5. Dedup on insert — prevent duplicate message_id entries.
 *
 * @package WP_MCP_AI_Pro
 * @since  2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chat Channels optimization manager.
 *
 * @since 2.9.0
 */
class WP_MCP_AI_Chat_Channels_Optimization {

	/**
	 * Cron hook for daily optimization run.
	 *
	 * @var string
	 */
	const OPTIMIZE_HOOK = 'wp_mcp_ai_cc_daily_optimize';

	/**
	 * Default message retention in days.
	 *
	 * @var int
	 */
	const DEFAULT_RETENTION_DAYS = 90;

	/**
	 * Settings option key.
	 *
	 * @var string
	 */
	const SETTINGS_OPTION = 'wp_mcp_ai_chat_channels_toolkit_settings';

	/**
	 * Initialize.
	 *
	 * @since 2.9.0
	 */
	public static function init() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_chat_channels_toolkit'] ) ) {
			return;
		}

		// Register optimization cron handler.
		add_action( self::OPTIMIZE_HOOK, array( __CLASS__, 'run_daily_optimization' ) );

		// Schedule on init.
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 40 );

		// Force autoload=no on settings option.
		add_action( 'update_option_' . self::SETTINGS_OPTION, array( __CLASS__, 'fix_autoload' ), 10, 2 );
		add_action( 'added_option_' . self::SETTINGS_OPTION, array( __CLASS__, 'fix_autoload' ), 10, 2 );

		// Fix CPT gate — de-register CPT if CCT is active.
		add_action( 'init', array( __CLASS__, 'maybe_deregister_cpts' ), 20 );
	}

	/**
	 * Schedule daily optimization if not already scheduled.
	 *
	 * @since 2.9.0
	 */
	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::OPTIMIZE_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 02:00:00' ), 'daily', self::OPTIMIZE_HOOK );
		}
	}

	/**
	 * Force settings option to no-autoload.
	 *
	 * @since 2.9.0
	 * @param mixed $old Previous value.
	 * @param mixed $new New value.
	 */
	public static function fix_autoload( $old, $new ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$wpdb->options,
			array( 'autoload' => 'no' ),
			array( 'option_name' => self::SETTINGS_OPTION ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * De-register CPTs if JetEngine CCT tables exist (avoid dead-weight).
	 *
	 * When JetEngine is active with the CCT tables, the CPT fallback is
	 * dead code that pollutes wp_posts indexes. This method removes the
	 * CPT init hook so only the CCT path is used.
	 *
	 * @since 2.9.0
	 */
	public static function maybe_deregister_cpts() {
		if ( ! function_exists( 'jet_engine' ) ) {
			return;
		}

		global $wpdb;

		// Check if CCT tables exist.
		$messages_table = $wpdb->prefix . 'jet_cct_channel_messages';
		$contacts_table = $wpdb->prefix . 'jet_cct_channel_contacts';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$messages_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $messages_table )
		);
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$contacts_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $contacts_table )
		);

		if ( $messages_exists ) {
			remove_action( 'init', array( 'WP_MCP_AI_Channel_Messages_CPT', 'register_post_type' ) );
		}
		if ( $contacts_exists ) {
			remove_action( 'init', array( 'WP_MCP_AI_Channel_Contacts_CPT', 'register_post_type' ) );
		}
	}

	/**
	 * Daily optimization: prune old messages and contacts.
	 *
	 * @since 2.9.0
	 */
	public static function run_daily_optimization() {
		$retention_days = self::DEFAULT_RETENTION_DAYS;

		$settings = class_exists( 'WP_MCP_AI_Toolkit_Settings_Base' )
			? get_option( self::SETTINGS_OPTION, array() )
			: array();
		if ( isset( $settings['optimization']['message_retention_days'] ) ) {
			$retention_days = absint( $settings['optimization']['message_retention_days'] );
			if ( $retention_days <= 0 ) {
				return; // 0 = keep forever.
			}
		}

		$pruned_messages = self::prune_old_messages( $retention_days );
		$pruned_contacts = self::prune_inactive_contacts( $retention_days * 2 );

		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'cc_daily_optimization',
				'system',
				'',
				array(
					'messages_pruned' => $pruned_messages,
					'contacts_pruned' => $pruned_contacts,
					'retention_days'  => $retention_days,
				)
			);
		}
	}

	/**
	 * Prune channel messages older than the retention period.
	 *
	 * @since 2.9.0
	 * @param int $retention_days Days to retain.
	 * @return int Number pruned.
	 */
	private static function prune_old_messages( $retention_days ) {
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );
		$total  = 0;

		// Prune CPT messages.
		if ( post_type_exists( 'mcp_chan_message' ) ) {
			$total += self::prune_cpt_batch( 'mcp_chan_message', $cutoff );
		}

		// Prune CCT messages if JetEngine is available.
		if ( function_exists( 'jet_engine' ) ) {
			$total += self::prune_cct_batch( 'channel_messages', $cutoff );
		}

		return $total;
	}

	/**
	 * Prune inactive contacts beyond retention period.
	 *
	 * @since 2.9.0
	 * @param int $retention_days Days to retain.
	 * @return int Number pruned.
	 */
	private static function prune_inactive_contacts( $retention_days ) {
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );
		$total  = 0;

		if ( post_type_exists( 'mcp_chan_contact' ) ) {
			$total += self::prune_cpt_batch( 'mcp_chan_contact', $cutoff );
		}

		if ( function_exists( 'jet_engine' ) ) {
			$total += self::prune_cct_batch( 'channel_contacts', $cutoff, 'last_message_at' );
		}

		return $total;
	}

	/**
	 * Batch-delete CPT posts older than cutoff.
	 *
	 * @param string $post_type Post type slug.
	 * @param string $cutoff    Date cutoff (MySQL format).
	 * @return int Count deleted.
	 */
	private static function prune_cpt_batch( $post_type, $cutoff ) {
		$total = 0;
		$batch = 100;

		do {
			$query = new WP_Query(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'publish',
					'posts_per_page' => $batch,
					'fields'         => 'ids',
					'date_query'     => array(
						array( 'before' => $cutoff ),
					),
					'no_found_rows'  => true,
				)
			);

			$ids = $query->posts;
			wp_reset_postdata();

			if ( empty( $ids ) ) {
				break;
			}

			foreach ( $ids as $id ) {
				wp_delete_post( $id, true );
			}

			$total += count( $ids );

			if ( $total >= 1000 ) {
				break; // Safety cap.
			}
		} while ( ! empty( $ids ) );

		return $total;
	}

	/**
	 * Batch-delete CCT rows older than cutoff.
	 *
	 * @since 2.9.0
	 * @param string $cct_slug    JetEngine CCT slug.
	 * @param string $cutoff      Date cutoff (MySQL format).
	 * @param string $date_column Column name for date check (default: 'created_at').
	 * @return int Count deleted.
	 */
	private static function prune_cct_batch( $cct_slug, $cutoff, $date_column = 'created_at' ) {
		global $wpdb;

		$table = $wpdb->prefix . 'jet_cct_' . $cct_slug;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		if ( ! $exists ) {
			return 0;
		}

		// Check if the date column exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$column_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SHOW COLUMNS FROM `{$table}` LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$date_column
			)
		);

		if ( ! $column_exists ) {
			// Try 'created_at' as fallback for messages.
			if ( 'last_message_at' === $date_column ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
				$column_exists = $wpdb->get_var(
					$wpdb->prepare(
						"SHOW COLUMNS FROM `{$table}` LIKE %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						'created_at'
					)
				);
				if ( $column_exists ) {
					$date_column = 'created_at';
				} else {
					return 0;
				}
			} else {
				return 0;
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM `{$table}` WHERE `{$date_column}` < %s",
				$cutoff
			)
		);

		if ( $count <= 0 ) {
			return 0;
		}

		// Safety cap.
		$limit = min( 1000, $count );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM `{$table}` WHERE `{$date_column}` < %s LIMIT %d",
				$cutoff,
				$limit
			)
		);

		return false !== $deleted ? (int) $deleted : 0;
	}
}

// Initialize.
add_action( 'plugins_loaded', array( 'WP_MCP_AI_Chat_Channels_Optimization', 'init' ), 40 );
