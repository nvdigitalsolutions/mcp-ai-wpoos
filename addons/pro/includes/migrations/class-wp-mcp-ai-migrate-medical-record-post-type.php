<?php
/**
 * Migration: Update medical record post type name
 *
 * Migrates posts from old post type name 'mcp_ai_medical_record' (21 chars - invalid)
 * to new post type name 'mcp_ai_med_record' (17 chars - valid).
 *
 * This migration is necessary because WordPress requires post type names to be
 * between 1 and 20 characters in length.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migrate medical record post type name.
 */
class WP_MCP_AI_Migrate_Medical_Record_Post_Type {
	/**
	 * Migration version.
	 *
	 * @var string
	 */
	const VERSION = '1.0.0';

	/**
	 * Old post type name.
	 *
	 * @var string
	 */
	const OLD_POST_TYPE = 'mcp_ai_medical_record';

	/**
	 * New post type name.
	 *
	 * @var string
	 */
	const NEW_POST_TYPE = 'mcp_ai_med_record';

	/**
	 * Run the migration.
	 *
	 * @return array Migration results.
	 */
	public static function run() {
		global $wpdb;

		// Check if migration has already been run.
		$migration_status = get_option( 'wp_mcp_ai_migration_medical_record_post_type', false );
		if ( $migration_status ) {
			return array(
				'status'  => 'already_run',
				'message' => __( 'Migration has already been completed.', 'mcp-ai-wpoos-pro' ),
				'version' => $migration_status,
			);
		}

		// Count posts to migrate.
		$count_query = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
			self::OLD_POST_TYPE
		);
		$total_posts = (int) $wpdb->get_var( $count_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( 0 === $total_posts ) {
			// No posts to migrate, mark as complete.
			update_option( 'wp_mcp_ai_migration_medical_record_post_type', self::VERSION );
			return array(
				'status'   => 'success',
				'message'  => __( 'No medical records found to migrate.', 'mcp-ai-wpoos-pro' ),
				'migrated' => 0,
				'total'    => 0,
			);
		}

		// Update post type in posts table.
		$updated = $wpdb->update(
			$wpdb->posts,
			array( 'post_type' => self::NEW_POST_TYPE ),
			array( 'post_type' => self::OLD_POST_TYPE ),
			array( '%s' ),
			array( '%s' )
		);

		if ( false === $updated ) {
			return array(
				'status'  => 'error',
				'message' => __( 'Database error during migration.', 'mcp-ai-wpoos-pro' ),
				'error'   => $wpdb->last_error,
			);
		}

		// Clear WordPress object cache for affected posts.
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
				self::NEW_POST_TYPE
			)
		);

		foreach ( $post_ids as $post_id ) {
			clean_post_cache( $post_id );
		}

		// Mark migration as complete.
		update_option( 'wp_mcp_ai_migration_medical_record_post_type', self::VERSION );

		return array(
			'status'   => 'success',
			'message'  => sprintf(
				/* translators: %d: number of posts migrated */
				__( 'Successfully migrated %d medical records.', 'mcp-ai-wpoos-pro' ),
				$updated
			),
			'migrated' => $updated,
			'total'    => $total_posts,
		);
	}

	/**
	 * Rollback the migration (for testing purposes).
	 *
	 * WARNING: This will convert all medical records back to the invalid post type name.
	 * Only use for testing or emergency rollback.
	 *
	 * @return array Rollback results.
	 */
	public static function rollback() {
		global $wpdb;

		// Count posts to rollback.
		$count_query = $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
			self::NEW_POST_TYPE
		);
		$total_posts = (int) $wpdb->get_var( $count_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		if ( 0 === $total_posts ) {
			delete_option( 'wp_mcp_ai_migration_medical_record_post_type' );
			return array(
				'status'      => 'success',
				'message'     => __( 'No medical records found to rollback.', 'mcp-ai-wpoos-pro' ),
				'rolled_back' => 0,
				'total'       => 0,
			);
		}

		// Update post type back to old name.
		$updated = $wpdb->update(
			$wpdb->posts,
			array( 'post_type' => self::OLD_POST_TYPE ),
			array( 'post_type' => self::NEW_POST_TYPE ),
			array( '%s' ),
			array( '%s' )
		);

		if ( false === $updated ) {
			return array(
				'status'  => 'error',
				'message' => __( 'Database error during rollback.', 'mcp-ai-wpoos-pro' ),
				'error'   => $wpdb->last_error,
			);
		}

		// Clear WordPress object cache.
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s",
				self::OLD_POST_TYPE
			)
		);

		foreach ( $post_ids as $post_id ) {
			clean_post_cache( $post_id );
		}

		// Remove migration marker.
		delete_option( 'wp_mcp_ai_migration_medical_record_post_type' );

		return array(
			'status'      => 'success',
			'message'     => sprintf(
				/* translators: %d: number of posts rolled back */
				__( 'Successfully rolled back %d medical records.', 'mcp-ai-wpoos-pro' ),
				$updated
			),
			'rolled_back' => $updated,
			'total'       => $total_posts,
		);
	}

	/**
	 * Get migration status.
	 *
	 * @return array Migration status information.
	 */
	public static function get_status() {
		global $wpdb;

		$migration_version = get_option( 'wp_mcp_ai_migration_medical_record_post_type', false );

		// Count posts with old post type.
		$old_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
				self::OLD_POST_TYPE
			)
		);

		// Count posts with new post type.
		$new_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
				self::NEW_POST_TYPE
			)
		);

		return array(
			'migration_completed' => (bool) $migration_version,
			'migration_version'   => $migration_version,
			'old_post_type_count' => $old_count,
			'new_post_type_count' => $new_count,
			'needs_migration'     => $old_count > 0,
		);
	}
}
