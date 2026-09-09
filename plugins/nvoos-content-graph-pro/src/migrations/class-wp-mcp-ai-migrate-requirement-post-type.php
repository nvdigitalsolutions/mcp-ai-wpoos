<?php
/**
 * WP_MCP_AI_Migrate_Requirement_Post_Type (ecosystem port - Wave F2, regulatory-registration data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/migrations/class-wp-mcp-ai-migrate-requirement-post-type.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is
 * defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; no path constants, so no path swaps.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Regulatory_Registration
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);





if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Migrate requirement post type name.
 */
class WP_MCP_AI_Migrate_Requirement_Post_Type {
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
	const OLD_POST_TYPE = 'mcp_ai_reg_requirement';

	/**
	 * New post type name.
	 *
	 * @var string
	 */
	const NEW_POST_TYPE = 'mcp_ai_requirement';

	/**
	 * Run the migration.
	 *
	 * @return array Migration results.
	 */
	public static function run() {
		global $wpdb;

		// Check if migration has already been run.
		$migration_status = get_option( 'wp_mcp_ai_migration_requirement_post_type', false );
		if ( $migration_status ) {
			return array(
				'status'  => 'already_run',
				'message' => __( 'Migration has already been completed.', 'nvoos-content-graph-pro' ),
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
			update_option( 'wp_mcp_ai_migration_requirement_post_type', self::VERSION );
			return array(
				'status'   => 'success',
				'message'  => __( 'No requirements found to migrate.', 'nvoos-content-graph-pro' ),
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
				'message' => __( 'Database error during migration.', 'nvoos-content-graph-pro' ),
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
		update_option( 'wp_mcp_ai_migration_requirement_post_type', self::VERSION );

		return array(
			'status'   => 'success',
			'message'  => sprintf(
				/* translators: %d: number of posts migrated */
				__( 'Successfully migrated %d requirements.', 'nvoos-content-graph-pro' ),
				$updated
			),
			'migrated' => $updated,
			'total'    => $total_posts,
		);
	}

	/**
	 * Rollback the migration (for testing purposes).
	 *
	 * WARNING: This will convert all requirements back to the invalid post type name.
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
			delete_option( 'wp_mcp_ai_migration_requirement_post_type' );
			return array(
				'status'      => 'success',
				'message'     => __( 'No requirements found to rollback.', 'nvoos-content-graph-pro' ),
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
				'message' => __( 'Database error during rollback.', 'nvoos-content-graph-pro' ),
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
		delete_option( 'wp_mcp_ai_migration_requirement_post_type' );

		return array(
			'status'      => 'success',
			'message'     => sprintf(
				/* translators: %d: number of posts rolled back */
				__( 'Successfully rolled back %d requirements.', 'nvoos-content-graph-pro' ),
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

		$migration_version = get_option( 'wp_mcp_ai_migration_requirement_post_type', false );

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
