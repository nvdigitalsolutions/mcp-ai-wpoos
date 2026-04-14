<?php
/**
 * Law Firm Toolkit Research & Add Page
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WP_MCP_AI_Law_Firm_Research_Page {

	const PAGE_SLUG = 'research-law-firm';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_lf_matter_from_research', array( __CLASS__, 'handle_create_matter' ) );
		add_action( 'wp_ajax_wp_mcp_ai_create_lf_client_from_research', array( __CLASS__, 'handle_create_client' ) );
	}

	public static function add_menu_page() {
		add_submenu_page(
			'edit.php?post_type=mcp_ai_lf_matter',
			__( 'Research & Add', 'mcp-ai-wpoos-pro' ),
			__( 'Research & Add', 'mcp-ai-wpoos-pro' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	public static function enqueue_assets( $hook ) {
		if ( 'mcp_ai_lf_matter_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'wp-mcp-ai-admin' );
		wp_enqueue_script( 'wp-mcp-ai-admin' );
	}

	public static function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Law Firm Research & Add', 'mcp-ai-wpoos-pro' ) . '</h1>';
		echo '<p>' . esc_html__( 'Use AI to research legal matters, case law, and add new matters or clients.', 'mcp-ai-wpoos-pro' ) . '</p>';
		$settings = get_option( 'wp_mcp_ai_law_firm_settings', array() );
		$assistant_id = $settings['research_assistant_id'] ?? '';
		if ( $assistant_id && shortcode_exists( 'mcp_ai_chat' ) ) {
			echo wp_kses_post( do_shortcode( '[mcp_ai_chat assistant_id="' . absint( $assistant_id ) . '"]' ) );
		} else {
			echo '<div class="notice notice-info"><p>' . esc_html__( 'Configure a Research Assistant in the Settings tab to enable AI-powered research.', 'mcp-ai-wpoos-pro' ) . '</p></div>';
		}
		echo '</div>';
	}

	public static function handle_create_matter() {
		check_ajax_referer( 'wp_mcp_ai_research_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		$title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		if ( empty( $title ) ) {
			wp_send_json_error( __( 'Title is required.', 'mcp-ai-wpoos-pro' ) );
		}
		$post_id = wp_insert_post( array(
			'post_title'  => $title,
			'post_type'   => 'mcp_ai_lf_matter',
			'post_status' => 'publish',
		) );
		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( $post_id->get_error_message() );
		}
		wp_send_json_success( array( 'post_id' => $post_id, 'edit_url' => get_edit_post_link( $post_id, 'raw' ) ) );
	}

	public static function handle_create_client() {
		check_ajax_referer( 'wp_mcp_ai_research_nonce', 'nonce' );
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		$title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
		if ( empty( $title ) ) {
			wp_send_json_error( __( 'Title is required.', 'mcp-ai-wpoos-pro' ) );
		}
		$post_id = wp_insert_post( array(
			'post_title'  => $title,
			'post_type'   => 'mcp_ai_lf_client',
			'post_status' => 'publish',
		) );
		if ( is_wp_error( $post_id ) ) {
			wp_send_json_error( $post_id->get_error_message() );
		}
		wp_send_json_success( array( 'post_id' => $post_id, 'edit_url' => get_edit_post_link( $post_id, 'raw' ) ) );
	}
}
