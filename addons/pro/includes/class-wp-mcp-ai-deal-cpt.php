<?php
/**
 * Deal / Opportunity Custom Post Type for CRM pipeline management.
 *
 * Registers `mcp_ai_deal` — an opportunity record in the sales pipeline
 * with stage, amount, close date, probability, contact/lead ownership,
 * and notes.
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
 * Deal CPT.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Deal_CPT {

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_deal';

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
	 * Register the deal post type.
	 *
	 * @since 2.3.0
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'               => _x( 'Deals', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name'      => _x( 'Deal', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'menu_name'          => _x( 'Deals', 'admin menu', 'mcp-ai-wpoos-pro' ),
					'add_new'            => _x( 'Add New', 'deal', 'mcp-ai-wpoos-pro' ),
					'add_new_item'       => __( 'Add New Deal', 'mcp-ai-wpoos-pro' ),
					'edit_item'          => __( 'Edit Deal', 'mcp-ai-wpoos-pro' ),
					'all_items'          => __( 'All Deals', 'mcp-ai-wpoos-pro' ),
					'search_items'       => __( 'Search Deals', 'mcp-ai-wpoos-pro' ),
					'not_found'          => __( 'No deals found.', 'mcp-ai-wpoos-pro' ),
					'not_found_in_trash' => __( 'No deals found in Trash.', 'mcp-ai-wpoos-pro' ),
				),
				'description'     => __( 'CRM pipeline opportunity records.', 'mcp-ai-wpoos-pro' ),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_icon'       => 'dashicons-chart-area',
				'menu_position'   => 56,
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

		$columns['stage']       = __( 'Stage', 'mcp-ai-wpoos-pro' );
		$columns['amount']      = __( 'Amount', 'mcp-ai-wpoos-pro' );
		$columns['probability'] = __( 'Prob %', 'mcp-ai-wpoos-pro' );
		$columns['close_date']  = __( 'Close Date', 'mcp-ai-wpoos-pro' );
		$columns['owner']       = __( 'Owner', 'mcp-ai-wpoos-pro' );

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
			case 'stage':
				$stage = get_post_meta( $post_id, 'deal_stage', true );
				if ( $stage && class_exists( 'WP_MCP_AI_CRM_Pipeline_Stages' ) ) {
					$st = WP_MCP_AI_CRM_Pipeline_Stages::get_stage( $stage );
					echo esc_html( $st ? $st['label'] : $stage );
				} else {
					echo esc_html( $stage ?: 'prospecting' );
				}
				break;
			case 'amount':
				$amount = get_post_meta( $post_id, 'deal_amount', true );
				echo esc_html( $amount ? WP_MCP_AI_CRM_Engine::format_currency( (float) $amount ) : '—' );
				break;
			case 'probability':
				$prob = get_post_meta( $post_id, 'deal_probability', true );
				echo esc_html( is_numeric( $prob ) ? round( (float) $prob * 100 ) . '%' : '—' );
				break;
			case 'close_date':
				$close = get_post_meta( $post_id, 'close_date', true );
				echo esc_html( $close ?: '—' );
				break;
			case 'owner':
				$owner = get_post_meta( $post_id, 'deal_owner', true );
				if ( $owner ) {
					$user = get_userdata( (int) $owner );
					echo esc_html( $user ? $user->display_name : $owner );
				} else {
					echo '—';
				}
				break;
		}
	}
}
