<?php
/**
 * Daily dHash index backfill for the media library.
 *
 * Keeps the `_wp_mcp_ai_image_dhash` post-meta index warm so the
 * find_similar_media tool never pays for a cold scan of large libraries.
 *
 * @package WP_MCP_AI
 * @since   1.1.87
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schedules and runs a daily perceptual-hash backfill.
 *
 * Each run hashes up to {@see WP_MCP_AI_DHash_Backfill::BATCH_SIZE} image
 * attachments that lack a cached hash, oldest first. Runs are cheap,
 * idempotent, and no-op when GD is unavailable.
 *
 * @since 1.1.87
 */
class WP_MCP_AI_DHash_Backfill {

	/**
	 * Daily cron hook name.
	 */
	const CRON_HOOK = 'wp_mcp_ai_dhash_backfill';

	/**
	 * Attachments hashed per run.
	 */
	const BATCH_SIZE = 100;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( self::CRON_HOOK, array( __CLASS__, 'backfill' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ) );
	}

	/**
	 * Schedule the daily backfill when not already scheduled.
	 *
	 * @return void
	 */
	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
		}
	}

	/**
	 * Hash a batch of unhashed image attachments.
	 *
	 * @return int Number of attachments hashed in this run.
	 */
	public static function backfill() {
		if ( ! class_exists( 'WP_MCP_AI_Image_DHash' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/helpers/class-wp-mcp-ai-image-dhash.php';
		}

		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return 0;
		}

		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => self::BATCH_SIZE,
				'orderby'        => 'date',
				'order'          => 'ASC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed post-meta key; bounded batch of 100 per run.
					array(
						'key'     => WP_MCP_AI_Image_DHash::HASH_META_KEY,
						'compare' => 'NOT EXISTS',
					),
				),
			)
		);

		$hashed = 0;

		foreach ( $query->posts as $attachment_id ) {
			$hash = WP_MCP_AI_Image_DHash::get_attachment_hash( $attachment_id );

			if ( ! is_wp_error( $hash ) ) {
				++$hashed;
			}
		}

		return $hashed;
	}
}
