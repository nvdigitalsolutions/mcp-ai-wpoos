<?php
/**
 * Tenant System Initialization
 *
 * Bootstraps the tenant isolation layer.  This file is loaded by the main
 * plugin bootstrap via includes/class-wp-mcp-ai-plugin.php.
 *
 * Responsibilities:
 *   - Load all tenant classes.
 *   - Register database table creation hooks.
 *   - Register REST API fields for tenant metadata.
 *
 * @package WP_MCP_AI
 * @since   3.1.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ─── Require tenant classes ──────────────────────────────────────────
require_once __DIR__ . '/class-wp-mcp-ai-tenant-context.php';
require_once __DIR__ . '/class-wp-mcp-ai-tenant-repository.php';
require_once __DIR__ . '/class-wp-mcp-ai-tenant-database.php';
require_once __DIR__ . '/class-wp-mcp-ai-tenant-options.php';
require_once __DIR__ . '/class-wp-mcp-ai-tenant-feature-flags.php';
require_once __DIR__ . '/class-wp-mcp-ai-tenant-migration.php';

// WP-CLI commands (only when running via CLI).
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once __DIR__ . '/class-wp-mcp-ai-tenant-cli-command.php';
}

// ─── Boot database tables ────────────────────────────────────────────
WP_MCP_AI_Tenant_Database::init();

/**
 * Register tenant post meta for REST API exposure.
 *
 * Allows the tenant_type and tenant_id post meta to be read via the
 * WordPress REST API (show_in_rest = true).
 *
 * @return void
 */
