<?php
/**
 * Tenant Database — Schema Management (Wave E4, sub-cluster 1).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Tenant_Database`:
 * byte-identical `mcp_ai_tenants` + `mcp_ai_tenant_user_map` dbDelta
 * schemas (columns, keys, charset collation), the `1.0.0` DB version +
 * `wp_mcp_ai_tenant_db_version` option gate, the cached
 * `tables_installed()` SHOW TABLES probe, the drop_tables() uninstall
 * path, and the assign_user()/get_user_tenants() mapping lifecycle
 * (upsert-by-user_tenant, primary-user-meta side write, is_primary
 * ordering).
 *
 * Documented deviations:
 *  - Class name/namespace — the platform's PSR-4 tree.
 *  - Standalone-only hook registration via `Plugin::registerTenant()`
 *    — the base tenant init.php owns the same `admin_init` +
 *    `wp_mcp_ai_activate` table hooks in monolith installs; double
 *    registration would double-create the shared tables.
 *  - Text domain — n/a (no translatable strings in the ported surface).
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\Tenant
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tenant;

/**
 * Tenant database schema manager.
 *
 * Creates and maintains the tenant registry and user-to-tenant mapping
 * tables.
 *
 * @since 2.1.0
 */
class TenantDatabase {

	/**
	 * Schema version stored in options.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Option key for schema version tracking.
	 *
	 * @var string
	 */
	const VERSION_OPTION = 'wp_mcp_ai_tenant_db_version';

