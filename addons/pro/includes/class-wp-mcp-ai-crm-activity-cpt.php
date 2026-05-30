<?php
/**
 * CRM Activity Custom Post Type for sales activity tracking.
 *
 * Registers `mcp_ai_crm_activity` — a lightweight activity record for
 * calls, emails, meetings, tasks, and notes attached to leads or deals.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CRM_Toolkit
 * @since 2.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRM Activity CPT.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_CRM_Activity_CPT {

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_crm_activity';

	/**
	 * Valid activity types.
	 *
	 * @var string[]
	 */
	const ACTIVITY_TYPES = array( 'call', 'email', 'meeting', 'task', 'note' );

	/**
	 * Initialize.
	 *
	 * @since 2.3.0
	 */
	public static function init() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return;
		}

		add_action( 'init', array( __CLASS__, 'register_post_type' ) );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'add_admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_admin_columns' ), 10, 2 );
	}

	/**
	 * Register the activity post type.
	 *
	 * @since 2.3.0
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'               => _x( 'Activities', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name'      => _x( 'Activity', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'menu_name'          => _x( 'Activities', 'admin menu', 'mcp-ai-wpoos-pro' ),
					'add_new'            => _x( 'Log Activity', 'activity', 'mcp-ai-wpoos-pro' ),
					'add_new_item'       => __( 'Log New Activity', 'mcp-ai-wpoos-pro' ),
					'edit_item'          => __( 'Edit Activity', 'mcp-ai-wpoos-pro' ),
					'all_items'          => __( 'All Activities', 'mcp-ai-wpoos-pro' ),
					'search_items'       => __( 'Search Activities', 'mcp-ai-wpoos-pro' ),
					'not_found'          => __( 'No activities found.', 'mcp-ai-wpoos-pro' ),
					'not_found_in_trash' => __( 'No activities found in Trash.', 'mcp-ai-wpoos-pro' ),
				),
				'description'     => __( 'CRM sales activities: calls, emails, meetings, tasks, notes.', 'mcp-ai-wpoos-pro' ),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_icon'       => 'dashicons-list-view',
				'menu_position'   => 57,
				'capability_type' => 'post',
				'has_archive'     => false,
				'hierarchical'    => false,
				'supports'        => array( 'title', 'editor', 'author' ),
				'show_in_rest'    => true,
			)
		);
	}

	/**
	 * Add admin columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function add_admin_columns( $columns ) {
		$date = isset( $columns['date'] ) ? $columns['date'] : null;
		unset( $columns['date'] );

		$columns['activity_type'] = __( 'Type', 'mcp-ai-wpoos-pro' );
		$columns['related']       = __( 'Related', 'mcp-ai-wpoos-pro' );
		$columns['due_date']      = __( 'Due', 'mcp-ai-wpoos-pro' );
		$columns['disposition']   = __( 'Disposition', 'mcp-ai-wpoos-pro' );

		if ( $date ) {
			$columns['date'] = $date;
		}
		return $columns;
	}

	/**
	 * Render admin column values.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function render_admin_columns( $column, $post_id ) {
		switch ( $column ) {
			case 'activity_type':
				$dashicons = array(
					'call'    => 'dashicons-phone',
					'email'   => 'dashicons-email',
					'meeting' => 'dashicons-calendar',
					'task'    => 'dashicons-yes',
					'note'    => 'dashicons-edit',
				);
				$type      = get_post_meta( $post_id, 'activity_type', true ) ?: 'task';
				$icon      = isset( $dashicons[ $type ] ) ? $dashicons[ $type ] : 'dashicons-yes';
				echo '<span class="dashicons ' . esc_attr( $icon ) . '" style="vertical-align: middle; margin-right: 4px;"></span> ';
				echo esc_html( ucfirst( $type ) );
				break;
			case 'related':
				$related_type = get_post_meta( $post_id, 'related_type', true );
				$related_id   = (int) get_post_meta( $post_id, 'related_id', true );
				if ( $related_id ) {
					$title = get_the_title( $related_id );
					echo esc_html( $title ?: ( $related_type . ' #' . $related_id ) );
				} else {
					echo '—';
				}
				break;
			case 'due_date':
				$due = get_post_meta( $post_id, 'due_date', true );
				echo esc_html( $due ?: '—' );
				break;
			case 'disposition':
				echo esc_html( get_post_meta( $post_id, 'disposition', true ) ?: '—' );
				break;
		}
	}
}