function wp_mcp_ai_register_tenant_rest_meta(): void {
	$post_types = get_post_types( array( 'public' => true ), 'names' );

	foreach ( $post_types as $post_type ) {
		register_post_meta(
			$post_type,
			'_tenant_type',
			array(
				'type'              => 'string',
				'description'       => __( 'Tenant type for multi-tenant isolation.', 'mcp-ai-wpoos' ),
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_key',
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);

		register_post_meta(
			$post_type,
			'_tenant_id',
			array(
				'type'              => 'integer',
				'description'       => __( 'Tenant ID for multi-tenant isolation.', 'mcp-ai-wpoos' ),
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => function () {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}
}
add_action( 'rest_api_init', 'wp_mcp_ai_register_tenant_rest_meta' );

/**
 * Add tenant columns to admin post list tables.
 *
 * @param array<string, string> $columns Existing columns.
 * @return array<string, string>
 */
function wp_mcp_ai_add_tenant_admin_columns( array $columns ): array {
	$new_columns = array();

	foreach ( $columns as $key => $label ) {
		$new_columns[ $key ] = $label;
		if ( 'title' === $key ) {
			$new_columns['tenant'] = __( 'Tenant', 'mcp-ai-wpoos' );
		}
	}

	return $new_columns;
}

/**
 * Render tenant column content.
 *
 * @param string $column  Column ID.
 * @param int    $post_id Post ID.
 * @return void
 */
function wp_mcp_ai_render_tenant_admin_column( string $column, int $post_id ): void {
	if ( 'tenant' !== $column ) {
		return;
	}

	$type = get_post_meta( $post_id, '_tenant_type', true );
	$id   = get_post_meta( $post_id, '_tenant_id', true );

	if ( empty( $type ) || empty( $id ) ) {
		echo '<span aria-hidden="true">—</span>';
		return;
	}

	echo esc_html( sprintf( '%s:%d', $type, (int) $id ) );
}

// Only add admin columns when tenant isolation is active.
if ( WP_MCP_AI_Tenant_Feature_Flags::is_enabled() ) {
	add_filter( 'manage_posts_columns', 'wp_mcp_ai_add_tenant_admin_columns' );
	add_action( 'manage_posts_custom_column', 'wp_mcp_ai_render_tenant_admin_column', 10, 2 );
	add_filter( 'manage_pages_columns', 'wp_mcp_ai_add_tenant_admin_columns' );
	add_action( 'manage_pages_custom_column', 'wp_mcp_ai_render_tenant_admin_column', 10, 2 );
}

/**
 * Filter post queries by tenant when isolation is active.
 *
 * Hooks into pre_get_posts to automatically append tenant meta queries
 * for admin list tables and REST API requests.
 *
 * @param WP_Query $query The WP_Query instance.
 * @return void
 */
function wp_mcp_ai_filter_query_by_tenant( WP_Query $query ): void {
	// Only apply when tenant isolation is globally enabled.
	if ( ! WP_MCP_AI_Tenant_Feature_Flags::is_enabled() ) {
		return;
	}

	// Don't filter in admin unless explicitly enabled per-toolkit.
	if ( is_admin() ) {
		return;
	}

	$context = WP_MCP_AI_Tenant_Context::instance()->resolve();
	if ( is_wp_error( $context ) ) {
		return;
	}

	$existing_meta = $query->get( 'meta_query', array() );
	if ( ! is_array( $existing_meta ) ) {
		$existing_meta = array();
	}

	$existing_meta[] = array(
		'key'     => '_tenant_id',
		'value'   => $context['id'],
		'compare' => '=',
		'type'    => 'NUMERIC',
	);

	$existing_meta[] = array(
		'key'     => '_tenant_type',
		'value'   => $context['type'],
		'compare' => '=',
	);

	$existing_meta['relation'] = 'AND';
	$query->set( 'meta_query', $existing_meta );
}
add_action( 'pre_get_posts', 'wp_mcp_ai_filter_query_by_tenant' );

// ─── Tenant-scoped post type registry ────────────────────────────────

/**
 * Default tenant-scoped post types.
 *
 * Toolkits can extend this via the `wp_mcp_ai_tenant_scoped_post_types`
 * filter in their init files.  When tenant isolation is active the
 * save_post hook below will automatically stamp `_tenant_type` and
 * `_tenant_id` post meta on every new or updated post whose post type
 * appears in this list.
 *
 * @since 3.1.0
 * @var string[]
 */
function wp_mcp_ai_get_tenant_scoped_post_types(): array {
	/**
	 * Filter: wp_mcp_ai_tenant_scoped_post_types
	 *
	 * Add CPT slugs that should receive automatic tenant meta stamping
	 * via the save_post hook and automatic read filtering via pre_get_posts.
	 *
	 * @since 3.1.0
	 *
	 * @param string[] $post_types Array of post type slugs.
	 */
	return apply_filters(
		'wp_mcp_ai_tenant_scoped_post_types',
		array(
			// ── CRM (6 CPTs) ──────────────────────────────────────
			'mcp_ai_lead',
			'mcp_ai_deal',
			'mcp_ai_company',
			'mcp_ai_customer',
			'mcp_ai_crm_activity',
			'mcp_ai_crm_wf_rule',
			'mcp_crm_message',

			// ── Healthcare (2 CPTs; most use CCTs or JetEngine) ───
			'mcp_ai_imaging_study',
			'mcp_ai_hc_vital_log',

			// ── Calendar Booking (4 CPTs) ─────────────────────────
			'mcp_appointment',
			'mcp_service',
			'mcp_staff',
			'mcp_ai_event',

			// ── Project Management (2 CPTs) ───────────────────────
			'mcp_ai_project',
			'mcp_ai_task',

			// ── Architectural Design (4 CPTs) ─────────────────────
			'mcp_ai_arch_draw',
			'mcp_ai_arch_prec',
			'mcp_ai_arch_proj',
			'mcp_ai_arch_spec',

			// ── Comic Creation (4 CPTs) ───────────────────────────
			'mcp_ai_comic',
			'mcp_ai_comic_char',
			'mcp_ai_comic_panel',
			'mcp_ai_comic_script',

			// ── Media (3 CPTs) ────────────────────────────────────
			'mcp_ai_media_coll',
			'mcp_ai_media_tpl',
			'mcp_ai_image_tpl',

			// ── Document Generation (2 CPTs) ──────────────────────
			'mcp_ai_doc_tpl',
			'mcp_content_template',

			// ── QMS (1 CPT) ───────────────────────────────────────
			'mcp_ai_doc_record',

			// ── Financial Planning (1 CPT) ────────────────────────
			'mcp_ai_fin_account',

			// ── Quiz (1 CPT) ──────────────────────────────────────
			'mcp_ai_quiz',

			// ── ECA Management (1 CPT) ────────────────────────────
			'mcp_ai_eca',

			// ── Places (1 CPT) ────────────────────────────────────
			'mcp_ai_place',

			// ── Extended Cognition (1 CPT) ────────────────────────
			'mcp_ai_cog_session',

			// ── Orchestration (1 CPT) ─────────────────────────────
			'mcp_ai_sequence',

			// ── Support / PARA / Channels ─────────────────────────
			'mcp_ai_ticket',
			'mcp_ai_area',
			'mcp_chan_contact',
			'mcp_chan_message',

			// ── Security ──────────────────────────────────────────
			'mcp_ai_audit',
		)
	);
}

/**
 * Auto-stamp tenant post meta on CPT save.
 *
 * When tenant isolation is globally enabled, every insert or update of a
 * tenant-scoped post type receives `_tenant_type` and `_tenant_id` post
 * meta derived from the current tenant context.  This is the write-side
 * counterpart of the `pre_get_posts` read-side filter.
 *
 * Skips autosaves, revisions, posts that already have tenant meta, and
 * post types not in the tenant-scoped registry.
 *
 * @since 3.1.0
 *
 * @param int     $post_id Post ID.
 * @param WP_Post $post    Post object.
 * @param bool    $update  Whether this is an update.
 * @return void
 */
function wp_mcp_ai_stamp_tenant_meta_on_save( int $post_id, WP_Post $post, bool $update ): void {
	// Never stamp during autosaves or when processing revisions.
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
		return;
	}

	// Only apply when tenant isolation is globally enabled.
	if ( ! WP_MCP_AI_Tenant_Feature_Flags::is_enabled() ) {
		return;
	}

	// Only stamp tenant-scoped post types.
	$scoped_types = wp_mcp_ai_get_tenant_scoped_post_types();
	if ( ! in_array( $post->post_type, $scoped_types, true ) ) {
		return;
	}

	// Don't overwrite existing tenant assignments (preserves explicit assignment).
	$existing_id = get_post_meta( $post_id, '_tenant_id', true );
	if ( ! empty( $existing_id ) ) {
		return;
	}

	// Resolve current tenant context.
	$context = WP_MCP_AI_Tenant_Context::instance()->resolve();
	if ( is_wp_error( $context ) ) {
		return;
	}

	update_post_meta( $post_id, '_tenant_type', $context['type'] );
	update_post_meta( $post_id, '_tenant_id', $context['id'] );
}
add_action( 'save_post', 'wp_mcp_ai_stamp_tenant_meta_on_save', 20, 3 );

/**
 * Update pre_get_posts filter to only target tenant-scoped post types.
 *
 * Limits the automatic meta_query injection to post types registered
 * as tenant-scoped, preventing unintended filtering of core WordPress
 * content (pages, posts, media) when tenant isolation is active.
 *
 * @param WP_Query $query The WP_Query instance.
 * @return void
 */
function wp_mcp_ai_filter_query_scoped_types_only( WP_Query $query ): void {
	// Only apply when tenant isolation is globally enabled.
	if ( ! WP_MCP_AI_Tenant_Feature_Flags::is_enabled() ) {
		return;
	}

	$post_type = $query->get( 'post_type', '' );

	// Determine which post types the query targets.
	if ( empty( $post_type ) ) {
		$post_type = array( 'post' ); // WordPress default.
	}

	$target_types = is_array( $post_type ) ? $post_type : array( $post_type );
	$scoped_types = wp_mcp_ai_get_tenant_scoped_post_types();

	// Only filter when ALL queried post types are tenant-scoped.
	$all_scoped = true;
	foreach ( $target_types as $type ) {
		if ( ! in_array( $type, $scoped_types, true ) ) {
			$all_scoped = false;
			break;
		}
	}

	if ( ! $all_scoped ) {
		return;
	}

	// Don't filter in admin unless explicitly enabled.
	if ( is_admin() ) {
		return;
	}

	$context = WP_MCP_AI_Tenant_Context::instance()->resolve();
	if ( is_wp_error( $context ) ) {
		return;
	}

	$existing_meta = $query->get( 'meta_query', array() );
	if ( ! is_array( $existing_meta ) ) {
		$existing_meta = array();
	}

	$existing_meta[] = array(
		'key'     => '_tenant_id',
		'value'   => $context['id'],
		'compare' => '=',
		'type'    => 'NUMERIC',
	);

	$existing_meta[] = array(
		'key'     => '_tenant_type',
		'value'   => $context['type'],
		'compare' => '=',
	);

	$existing_meta['relation'] = 'AND';
	$query->set( 'meta_query', $existing_meta );
}

// Replace the broad pre_get_posts hook with the scoped version when the
// CPT registry is populated.  The broad hook remains as a fallback.
add_action( 'pre_get_posts', 'wp_mcp_ai_filter_query_scoped_types_only', 15 );

// ─── Custom table tenant-column migration ───────────────────────────

/**
 * Known custom tables that need tenant columns.
 *
 * This registry drives the bulk migration that adds `tenant_type` and
 * `tenant_id` columns plus a composite `tenant_lookup` index to every
 * custom table owned by the plugin.  The migration is idempotent —
 * columns that already exist are skipped.
 *
 * @since 3.1.0
 * @return string[] Fully-qualified table names (with prefix).
 */
function wp_mcp_ai_get_tenant_migratable_tables(): array {
	global $wpdb;

	return array(
		// Pro Database (ISO 27001 compliance).
		$wpdb->prefix . 'mcp_ai_controls',
		$wpdb->prefix . 'mcp_ai_evidence',
		$wpdb->prefix . 'mcp_ai_audit_trail',
		$wpdb->prefix . 'mcp_ai_risks',
		$wpdb->prefix . 'mcp_ai_compliance_checks',

		// Analytics.
		$wpdb->prefix . 'mcp_ai_custom_metrics',
		$wpdb->prefix . 'mcp_ai_events',

		// QMS Audit.
		$wpdb->prefix . 'mcp_ai_qms_audit',

		// Async job queue.
		$wpdb->prefix . 'mcp_ai_job_queue',

		// Threads.
		$wpdb->prefix . 'mcp_ai_threads',
		$wpdb->prefix . 'mcp_ai_thread_messages',
		$wpdb->prefix . 'mcp_ai_thread_checkpoints',

		// Token tracking.
		$wpdb->prefix . 'mcp_ai_hourly_token_usage',

		// Graphify (5 tables).
		$wpdb->prefix . 'nvoos_graph_nodes',
		$wpdb->prefix . 'nvoos_graph_edges',
		$wpdb->prefix . 'nvoos_graph_meta',
		$wpdb->prefix . 'nvoos_graph_remote_sources',
		$wpdb->prefix . 'nvoos_graph_node_embeddings',
	);
}

/**
 * Run the bulk tenant-column migration for all known custom tables.
 *
 * Called on plugin activation and upgrade.  Idempotent — tables that
 * already have tenant columns are skipped.  Each table also receives a
 * composite `tenant_lookup` index on (tenant_type, tenant_id).
 *
 * @since 3.1.0
 *
 * @return array{added: int, indexed: int, skipped: int} Migration summary.
 */
function wp_mcp_ai_migrate_all_custom_tables(): array {
	$tables  = wp_mcp_ai_get_tenant_migratable_tables();
	$added   = 0;
	$indexed = 0;
	$skipped = 0;

	foreach ( $tables as $table_name ) {
		// Skip tables that don't exist yet (the owning component may not be active).
		if ( ! WP_MCP_AI_Tenant_Migration::has_tenant_columns( $table_name ) ) {
			if ( WP_MCP_AI_Tenant_Migration::add_tenant_columns( $table_name ) ) {
				++$added;
			}
		} else {
			++$skipped;
		}

		// Add the lookup index (safe — checks for existence internally).
		if ( WP_MCP_AI_Tenant_Migration::add_tenant_index( $table_name ) ) {
			++$indexed;
		}
	}

	return array(
		'added'   => $added,
		'indexed' => $indexed,
		'skipped' => $skipped,
	);
}

// Run migration on plugin activation / upgrade.
add_action( 'wp_mcp_ai_after_plugin_upgrade', 'wp_mcp_ai_migrate_all_custom_tables' );
