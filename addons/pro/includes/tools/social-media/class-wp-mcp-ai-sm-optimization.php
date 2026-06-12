<?php
/**
 * Social Media Performance Optimization
 *
 * Ensures the Social Media toolkit keeps the WordPress site performant:
 *
 *  1. CPT registration fix — registers the missing social_scheduled_post
 *     CPT that schedule and bulk-schedule tools write to.
 *  2. Cron handler wire — registers the missing wp_mcp_ai_publish_scheduled_post
 *     cron handler so scheduled posts actually transition to publish.
 *  3. Scheduled post retention — daily prune of old/failed scheduled posts.
 *  4. Autorespond templates fix — non-autoloaded + max cap + delete function.
 *
 * @package WP_MCP_AI_Pro
 * @since  2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Social Media optimization manager.
 *
 * @since 2.9.0
 */
class WP_MCP_AI_Social_Media_Optimization {

	/**
	 * CPT slug for scheduled posts.
	 *
	 * @var string
	 */
	const SCHEDULED_POST_TYPE = 'social_scheduled_post';

	/**
	 * Cron hook for daily cleanup.
	 *
	 * @var string
	 */
	const CLEANUP_HOOK = 'wp_mcp_ai_sm_daily_cleanup';

	/**
	 * Option key for autorespond templates.
	 *
	 * @var string
	 */
	const TEMPLATES_OPTION = 'wp_mcp_ai_autorespond_templates';

	/**
	 * Max autorespond templates.
	 *
	 * @var int
	 */
	const MAX_TEMPLATES = 50;

	/**
	 * Default retention for old scheduled posts in days.
	 *
	 * @var int
	 */
	const DEFAULT_RETENTION_DAYS = 30;

	/**
	 * Initialize.
	 *
	 * @since 2.9.0
	 */
	public static function init() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_social_media_toolkit'] ) ) {
			return;
		}

		// Register the missing CPT.
		add_action( 'init', array( __CLASS__, 'register_scheduled_post_cpt' ), 11 );

		// Wire the missing cron handler.
		add_action( 'wp_mcp_ai_publish_scheduled_post', array( __CLASS__, 'handle_publish_scheduled_post' ) );

		// Daily cleanup of old scheduled posts.
		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'run_daily_cleanup' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 40 );

		// Fix autorespond templates — force non-autoload.
		add_action( 'update_option_' . self::TEMPLATES_OPTION, array( __CLASS__, 'fix_templates_autoload' ), 10, 2 );
		add_action( 'added_option_' . self::TEMPLATES_OPTION, array( __CLASS__, 'fix_templates_autoload' ), 10, 2 );
	}

	/**
	 * Schedule daily cleanup if not already scheduled.
	 *
	 * @since 2.9.0
	 */
	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 03:00:00' ), 'daily', self::CLEANUP_HOOK );
		}
	}

	/**
	 * Register the social_scheduled_post custom post type.
	 *
	 * This CPT was used by schedule_social_post and bulk_schedule_posts
	 * tools but never registered — posts would silently fail or become
	 * orphaned with no post type.
	 *
	 * @since 2.9.0
	 */
	public static function register_scheduled_post_cpt() {
		if ( post_type_exists( self::SCHEDULED_POST_TYPE ) ) {
			return;
		}

		register_post_type(
			self::SCHEDULED_POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'Scheduled Posts', 'mcp-ai-wpoos-pro' ),
					'singular_name' => __( 'Scheduled Post', 'mcp-ai-wpoos-pro' ),
				),
				'public'              => false,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'menu_icon'           => 'dashicons-calendar-alt',
				'menu_position'       => 60,
				'capability_type'     => 'post',
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title', 'editor', 'author' ),
				'show_in_rest'        => true,
			)
		);
	}

	/**
	 * Handle the wp_mcp_ai_publish_scheduled_post cron hook.
	 *
	 * Transitions a scheduled post from 'future' to 'publish' status.
	 * This handler was documented but never wired up.
	 *
	 * @since 2.9.0
	 * @param int $post_id Scheduled post ID.
	 */
	public static function handle_publish_scheduled_post( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return;
		}

		$post = get_post( $post_id );
		if ( ! $post || self::SCHEDULED_POST_TYPE !== $post->post_type ) {
			return;
		}

		if ( 'future' !== $post->post_status ) {
			return;
		}

		// Transition to publish.
		wp_update_post(
			array(
				'ID'          => $post_id,
				'post_status' => 'publish',
			)
		);

		// Update meta.
		update_post_meta( $post_id, '_social_status', 'published' );
		update_post_meta( $post_id, '_social_published_at', current_time( 'mysql', true ) );

		/**
		 * Fires after a scheduled social post is published via cron.
		 *
		 * @since 2.9.0
		 * @param int $post_id Scheduled post ID.
		 */
		do_action( 'wp_mcp_ai_social_post_published', $post_id );
	}

	/**
	 * Daily cleanup: prune old published and failed scheduled posts.
	 *
	 * @since 2.9.0
	 */
	public static function run_daily_cleanup() {
		$retention_days = self::DEFAULT_RETENTION_DAYS;

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( isset( $settings['social_media']['scheduled_post_retention_days'] ) ) {
			$retention_days = absint( $settings['social_media']['scheduled_post_retention_days'] );
		}

		if ( $retention_days <= 0 ) {
			return;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( "-{$retention_days} days" ) );

		// Prune old published posts.
		$published = new WP_Query(
			array(
				'post_type'      => self::SCHEDULED_POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'date_query'     => array(
					array( 'before' => $cutoff ),
				),
				'no_found_rows'  => true,
			)
		);

		foreach ( $published->posts as $id ) {
			wp_delete_post( $id, true );
		}
		wp_reset_postdata();

		// Prune old failed/scheduled posts beyond 2x retention.
		$old_cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . ( $retention_days * 2 ) . ' days' ) );
		$stale      = new WP_Query(
			array(
				'post_type'      => self::SCHEDULED_POST_TYPE,
				'post_status'    => array( 'future', 'draft' ),
				'posts_per_page' => 50,
				'fields'         => 'ids',
				'date_query'     => array(
					array( 'before' => $old_cutoff ),
				),
				'no_found_rows'  => true,
			)
		);

		foreach ( $stale->posts as $id ) {
			wp_delete_post( $id, true );
		}
		wp_reset_postdata();
	}

	/**
	 * Force autorespond templates to no-autoload and enforce cap.
	 *
	 * @since 2.9.0
	 * @param mixed $old Previous value.
	 * @param mixed $new New value.
	 */
	public static function fix_templates_autoload( $old, $new ) {
		global $wpdb;

		// Force no-autoload.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$wpdb->options,
			array( 'autoload' => 'no' ),
			array( 'option_name' => self::TEMPLATES_OPTION ),
			array( '%s' ),
			array( '%s' )
		);

		// Enforce cap on template count.
		if ( is_array( $new ) && count( $new ) > self::MAX_TEMPLATES ) {
			$new = array_slice( $new, count( $new ) - self::MAX_TEMPLATES, self::MAX_TEMPLATES, true );
			update_option( self::TEMPLATES_OPTION, $new, false );
		}
	}
}

// Initialize.
add_action( 'plugins_loaded', array( 'WP_MCP_AI_Social_Media_Optimization', 'init' ), 40 );
