<?php
/**
 * Tenant Migration Helper (Wave E4, sub-cluster 1).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Tenant_Migration`:
 * byte-identical tenant column/index DDL (ALTER TABLE … ADD COLUMN
 * `tenant_type` VARCHAR(20) DEFAULT 'school' / `tenant_id` BIGINT
 * UNSIGNED DEFAULT 0, composite `tenant_lookup` index), the
 * INFORMATION_SCHEMA column-existence probe, the SHOW INDEX
 * index-existence probe, the CPT post-meta migration (only adds when
 * unset, counts every post), and the tenant_id=0 table backfill.
 *
 * Documented deviations:
 *  - Class name/namespace — the platform's PSR-4 tree.
 *  - Text domain — n/a (no translatable strings in the ported surface).
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\Tenant
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tenant;

/**
 * Tenant Migration Helper.
 *
 * Provides utilities for adding tenant columns to existing custom tables,
 * backfilling tenant data, and migrating CPT post metadata.
 *
 * @since 2.1.0
 */
class TenantMigration {

	/**
	 * Known tenant column definitions for maybe_add_column() checks.
	 *
	 * @var string[]
	 */
	const TENANT_COLUMNS = array( 'tenant_type', 'tenant_id' );

	/**
	 * Column name used by dbDelta() definition lookup.
	 *
	 * @var string
	 */
	const TENANT_TYPE_COL = 'tenant_type';

	/**
	 * Column name used by dbDelta() definition lookup.
	 *
	 * @var string
	 */
	const TENANT_ID_COL = 'tenant_id';

