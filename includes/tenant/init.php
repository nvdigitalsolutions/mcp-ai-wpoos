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
