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
				if ( ! $stage ) {
					$stage = get_post_meta( $post_id, '_deal_stage', true );
				}
				if ( $stage && class_exists( 'WP_MCP_AI_CRM_Pipeline_Stages' ) ) {
					$st = WP_MCP_AI_CRM_Pipeline_Stages::get_stage( $stage );
					echo esc_html( $st ? $st['label'] : $stage );
				} else {
					echo esc_html( $stage ? $stage : 'prospecting' );
				}
				break;
			case 'amount':
				$amount = get_post_meta( $post_id, 'deal_amount', true );
				if ( ! $amount ) {
					$amount = get_post_meta( $post_id, '_deal_amount', true );
				}
				echo esc_html( $amount ? WP_MCP_AI_CRM_Engine::format_currency( (float) $amount ) : '—' );
				break;
			case 'probability':
				$prob = get_post_meta( $post_id, 'deal_probability', true );
				if ( ! $prob ) {
					$prob = get_post_meta( $post_id, '_deal_probability', true );
				}
				echo esc_html( is_numeric( $prob ) ? round( (float) $prob * 100 ) . '%' : '—' );
				break;
			case 'close_date':
				$close = get_post_meta( $post_id, 'close_date', true );
				if ( ! $close ) {
					$close = get_post_meta( $post_id, '_deal_close_date', true );
				}
				echo esc_html( $close ? $close : '—' );
				break;
			case 'owner':
				$owner = get_post_meta( $post_id, 'deal_owner', true );
				if ( ! $owner ) {
					$owner = get_post_meta( $post_id, '_deal_owner', true );
				}
				if ( $owner ) {
					$user = get_userdata( (int) $owner );
					echo esc_html( $user ? $user->display_name : $owner );
				} else {
					echo '—';
				}
				break;
		}
	}

	/**
	 * Create a deal from structured data.
	 *
	 * Used by Upwork/LinkedIn importers and programmatic deal creation.
	 *
	 * @since 2.11.0
	 *
	 * @param array $data {
	 *     Deal creation data.
	 *
	 *     @type string $name   Deal title (required).
	 *     @type float  $value  Deal monetary value.
	 *     @type string $stage  Pipeline stage key (default: qualification).
	 *     @type string $source Source identifier (e.g. 'upwork', 'linkedin').
	 *     @type string $notes  Deal description / notes.
	 * }
	 * @return int|WP_Error Post ID on success, WP_Error on failure.
	 */
	public static function create( array $data ) {
		$name = isset( $data['name'] ) ? sanitize_text_field( $data['name'] ) : '';
		if ( '' === $name ) {
			return new WP_Error(
				'wp_mcp_ai_deal_missing_name',
				__( 'Deal name is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$value       = isset( $data['value'] ) ? (float) $data['value'] : 0;
		$stage       = isset( $data['stage'] ) ? sanitize_key( $data['stage'] ) : 'qualification';
		$source      = isset( $data['source'] ) ? sanitize_text_field( $data['source'] ) : '';
		$notes       = isset( $data['notes'] ) ? sanitize_textarea_field( $data['notes'] ) : '';
		$probability = isset( $data['probability'] ) ? (float) $data['probability'] : 0;

		// Resolve default stage probability from pipeline config if available.
		if ( 0 === $probability && class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			$settings = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
			$stages   = isset( $settings['pipeline']['stages'] ) ? $settings['pipeline']['stages'] : array();
			if ( isset( $stages[ $stage ]['probability'] ) ) {
				$probability = (float) $stages[ $stage ]['probability'];
			}
		}

		$post_data = array(
			'post_type'    => self::POST_TYPE,
			'post_title'   => $name,
			'post_content' => $notes,
			'post_status'  => 'publish',
			'meta_input'   => array(
				'_deal_stage'       => $stage,
				'deal_stage'        => $stage,
				'_deal_amount'      => $value,
				'deal_amount'       => $value,
				'_deal_probability' => $probability,
				'deal_probability'  => $probability,
				'_deal_source'      => $source,
				'deal_source'       => $source,
			),
		);

		$post_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		/**
		 * Fires after a deal is created via the programmatic create() method.
		 *
		 * @since 2.11.0
		 *
		 * @param int   $post_id The deal post ID.
		 * @param array $data    The original creation data.
		 */
		do_action( 'wp_mcp_ai_deal_created', $post_id, $data );

		return $post_id;
	}
}
