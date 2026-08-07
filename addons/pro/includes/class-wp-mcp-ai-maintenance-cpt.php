<?php
/**
 * Maintenance Window Custom Post Type
 *
 * Registers the `mcp_ai_maintenance` CPT for scheduled maintenance windows.
 * Each post represents a planned maintenance window with start/end times,
 * affected services, notification channels, and banner display settings.
 *
 * Status transitions are driven by the consolidated
 * `wp_mcp_ai_five_minute_tick` cron job (every 5 minutes) which calls
 * {@see WP_MCP_AI_Maintenance_CPT::process_transitions()} and
 * {@see WP_MCP_AI_Maintenance_CPT::process_reminders()} within a single
 * PHP process.
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Maintenance_CPT' ) ) {
	/**
	 * Maintenance Window CPT class.
	 *
	 * @since 1.3.0
	 */
	class WP_MCP_AI_Maintenance_CPT {

		/**
		 * Post type slug.
		 *
		 * @since 1.3.0
		 * @var string
		 */
		const POST_TYPE = 'mcp_ai_maintenance';

		/**
		 * Status constants.
		 *
		 * @since 1.3.0
		 * @var string
		 */
		const STATUS_SCHEDULED   = 'scheduled';
		const STATUS_IN_PROGRESS = 'in_progress';
		const STATUS_COMPLETED   = 'completed';
		const STATUS_CANCELLED   = 'cancelled';

		/**
		 * Monitor cron hook name.
		 *
		 * @since 1.3.0
		 * @var string
		 */
		const MONITOR_HOOK = 'wp_mcp_ai_maintenance_monitor_cron';

		/**
		 * Reminder cron hook name.
		 *
		 * @since 1.3.0
		 * @var string
		 */
		const REMINDER_HOOK = 'wp_mcp_ai_maintenance_reminder_cron';

		/**
		 * Valid status transitions.
		 *
		 * @since 1.3.0
		 * @var array<string, string[]>
		 */
		const VALID_TRANSITIONS = array(
			self::STATUS_SCHEDULED   => array( self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED ),
			self::STATUS_IN_PROGRESS => array( self::STATUS_COMPLETED, self::STATUS_CANCELLED ),
			self::STATUS_COMPLETED   => array(),
			self::STATUS_CANCELLED   => array(),
		);

		/**
		 * Register the CPT and meta fields.
		 *
		 * @since 1.3.0
		 *
		 * @return void
		 */
		public static function init(): void {
			add_action( 'init', array( __CLASS__, 'register_post_type' ) );
			add_action( 'init', array( __CLASS__, 'register_meta' ) );

			// Cron callbacks are now dispatched from the consolidated
			// wp_mcp_ai_five_minute_tick handler (includes/bootstrap/cron.php)
			// to reduce per-cycle PHP processes and MySQL connections.

			// Fire action hooks on status transitions.
			add_action( 'post_updated', array( __CLASS__, 'on_post_updated' ), 10, 3 );
		}

		/**
		 * Register the custom post type.
		 *
		 * @since 1.3.0
		 *
		 * @return void
		 */
		public static function register_post_type(): void {
			$labels = array(
				'name'               => __( 'Maintenance Windows', 'mcp-ai-wpoos-pro' ),
				'singular_name'      => __( 'Maintenance Window', 'mcp-ai-wpoos-pro' ),
				'add_new'            => __( 'Add New', 'mcp-ai-wpoos-pro' ),
				'add_new_item'       => __( 'Add New Maintenance Window', 'mcp-ai-wpoos-pro' ),
				'edit_item'          => __( 'Edit Maintenance Window', 'mcp-ai-wpoos-pro' ),
				'new_item'           => __( 'New Maintenance Window', 'mcp-ai-wpoos-pro' ),
				'view_item'          => __( 'View Maintenance Window', 'mcp-ai-wpoos-pro' ),
				'search_items'       => __( 'Search Maintenance Windows', 'mcp-ai-wpoos-pro' ),
				'not_found'          => __( 'No maintenance windows found.', 'mcp-ai-wpoos-pro' ),
				'not_found_in_trash' => __( 'No maintenance windows found in Trash.', 'mcp-ai-wpoos-pro' ),
				'all_items'          => __( 'All Maintenance Windows', 'mcp-ai-wpoos-pro' ),
			);

			$args = array(
				'labels'          => $labels,
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => false,
				'show_in_rest'    => true,
				'rest_base'       => 'mcp-ai-maintenance',
				'supports'        => array( 'title', 'editor' ),
				'capability_type' => 'post',
				'capabilities'    => array(
					'create_posts' => 'manage_options',
				),
				'map_meta_cap'    => true,
				'has_archive'     => false,
				'rewrite'         => false,
				'query_var'       => false,
			);

			register_post_type( self::POST_TYPE, $args );
		}

		/**
		 * Register post meta fields.
		 *
		 * @since 1.3.0
		 *
		 * @return void
		 */
		public static function register_meta(): void {
			$meta_fields = array(
				'_mcp_ai_maintenance_status'          => array(
					'type'        => 'string',
					'description' => __( 'Current status of the maintenance window.', 'mcp-ai-wpoos-pro' ),
					'default'     => self::STATUS_SCHEDULED,
				),
				'_mcp_ai_maintenance_start'           => array(
					'type'        => 'string',
					'description' => __( 'Scheduled start time (ISO 8601).', 'mcp-ai-wpoos-pro' ),
				),
				'_mcp_ai_maintenance_end'             => array(
					'type'        => 'string',
					'description' => __( 'Scheduled end time (ISO 8601).', 'mcp-ai-wpoos-pro' ),
				),
				'_mcp_ai_maintenance_services'        => array(
					'type'         => 'array',
					'description'  => __( 'Affected service component slugs.', 'mcp-ai-wpoos-pro' ),
					'show_in_rest' => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
				'_mcp_ai_maintenance_notify_channels' => array(
					'type'         => 'array',
					'description'  => __( 'Notification channel identifiers.', 'mcp-ai-wpoos-pro' ),
					'show_in_rest' => array(
						'schema' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
					),
				),
				'_mcp_ai_maintenance_notify_before'   => array(
					'type'        => 'integer',
					'description' => __( 'Minutes before start to send reminder.', 'mcp-ai-wpoos-pro' ),
					'default'     => 60,
				),
				'_mcp_ai_maintenance_banner_enabled'  => array(
					'type'        => 'boolean',
					'description' => __( 'Show frontend banner during this window.', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'_mcp_ai_maintenance_reminder_sent'   => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the pre-maintenance reminder has been sent.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			);

			foreach ( $meta_fields as $key => $args ) {
				$defaults = array(
					'single'       => true,
					'show_in_rest' => true,
				);

				$args = array_merge( $defaults, $args );

				register_post_meta( self::POST_TYPE, $key, $args );
			}
		}

		/**
		 * Process scheduled → in_progress and in_progress → completed transitions.
		 *
		 * Called from wp_mcp_ai_five_minute_tick (every 5 minutes).
		 *
		 * @since 1.3.0
		 *
		 * @return void
		 */
		public static function process_transitions(): void {
			$now = time();

			// Transition scheduled → in_progress.
			$scheduled = self::get_windows_by_status( self::STATUS_SCHEDULED );
			foreach ( $scheduled as $post ) {
				$start = self::get_meta_timestamp( $post->ID, '_mcp_ai_maintenance_start' );
				if ( $start && $now >= $start ) {
					self::transition_status( $post->ID, self::STATUS_IN_PROGRESS );
				}
			}

			// Transition in_progress → completed.
			$in_progress = self::get_windows_by_status( self::STATUS_IN_PROGRESS );
			foreach ( $in_progress as $post ) {
				$end = self::get_meta_timestamp( $post->ID, '_mcp_ai_maintenance_end' );
				if ( $end && $now >= $end ) {
					self::transition_status( $post->ID, self::STATUS_COMPLETED );
				}
			}
		}

		/**
		 * Process pre-maintenance reminders.
		 *
		 * Called from wp_mcp_ai_five_minute_tick (every 5 minutes).
		 *
		 * @since 1.3.0
		 *
		 * @return void
		 */
		public static function process_reminders(): void {
			$scheduled = self::get_windows_by_status( self::STATUS_SCHEDULED );
			$now       = time();

			foreach ( $scheduled as $post ) {
				$reminder_sent = (bool) get_post_meta( $post->ID, '_mcp_ai_maintenance_reminder_sent', true );
				if ( $reminder_sent ) {
					continue;
				}

				$start         = self::get_meta_timestamp( $post->ID, '_mcp_ai_maintenance_start' );
				$notify_before = (int) get_post_meta( $post->ID, '_mcp_ai_maintenance_notify_before', true );
				if ( $notify_before <= 0 ) {
					$notify_before = 60;
				}

				$reminder_time = $start - ( $notify_before * MINUTE_IN_SECONDS );

				if ( $start && $now >= $reminder_time ) {
					/**
					 * Fires when a pre-maintenance reminder should be sent.
					 *
					 * @since 1.3.0
					 *
					 * @param int $post_id        Maintenance window post ID.
					 * @param int $minutes_until  Minutes until the window starts.
					 */
					do_action( 'wp_mcp_ai_maintenance_reminder', $post->ID, $notify_before );
					update_post_meta( $post->ID, '_mcp_ai_maintenance_reminder_sent', true );
				}
			}
		}

		/**
		 * Transition a maintenance window to a new status.
		 *
		 * Validates the transition and fires the appropriate action hooks.
		 *
		 * @since 1.3.0
		 *
		 * @param int    $post_id    Post ID.
		 * @param string $new_status Target status.
		 * @return bool True on success, false on invalid transition.
		 */
		public static function transition_status( int $post_id, string $new_status ): bool {
			$old_status = (string) get_post_meta( $post_id, '_mcp_ai_maintenance_status', true );

			if ( '' === $old_status ) {
				$old_status = self::STATUS_SCHEDULED;
			}

			if ( $old_status === $new_status ) {
				return true;
			}

			// Validate transition.
			$allowed = self::VALID_TRANSITIONS[ $old_status ] ?? array();
			if ( ! in_array( $new_status, $allowed, true ) ) {
				return false;
			}

			update_post_meta( $post_id, '_mcp_ai_maintenance_status', $new_status );

			/**
			 * Fires when a maintenance window status changes.
			 *
			 * @since 1.3.0
			 *
			 * @param int    $post_id    Maintenance window post ID.
			 * @param string $old_status Previous status.
			 * @param string $new_status New status.
			 */
			do_action( 'wp_mcp_ai_maintenance_status_changed', $post_id, $old_status, $new_status );

			// Fire specific hooks.
			if ( self::STATUS_IN_PROGRESS === $new_status ) {
				/** Fires when a maintenance window transitions to in-progress. @since 1.3.0 */
				do_action( 'wp_mcp_ai_maintenance_started', $post_id );
			} elseif ( self::STATUS_COMPLETED === $new_status ) {
				/** Fires when a maintenance window transitions to completed. @since 1.3.0 */
				do_action( 'wp_mcp_ai_maintenance_completed', $post_id );
			} elseif ( self::STATUS_CANCELLED === $new_status ) {
				/** Fires when a maintenance window transitions to cancelled. @since 1.3.0 */
				do_action( 'wp_mcp_ai_maintenance_cancelled', $post_id );
			}

			return true;
		}

		/**
		 * Detect manual status changes via post edit and fire hooks.
		 *
		 * @since 1.3.0
		 *
		 * @param int     $post_id     Post ID.
		 * @param WP_Post $post_after  Post object after update.
		 * @param WP_Post $post_before Post object before update.
		 * @return void
		 */
		public static function on_post_updated( int $post_id, WP_Post $post_after, WP_Post $post_before ): void {
			if ( self::POST_TYPE !== $post_after->post_type ) {
				return;
			}

			// In case status is stored in post_status.
			$old_status = $post_before->post_status;
			$new_status = $post_after->post_status;

			if ( $old_status !== $new_status ) {
				/**
				 * Fires when a maintenance window status changes.
				 *
				 * @since 1.3.0
				 *
				 * @param int    $post_id    Maintenance window post ID.
				 * @param string $old_status Previous status.
				 * @param string $new_status New status.
				 */
				do_action( 'wp_mcp_ai_maintenance_status_changed', $post_id, $old_status, $new_status );
			}
		}

		/**
		 * Get all maintenance windows with a given status.
		 *
		 * @since 1.3.0
		 *
		 * @param string $status Status slug.
		 * @param int    $limit  Maximum number of posts to return (default 50).
		 * @return WP_Post[]
		 */
		private static function get_windows_by_status( string $status, int $limit = 50 ): array {
			return get_posts(
				array(
					'post_type'      => self::POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => $limit,
					'meta_key'       => '_mcp_ai_maintenance_status',
					'meta_value'     => $status,
					'orderby'        => 'date',
					'order'          => 'ASC',
					'no_found_rows'  => true,
				)
			);
		}

		/**
		 * Get a Unix timestamp from a post meta field stored as ISO 8601.
		 *
		 * @since 1.3.0
		 *
		 * @param int    $post_id  Post ID.
		 * @param string $meta_key Meta key.
		 * @return int Unix timestamp, or 0 if invalid/missing.
		 */
		private static function get_meta_timestamp( int $post_id, string $meta_key ): int {
			$value = get_post_meta( $post_id, $meta_key, true );
			if ( empty( $value ) || ! is_string( $value ) ) {
				return 0;
			}

			$timestamp = strtotime( $value );
			return false === $timestamp ? 0 : $timestamp;
		}

		/**
		 * Get the currently active (in-progress) maintenance window, if any.
		 *
		 * @since 1.3.0
		 *
		 * @return WP_Post|null
		 */
		public static function get_active_window(): ?WP_Post {
			$windows = self::get_windows_by_status( self::STATUS_IN_PROGRESS, 1 );
			return ! empty( $windows ) ? $windows[0] : null;
		}

		/**
		 * Get upcoming (scheduled) maintenance windows.
		 *
		 * @since 1.3.0
		 *
		 * @param int $limit Maximum number to return.
		 * @return WP_Post[]
		 */
		public static function get_upcoming_windows( int $limit = 5 ): array {
			return self::get_windows_by_status( self::STATUS_SCHEDULED, $limit );
		}
	}

	// Bootstrap.
	WP_MCP_AI_Maintenance_CPT::init();
}