	/**
	 * Cached result of the table-existence check.
	 *
	 * @var bool|null
	 */
	private static $tables_installed = null;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_init', array( __CLASS__, 'maybe_create_tables' ) );
		// Also run on plugin activation to ensure tables exist before admin_init.
		add_action( 'wp_mcp_ai_activate', array( __CLASS__, 'create_tables' ) );
	}

	/**
	 * Check if tables need creating or updating.
	 *
	 * @return void
	 */
	public static function maybe_create_tables(): void {
		$installed = get_option( self::VERSION_OPTION, '0' );
		if ( version_compare( $installed, self::DB_VERSION, '<' ) ) {
			self::create_tables();
			update_option( self::VERSION_OPTION, self::DB_VERSION, false );
		}
	}

	/**
	 * Create or update the tenant database tables.
	 *
	 * Safe to call multiple times — dbDelta only applies changes.
	 *
	 * @return void
	 */
	public static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		// Tenants registry table.
		$tenants_table = $wpdb->prefix . 'mcp_ai_tenants';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
		$tenants_sql = "CREATE TABLE {$tenants_table} (
			id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			tenant_type     VARCHAR(50)  NOT NULL COMMENT 'Tenant type: school, company, etc.',
			tenant_name     VARCHAR(255) NOT NULL COMMENT 'Human-readable tenant name.',
			external_id     VARCHAR(191) DEFAULT NULL COMMENT 'External system identifier.',
			settings        LONGTEXT     DEFAULT NULL COMMENT 'JSON-encoded tenant settings.',
			is_active       TINYINT(1)   NOT NULL DEFAULT 1,
			created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY type_name (tenant_type, tenant_name),
			KEY tenant_type (tenant_type),
			KEY external_id (external_id)
		) {$charset_collate};";

		dbDelta( $tenants_sql );

		// User-to-tenant mapping table.
		$user_map_table = $wpdb->prefix . 'mcp_ai_tenant_user_map';
		$user_map_sql   = "CREATE TABLE {$user_map_table} (
			id              BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id         BIGINT(20) UNSIGNED NOT NULL COMMENT 'WordPress user ID.',
			tenant_type     VARCHAR(50)  NOT NULL COMMENT 'Tenant type.',
			tenant_id       BIGINT(20) UNSIGNED NOT NULL COMMENT 'FK → mcp_ai_tenants.id.',
			is_primary      TINYINT(1)   NOT NULL DEFAULT 0,
			assigned_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
			assigned_by     BIGINT(20) UNSIGNED DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY user_tenant (user_id, tenant_type, tenant_id),
			KEY tenant_lookup (tenant_type, tenant_id),
			KEY user_primary (user_id, is_primary)
		) {$charset_collate};";

		dbDelta( $user_map_sql );
		// phpcs:enable

		self::$tables_installed = true;
	}

	/**
	 * Whether the tenant tables exist in the database.
	 *
	 * CRUD methods call this before querying: on sites where the plugin is
	 * loaded but the schema has not been created yet (for example CI test
	 * installs), querying would spam database errors.
	 *
	 * The result is cached for the lifetime of the request; create_tables()
	 * and drop_tables() refresh it.
	 *
	 * @return bool True when the tenants table exists.
	 */
	public static function tables_installed(): bool {
		if ( null !== self::$tables_installed ) {
			return self::$tables_installed;
		}

		global $wpdb;
		$tenants_table = $wpdb->prefix . 'mcp_ai_tenants';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-off schema check; cached in a static.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tenants_table ) );

		self::$tables_installed = ( $tenants_table === $found );

		return self::$tables_installed;
	}

	/**
	 * Drop tenant tables (used in uninstall.php only).
	 *
	 * @return void
	 */
	public static function drop_tables(): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mcp_ai_tenants" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}mcp_ai_tenant_user_map" );
		// phpcs:enable
		delete_option( self::VERSION_OPTION );

		self::$tables_installed = false;
	}

	/**
	 * Add a user-to-tenant mapping.
	 *
	 * @param int    $user_id    WordPress user ID.
	 * @param string $type       Tenant type.
	 * @param int    $tenant_id  Tenant ID (from mcp_ai_tenants table).
	 * @param bool   $is_primary Whether this is the user's primary tenant.
	 * @return bool|int False on failure, row ID on success.
	 */
	public static function assign_user( int $user_id, string $type, int $tenant_id, bool $is_primary = false ) {
		if ( ! self::tables_installed() ) {
			return false;
		}

		global $wpdb;

		$table = $wpdb->prefix . 'mcp_ai_tenant_user_map';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sql = 'SELECT id FROM ' . esc_sql( $table ) . ' WHERE user_id = %d AND tenant_type = %s AND tenant_id = %d';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql uses esc_sql() for table name; prepared below.
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Escaped above, prepared here.
				$user_id,
				$type,
				$tenant_id
			)
		);

		if ( $existing ) {
			return (int) $existing;
		}

		$result = $wpdb->insert(
			$table,
			array(
				'user_id'     => $user_id,
				'tenant_type' => $type,
				'tenant_id'   => $tenant_id,
				'is_primary'  => $is_primary ? 1 : 0,
				'assigned_by' => get_current_user_id() ? get_current_user_id() : null,
			),
			array( '%d', '%s', '%d', '%d', '%d' )
		);
		// phpcs:enable

		if ( false === $result ) {
			return false;
		}

		$row_id = (int) $wpdb->insert_id;

		// Also update user meta for fast lookup.
		if ( $is_primary ) {
			update_user_meta(
				$user_id,
				'_wp_mcp_ai_tenant',
				array(
					'type' => $type,
					'id'   => $tenant_id,
				)
			);
		}

		return $row_id;
	}

	/**
	 * Get all tenants a user belongs to.
	 *
	 * @param int $user_id WordPress user ID.
	 * @return array<int, array{type: string, id: int, is_primary: bool}>
	 */
	public static function get_user_tenants( int $user_id ): array {
		if ( ! self::tables_installed() ) {
			return array();
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mcp_ai_tenant_user_map';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sql = 'SELECT tenant_type, tenant_id, is_primary FROM ' . esc_sql( $table ) . ' WHERE user_id = %d ORDER BY is_primary DESC';
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- $sql uses esc_sql() for table name; prepared below.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				$sql, // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Escaped above, prepared here.
				$user_id
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( ! $rows ) {
			return array();
		}

		return array_map(
			function ( $row ) {
				return array(
					'type'       => $row['tenant_type'],
					'id'         => (int) $row['tenant_id'],
					'is_primary' => (bool) $row['is_primary'],
				);
			},
			$rows
		);
	}
}
