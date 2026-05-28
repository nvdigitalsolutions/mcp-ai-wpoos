<?php
/**
 * Lead Custom Post Type for CRM lead management.
 *
 * Registers `mcp_ai_lead` — a lifecycle-stage entity with BANT/MEDDIC
 * qualification fields, lead score, owner assignment, source tracking,
 * and MQL/SQL stage progression.  Coexists alongside `mcp_crm_contacts`
 * (WP_MCP_AI_CRM_Engine::resolve_lead_id() resolves both).
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
 * Lead CPT.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Lead_CPT {

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_lead';

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
	 * Register the lead post type.
	 *
	 * @since 2.3.0
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'               => _x( 'Leads', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name'      => _x( 'Lead', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'menu_name'          => _x( 'Leads', 'admin menu', 'mcp-ai-wpoos-pro' ),
					'add_new'            => _x( 'Add New', 'lead', 'mcp-ai-wpoos-pro' ),
					'add_new_item'       => __( 'Add New Lead', 'mcp-ai-wpoos-pro' ),
					'edit_item'          => __( 'Edit Lead', 'mcp-ai-wpoos-pro' ),
					'all_items'          => __( 'All Leads', 'mcp-ai-wpoos-pro' ),
					'search_items'       => __( 'Search Leads', 'mcp-ai-wpoos-pro' ),
					'not_found'          => __( 'No leads found.', 'mcp-ai-wpoos-pro' ),
					'not_found_in_trash' => __( 'No leads found in Trash.', 'mcp-ai-wpoos-pro' ),
				),
				'description'     => __( 'CRM lifecycle-stage lead records.', 'mcp-ai-wpoos-pro' ),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_icon'       => 'dashicons-groups',
				'menu_position'   => 55,
				'capability_type' => 'post',
				'has_archive'     => false,
				'hierarchical'    => false,
				'supports'        => array( 'title', 'author' ),
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

		$columns['lead_status']   = __( 'Status', 'mcp-ai-wpoos-pro' );
		$columns['lifecycle']     = __( 'Lifecycle', 'mcp-ai-wpoos-pro' );
		$columns['lead_score']    = __( 'Score', 'mcp-ai-wpoos-pro' );
		$columns['contact_owner'] = __( 'Owner', 'mcp-ai-wpoos-pro' );
		$columns['source']        = __( 'Source', 'mcp-ai-wpoos-pro' );

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
			case 'lead_status':
				echo esc_html( get_post_meta( $post_id, 'lead_status', true ) ?: 'new' );
				break;
			case 'lifecycle':
				echo esc_html( get_post_meta( $post_id, 'lifecycle_stage', true ) ?: 'lead' );
				break;
			case 'lead_score':
				$score = get_post_meta( $post_id, 'lead_score', true );
				echo esc_html( is_numeric( $score ) ? (int) $score : '0' );
				break;
			case 'contact_owner':
				$owner = get_post_meta( $post_id, 'contact_owner', true );
				if ( $owner ) {
					$user = get_userdata( (int) $owner );
					echo esc_html( $user ? $user->display_name : $owner );
				} else {
					echo '—';
				}
				break;
			case 'source':
				echo esc_html( get_post_meta( $post_id, 'source', true ) ?: '—' );
				break;
		}
	}
}