	/**
	 * Add tenant_type and tenant_id columns to an existing custom table.
	 *
	 * Uses SHOW COLUMNS to check for existing columns before ALTER-ing.
	 * Each column is added individually with safe error handling.
	 *
	 * @param string $table_name Full table name including prefix.
	 * @return bool True if columns were added or already exist.
	 */
	public static function add_tenant_columns( string $table_name ): bool {
		global $wpdb;

		if ( empty( $table_name ) ) {
			return false;
		}

		$all_ok = true;

		// Add tenant_type column.
		if ( ! self::column_exists( $table_name, 'tenant_type' ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
			$result = $wpdb->query(
				'ALTER TABLE ' . esc_sql( $table_name ) . " ADD COLUMN tenant_type VARCHAR(20) NOT NULL DEFAULT 'school' AFTER id"
			);
			// phpcs:enable
			if ( false === $result ) {
				$all_ok = false;
			}
		}

		// Add tenant_id column.
		if ( ! self::column_exists( $table_name, 'tenant_id' ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
			$result = $wpdb->query(
				'ALTER TABLE ' . esc_sql( $table_name ) . ' ADD COLUMN tenant_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0 AFTER tenant_type'
			);
			// phpcs:enable
			if ( false === $result ) {
				$all_ok = false;
			}
		}

		return $all_ok;
	}

	/**
	 * Add a composite tenant index to an existing custom table.
	 *
	 * Creates an index on (tenant_type, tenant_id) if it does not
	 * already exist. The index name is `tenant_lookup`.
	 *
	 * @param string $table_name Full table name including prefix.
	 * @return bool True if index was added or already exists.
	 */
	public static function add_tenant_index( string $table_name ): bool {
		global $wpdb;

		if ( empty( $table_name ) ) {
			return false;
		}

		// Check if the index already exists.
		$index_name = 'tenant_lookup';

		if ( self::index_exists( $table_name, $index_name ) ) {
			return true;
		}

		// Verify both tenant columns exist before creating the index.
		if ( ! self::column_exists( $table_name, 'tenant_type' ) || ! self::column_exists( $table_name, 'tenant_id' ) ) {
			return false;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
		$result = $wpdb->query(
			'ALTER TABLE ' . esc_sql( $table_name ) . ' ADD KEY ' . esc_sql( $index_name ) . ' (tenant_type, tenant_id)'
		);
		// phpcs:enable

		return false !== $result;
	}

	/**
	 * Migrate CPT posts by adding tenant post meta to each.
	 *
	 * Iterates over all published posts of a given post type and
	 * adds `_tenant_type` and `_tenant_id` post meta to each.
	 *
	 * @param string $post_type   Post type slug.
	 * @param string $tenant_type Tenant type slug to assign.
	 * @param int    $tenant_id   Tenant ID to assign.
	 * @return int Number of posts updated.
	 */
	public static function migrate_cpt_posts( string $post_type, string $tenant_type, int $tenant_id ): int {
		global $wpdb;

		if ( empty( $post_type ) || empty( $tenant_type ) || $tenant_id <= 0 ) {
			return 0;
		}

		$updated = 0;

		// Get all post IDs of the given type.
		$post_ids = get_posts(
			array(
				'post_type'      => $post_type,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		foreach ( $post_ids as $post_id ) {
			$current_type = get_post_meta( $post_id, '_tenant_type', true );
			$current_id   = get_post_meta( $post_id, '_tenant_id', true );

			// Only add if not already set.
			if ( empty( $current_type ) ) {
				update_post_meta( $post_id, '_tenant_type', $tenant_type );
			}
			if ( empty( $current_id ) ) {
				update_post_meta( $post_id, '_tenant_id', $tenant_id );
			}

			++$updated;
		}

		return $updated;
	}

	/**
	 * Backfill tenant_id and tenant_type for existing rows in a custom table.
	 *
	 * Updates all rows where tenant_id = 0 or tenant_type = '' with the
	 * specified tenant values. Use with caution on large tables.
	 *
	 * @param string $table_name  Full table name including prefix.
	 * @param string $tenant_type Tenant type slug.
	 * @param int    $tenant_id   Tenant ID.
	 * @return int Number of rows updated.
	 */
	public static function backfill_table( string $table_name, string $tenant_type, int $tenant_id ): int {
		global $wpdb;

		if ( empty( $table_name ) || empty( $tenant_type ) || $tenant_id <= 0 ) {
			return 0;
		}

		// Verify columns exist before attempting update.
		if ( ! self::column_exists( $table_name, 'tenant_type' ) || ! self::column_exists( $table_name, 'tenant_id' ) ) {
			return 0;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . esc_sql( $table_name ) . ' SET tenant_type = %s, tenant_id = %d WHERE tenant_id = %d',
				$tenant_type,
				$tenant_id,
				0
			)
		);
		// phpcs:enable

		if ( false === $result ) {
			return 0;
		}

		return (int) $result;
	}

	/**
	 * Check if a table already has tenant columns.
	 *
	 * Verifies that both `tenant_type` and `tenant_id` columns exist.
	 *
	 * @param string $table_name Full table name including prefix.
	 * @return bool True if both tenant columns exist.
	 */
	public static function has_tenant_columns( string $table_name ): bool {
		if ( empty( $table_name ) ) {
			return false;
		}

		return self::column_exists( $table_name, 'tenant_type' )
			&& self::column_exists( $table_name, 'tenant_id' );
	}

	/**
	 * Check if a specific column exists in a table.
	 *
	 * @param string $table_name  Full table name including prefix.
	 * @param string $column_name Column name to check.
	 * @return bool True if the column exists.
	 */
	private static function column_exists( string $table_name, string $column_name ): bool {
		global $wpdb;

		// Use DESCRIBE to check for column existence — works across MySQL/MariaDB.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				DB_NAME,
				$table_name,
				$column_name
			)
		);
		// phpcs:enable

		return ! empty( $result );
	}

	/**
	 * Check if a specific index exists on a table.
	 *
	 * @param string $table_name Full table name including prefix.
	 * @param string $index_name Index name to check.
	 * @return bool True if the index exists.
	 */
	private static function index_exists( string $table_name, string $index_name ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var(
			$wpdb->prepare(
				'SHOW INDEX FROM ' . esc_sql( $table_name ) . ' WHERE Key_name = %s',
				$index_name
			)
		);
		// phpcs:enable

		return ! empty( $result );
	}
}
