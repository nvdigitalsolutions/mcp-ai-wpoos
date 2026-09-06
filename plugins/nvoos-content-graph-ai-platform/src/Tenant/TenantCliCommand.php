<?php
/**
 * Tenant Management WP-CLI Commands (Wave E4, sub-cluster 1).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Tenant_CLI_Command`:
 * byte-identical `wp mcp tenant` subcommands (list/create/assign/migrate/
 * status/toggle) with the same options, dry-run semantics, sanitization,
 * and success/error envelopes.
 *
 * Documented deviations:
 *  - Class name/namespace — the platform's PSR-4 tree.
 *  - The class file self-registers the command on autoload (the base
 *    registers it from `init.php` when `WP_CLI` is defined). The
 *    platform's `TenantBootstrap::register()` probes this class under
 *    the same `WP_CLI` gate, which triggers autoload + registration.
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\Tenant
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Tenant;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Tenant management commands for WP-CLI.
 *
 * @since 2.1.0
 */
class TenantCliCommand {

	/**
	 * List all registered tenants.
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : Filter by tenant type (e.g. 'school', 'company').
	 *
	 * [--format=<format>]
	 * : Output format: table, csv, json, yaml. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mcp tenant list
	 *     wp mcp tenant list --type=school
	 *     wp mcp tenant list --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function list( $args, $assoc_args ) {
		global $wpdb;

		$type   = isset( $assoc_args['type'] ) ? sanitize_key( $assoc_args['type'] ) : '';
		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
		$table  = $wpdb->prefix . 'mcp_ai_tenants';

		$where = '';
		if ( $type ) {
			$where = $wpdb->prepare( 'WHERE tenant_type = %s', $type );
		}

		$sql = 'SELECT id, tenant_type, tenant_name, external_id, is_active, created_at FROM ' . esc_sql( $table ) . ' ' . $where . ' ORDER BY tenant_type, id';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable

		if ( empty( $rows ) ) {
			\WP_CLI::success( __( 'No tenants found.', 'nvoos-content-graph-ai-platform' ) );
			return;
		}

		// Format is_active as human-readable.
		foreach ( $rows as &$row ) {
			$row['is_active'] = $row['is_active'] ? 'yes' : 'no';
		}
		unset( $row );

		\WP_CLI\Utils\format_items( $format, $rows, array( 'id', 'tenant_type', 'tenant_name', 'external_id', 'is_active', 'created_at' ) );
	}

	/**
	 * Create a new tenant.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : Tenant type (e.g. 'school', 'company', 'eca').
	 *
	 * <name>
	 * : Human-readable tenant name.
	 *
	 * [--external-id=<id>]
	 * : Optional external system identifier.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mcp tenant create school "Springfield Elementary"
	 *     wp mcp tenant create company "Acme Corp" --external-id=ACM001
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function create( $args, $assoc_args ) {
		global $wpdb;

		$type        = sanitize_key( $args[0] );
		$name        = sanitize_text_field( $args[1] );
		$external_id = isset( $assoc_args['external-id'] ) ? sanitize_text_field( $assoc_args['external-id'] ) : null;
		$table       = $wpdb->prefix . 'mcp_ai_tenants';

		// Check for duplicate.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$sql = 'SELECT id FROM ' . esc_sql( $table ) . ' WHERE tenant_type = %s AND tenant_name = %s';
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared -- esc_sql on table name, prepared below.
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				$sql,
				$type,
				$name
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared

		if ( $existing ) {
			\WP_CLI::error(
				sprintf(
					/* translators: 1: tenant type, 2: tenant name */
					__( 'A tenant of type "%1$s" with name "%2$s" already exists (ID: %3$d).', 'nvoos-content-graph-ai-platform' ),
					$type,
					$name,
					$existing
				)
			);
		}

		$data = array(
			'tenant_type' => $type,
			'tenant_name' => $name,
		);

		if ( $external_id ) {
			$data['external_id'] = $external_id;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->insert( $table, $data, array( '%s', '%s' ) );
		// phpcs:enable

		if ( false === $result ) {
			\WP_CLI::error( __( 'Failed to create tenant.', 'nvoos-content-graph-ai-platform' ) );
		}

		$tenant_id = (int) $wpdb->insert_id;

		\WP_CLI::success(
			sprintf(
				/* translators: 1: tenant ID, 2: tenant type, 3: tenant name */
				__( 'Created tenant #%1$d (%2$s: %3$s).', 'nvoos-content-graph-ai-platform' ),
				$tenant_id,
				$type,
				$name
			)
		);
	}

	/**
	 * Assign a WordPress user to a tenant.
	 *
	 * ## OPTIONS
	 *
	 * <user_id>
	 * : WordPress user ID or login/email.
	 *
	 * <tenant_type>
	 * : Tenant type.
	 *
	 * <tenant_id>
	 * : Tenant ID from the tenants table.
	 *
	 * [--primary]
	 * : Mark this as the user's primary tenant.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mcp tenant assign 42 school 1 --primary
	 *     wp mcp tenant assign admin@school.edu school 1
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function assign( $args, $assoc_args ) {
		$user_id_raw = $args[0];
		$tenant_type = sanitize_key( $args[1] );
		$tenant_id   = absint( $args[2] );
		$is_primary  = isset( $assoc_args['primary'] );

		// Resolve user ID from login, email, or numeric ID.
		if ( is_numeric( $user_id_raw ) ) {
			$user_id = absint( $user_id_raw );
			$user    = get_user_by( 'id', $user_id );
		} elseif ( is_email( $user_id_raw ) ) {
			$user = get_user_by( 'email', $user_id_raw );
		} else {
			$user = get_user_by( 'login', $user_id_raw );
		}

		if ( ! $user ) {
			\WP_CLI::error(
				sprintf(
					/* translators: %s: user identifier */
					__( 'User "%s" not found.', 'nvoos-content-graph-ai-platform' ),
					$user_id_raw
				)
			);
		}

		$result = TenantDatabase::assign_user( $user->ID, $tenant_type, $tenant_id, $is_primary );

		if ( false === $result ) {
			\WP_CLI::error( __( 'Failed to assign user to tenant.', 'nvoos-content-graph-ai-platform' ) );
		}

		\WP_CLI::success(
			sprintf(
				/* translators: 1: user login, 2: tenant type, 3: tenant ID */
				__( 'Assigned user "%1$s" to tenant %2$s:%3$d.', 'nvoos-content-graph-ai-platform' ),
				$user->user_login,
				$tenant_type,
				$tenant_id
			)
		);
	}

	/**
	 * Migrate existing data to add tenant columns.
	 *
	 * Adds tenant_type + tenant_id columns to a custom table or backfills
	 * post meta for a CPT.
	 *
	 * ## OPTIONS
	 *
	 * <target>
	 * : Migration target: either a custom table name (without prefix) or a
	 *   CPT slug prefixed with "cpt:" (e.g. "cpt:mcp_ai_eca").
	 *
	 * <tenant_type>
	 * : Tenant type to assign to existing data.
	 *
	 * <tenant_id>
	 * : Tenant ID to assign to existing data.
	 *
	 * [--dry-run]
	 * : Preview changes without applying them.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mcp tenant migrate mcp_ai_custom_metrics school 1
	 *     wp mcp tenant migrate cpt:mcp_ai_eca school 1 --dry-run
	 *     wp mcp tenant migrate mcp_ai_audit_trail company 5
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function migrate( $args, $assoc_args ) {
		$target      = sanitize_text_field( $args[0] );
		$tenant_type = sanitize_key( $args[1] );
		$tenant_id   = absint( $args[2] );
		$dry_run     = isset( $assoc_args['dry-run'] );

		// CPT migration.
		if ( 0 === strpos( $target, 'cpt:' ) ) {
			$post_type = substr( $target, 4 );

			if ( ! post_type_exists( $post_type ) ) {
				\WP_CLI::error(
					sprintf(
						/* translators: %s: post type slug */
						__( 'Post type "%s" does not exist.', 'nvoos-content-graph-ai-platform' ),
						$post_type
					)
				);
			}

			$count_posts = wp_count_posts( $post_type );
			$total       = isset( $count_posts->publish ) ? (int) $count_posts->publish : 0;
			$total      += isset( $count_posts->draft ) ? (int) $count_posts->draft : 0;
			$total      += isset( $count_posts->pending ) ? (int) $count_posts->pending : 0;
			$total      += isset( $count_posts->private ) ? (int) $count_posts->private : 0;

			if ( $dry_run ) {
				\WP_CLI::log(
					sprintf(
						/* translators: 1: post count, 2: tenant type, 3: tenant ID */
						__( 'Dry run: Would add tenant meta (%2$s:%3$d) to %1$d posts of type "%4$s".', 'nvoos-content-graph-ai-platform' ),
						$total,
						$tenant_type,
						$tenant_id,
						$post_type
					)
				);
				return;
			}

			$migrated = TenantMigration::migrate_cpt_posts( $post_type, $tenant_type, $tenant_id );

			\WP_CLI::success(
				sprintf(
					/* translators: 1: count, 2: tenant type, 3: tenant ID, 4: post type */
					__( 'Migrated %1$d posts of type "%4$s" to tenant %2$s:%3$d.', 'nvoos-content-graph-ai-platform' ),
					$migrated,
					$tenant_type,
					$tenant_id,
					$post_type
				)
			);
			return;
		}

		// Custom table migration.
		global $wpdb;
		$table = $wpdb->prefix . $target;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
		// phpcs:enable

		if ( ! $exists ) {
			\WP_CLI::error(
				sprintf(
					/* translators: %s: table name */
					__( 'Table "%s" does not exist.', 'nvoos-content-graph-ai-platform' ),
					$table
				)
			);
		}

		// Add columns.
		if ( ! TenantMigration::has_tenant_columns( $table ) ) {
			if ( $dry_run ) {
				\WP_CLI::log(
					sprintf(
						/* translators: %s: table name */
						__( 'Dry run: Would add tenant columns to table "%s".', 'nvoos-content-graph-ai-platform' ),
						$table
					)
				);
			} else {
				TenantMigration::add_tenant_columns( $table );
				TenantMigration::add_tenant_index( $table );
				\WP_CLI::log(
					sprintf(
						/* translators: %s: table name */
						__( 'Added tenant columns to table "%s".', 'nvoos-content-graph-ai-platform' ),
						$table
					)
				);
			}
		}

		// Backfill.
		if ( $dry_run ) {
			\WP_CLI::log(
				sprintf(
					/* translators: 1: table name, 2: tenant type, 3: tenant ID */
					__( 'Dry run: Would backfill table "%1$s" with tenant %2$s:%3$d.', 'nvoos-content-graph-ai-platform' ),
					$table,
					$tenant_type,
					$tenant_id
				)
			);
		} else {
			$updated = TenantMigration::backfill_table( $table, $tenant_type, $tenant_id );

			\WP_CLI::success(
				sprintf(
					/* translators: 1: count, 2: tenant type, 3: tenant ID, 4: table name */
					__( 'Backfilled %1$d rows in table "%4$s" with tenant %2$s:%3$d.', 'nvoos-content-graph-ai-platform' ),
					$updated,
					$tenant_type,
					$tenant_id,
					$table
				)
			);
		}
	}

	/**
	 * Show tenant feature flag status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mcp tenant status
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- WP-CLI command signature contract.
	public function status( $args, $assoc_args ) {
		$global_enabled = TenantFeatureFlags::is_enabled() ? 'yes' : 'no';
		$toolkits       = TenantFeatureFlags::get_enabled_toolkits();

		\WP_CLI::log( sprintf( 'Global tenant isolation: %s', $global_enabled ) );

		if ( empty( $toolkits ) ) {
			\WP_CLI::log( __( 'No toolkits have tenant isolation enabled.', 'nvoos-content-graph-ai-platform' ) );
		} else {
			\WP_CLI::log( __( 'Toolkits with tenant isolation:', 'nvoos-content-graph-ai-platform' ) );
			foreach ( $toolkits as $toolkit ) {
				\WP_CLI::log( '  - ' . $toolkit );
			}
		}
		// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter
	}

	/**
	 * Enable or disable tenant isolation globally.
	 *
	 * ## OPTIONS
	 *
	 * <state>
	 * : 'on' or 'off'.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mcp tenant toggle on
	 *     wp mcp tenant toggle off
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter -- $assoc_args is part of the WP-CLI command signature contract.
	public function toggle( $args, $assoc_args ) {
		$state = strtolower( $args[0] );

		if ( 'on' === $state ) {
			TenantFeatureFlags::enable();
			\WP_CLI::success( __( 'Tenant isolation enabled globally.', 'nvoos-content-graph-ai-platform' ) );
		} elseif ( 'off' === $state ) {
			TenantFeatureFlags::disable();
			\WP_CLI::success( __( 'Tenant isolation disabled globally.', 'nvoos-content-graph-ai-platform' ) );
		} else {
			\WP_CLI::error( __( 'State must be "on" or "off".', 'nvoos-content-graph-ai-platform' ) );
		}
		// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter
	}
}

if ( class_exists( 'WP_CLI' ) ) {
	\WP_CLI::add_command( 'mcp tenant', self::class );
}
