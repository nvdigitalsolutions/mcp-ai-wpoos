<?php
/**
 * Ecommerce Toolkit Performance Optimization
 *
 * Fixes autoload bloat from inventory movement logs and adds
 * temp-directory cleanup for orphaned export/invoice files.
 *
 * @package WP_MCP_AI_Pro
 * @since  2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Ecommerce optimization manager.
 *
 * @since 2.9.0
 */
class WP_MCP_AI_Ecommerce_Optimization {

	/**
	 * Cron hook for daily cleanup.
	 *
	 * @var string
	 */
	const CLEANUP_HOOK = 'wp_mcp_ai_ec_daily_cleanup';

	/**
	 * Temp directory path relative to uploads.
	 *
	 * @var string
	 */
	const TEMP_DIR = 'wp-mcp-ai-temp';

	/**
	 * Initialize.
	 *
	 * @since 2.9.0
	 */
	public static function init() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_ecommerce_toolkit'] ) ) {
			return;
		}

		// Register daily cleanup cron.
		add_action( self::CLEANUP_HOOK, array( __CLASS__, 'run_daily_cleanup' ) );
		add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 40 );

		// Force no-autoload on inventory movements option.
		add_action( 'update_option_wp_mcp_ai_inventory_movements', array( __CLASS__, 'fix_inventory_autoload' ), 10, 2 );
		add_action( 'added_option_wp_mcp_ai_inventory_movements', array( __CLASS__, 'fix_inventory_autoload' ), 10, 2 );
	}

	/**
	 * Schedule daily cleanup.
	 *
	 * @since 2.9.0
	 */
	public static function maybe_schedule() {
		if ( ! wp_next_scheduled( self::CLEANUP_HOOK ) ) {
			wp_schedule_event( strtotime( 'tomorrow 04:00:00' ), 'daily', self::CLEANUP_HOOK );
		}
	}

	/**
	 * Force inventory movements option to no-autoload.
	 *
	 * This option stores up to 1000 inventory movement records at
	 * ~500 bytes each = 500KB+. It should never autoload.
	 *
	 * @since 2.9.0
	 * @param mixed $old       Previous value (unused).
	 * @param mixed $new_value New value (unused).
	 */
	public static function fix_inventory_autoload( $old, $new_value ) {
		unset( $old, $new_value );
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->update(
			$wpdb->options,
			array( 'autoload' => 'no' ),
			array( 'option_name' => 'wp_mcp_ai_inventory_movements' ),
			array( '%s' ),
			array( '%s' )
		);
	}

	/**
	 * Daily cleanup: purge old temp files and compact post meta logs.
	 *
	 * @since 2.9.0
	 */
	public static function run_daily_cleanup() {
		self::cleanup_temp_directory();
		self::prune_inventory_post_meta();
	}

	/**
	 * Delete temp files older than 24 hours from wp-mcp-ai-temp/.
	 *
	 * Five ecommerce tools create files in this directory with
	 * inconsistent cleanup — this ensures orphans are removed.
	 *
	 * @since 2.9.0
	 */
	private static function cleanup_temp_directory() {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		$upload_dir = wp_upload_dir();
		$temp_path  = trailingslashit( $upload_dir['basedir'] ) . self::TEMP_DIR;

		if ( ! $wp_filesystem->is_dir( $temp_path ) ) {
			return;
		}

		$files = $wp_filesystem->dirlist( $temp_path );
		if ( ! is_array( $files ) ) {
			return;
		}

		$cutoff    = time() - DAY_IN_SECONDS; // 24 hours.
		$total     = 0;
		$max_files = 200; // Safety cap - don't iterate thousands.

		foreach ( $files as $file => $info ) {
			$filepath = $temp_path . '/' . $file;

			if ( 'f' === $info['type'] && $info['lastmodunix'] < $cutoff ) {
				$wp_filesystem->delete( $filepath );
				++$total;
			}

			if ( $total >= $max_files ) {
				break;
			}
		}

		// Also clean empty subdirectories.
		foreach ( $files as $file => $info ) {
			if ( 'd' === $info['type'] ) {
				$filepath  = $temp_path . '/' . $file;
				$sub_files = $wp_filesystem->dirlist( $filepath );
				if ( is_array( $sub_files ) && count( $sub_files ) <= 0 ) {
					$wp_filesystem->rmdir( $filepath );
				}
			}
		}
	}

	/**
	 * Time-based pruning of inventory movement post meta per product.
	 *
	 * Removes entries older than 180 days from _inventory_movement_log
	 * and _inventory_sync_log post meta.
	 *
	 * @since 2.9.0
	 */
	private static function prune_inventory_post_meta() {
		$cutoff = time() - ( 180 * DAY_IN_SECONDS );
		$batch  = 50;

		// Query products that have inventory logs.
		$query = new WP_Query(
			array(
				'post_type'      => 'product',
				'post_status'    => 'publish',
				'posts_per_page' => $batch,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => '_inventory_movement_log',
						'compare' => 'EXISTS',
					),
				),
				'no_found_rows'  => true,
			)
		);

		foreach ( $query->posts as $product_id ) {
			$log = get_post_meta( $product_id, '_inventory_movement_log', true );
			if ( ! is_array( $log ) ) {
				continue;
			}

			$filtered = array_filter(
				$log,
				function ( $entry ) use ( $cutoff ) {
					$ts = isset( $entry['timestamp'] ) ? (int) $entry['timestamp'] : 0;
					return $ts <= 0 || $ts > $cutoff;
				}
			);

			if ( count( $filtered ) !== count( $log ) ) {
				update_post_meta( $product_id, '_inventory_movement_log', array_values( $filtered ) );
			}
		}
		wp_reset_postdata();
	}
}
