<?php
/**
 * PARA Admin Columns.
 *
 * Adds a "PARA" badge column to the list tables of all PARA-classified post types.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin column registration.
 */
class WP_MCP_AI_PARA_Admin_Columns {

	/**
	 * Initialize hooks.
	 */
	public static function init() {
		if ( ! is_admin() ) {
			return;
		}
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_head', array( __CLASS__, 'inline_styles' ) );
	}

	/**
	 * Register columns for each PARA-classified post type.
	 */
	public static function register() {
		if ( ! WP_MCP_AI_PARA_Taxonomy::is_enabled() ) {
			return;
		}
		foreach ( WP_MCP_AI_PARA_Taxonomy::get_object_types() as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", array( __CLASS__, 'add_column' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( __CLASS__, 'render_column' ), 10, 2 );
		}
	}

	/**
	 * Add the column.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function add_column( $columns ) {
		$columns['mcp_ai_para'] = __( 'PARA', 'mcp-ai-wpoos-pro' );
		return $columns;
	}

	/**
	 * Render the column.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function render_column( $column, $post_id ) {
		if ( 'mcp_ai_para' !== $column ) {
			return;
		}
		$bucket = WP_MCP_AI_PARA_Taxonomy::get_post_bucket( $post_id );
		if ( ! $bucket ) {
			echo '<span class="wp-mcp-ai-para-badge wp-mcp-ai-para-empty">—</span>';
			return;
		}
		printf(
			'<span class="wp-mcp-ai-para-badge wp-mcp-ai-para-%s">%s</span>',
			esc_attr( $bucket ),
			esc_html( ucfirst( $bucket ) )
		);
	}

	/**
	 * Inline styles for the badge.
	 */
	public static function inline_styles() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || 'edit' !== $screen->base ) {
			return;
		}
		if ( ! in_array( $screen->post_type, WP_MCP_AI_PARA_Taxonomy::get_object_types(), true ) ) {
			return;
		}
		echo '<style>
.wp-mcp-ai-para-badge{display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;text-transform:uppercase;}
.wp-mcp-ai-para-projects{background:#dbeafe;color:#1e40af;}
.wp-mcp-ai-para-areas{background:#dcfce7;color:#166534;}
.wp-mcp-ai-para-resources{background:#fef3c7;color:#92400e;}
.wp-mcp-ai-para-archives{background:#e5e7eb;color:#374151;}
.wp-mcp-ai-para-empty{color:#9ca3af;}
</style>';
	}
}
