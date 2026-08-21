<?php
/**
 * OKF Enrichment Admin AJAX (Pro).
 *
 * Handles the `wp_mcp_ai_okf_bundle_enrich` action posted from the Base
 * Bundle Manager admin page's gated enrichment form. Reuses the Base page
 * nonce so the form needs no extra nonce fields.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.1.62
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Enrichment admin AJAX wiring.
 *
 * @since 1.1.62
 */
class WP_MCP_AI_OKF_Enrichment_Admin {

	/**
	 * Nonce action shared with the Base Bundle Manager admin page.
	 *
	 * @since 1.1.62
	 * @var string
	 */
	const NONCE_ACTION = 'wp_mcp_ai_okf_bundle_manager';

	/**
	 * Register the AJAX handler.
	 *
	 * @since 1.1.62
	 * @return void
	 */
	public static function init() {
		add_action( 'wp_ajax_wp_mcp_ai_okf_bundle_enrich', array( __CLASS__, 'handle_enrich' ) );
	}

	/**
	 * Handle the enrichment request.
	 *
	 * @return void
	 */
	public static function handle_enrich() {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid nonce.', 'mcp-ai-wpoos-pro' ) ), 403 );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos-pro' ) ), 403 );
		}

		if ( ! class_exists( 'WP_MCP_AI_OKF_Enrichment_Agent' ) ) {
			wp_send_json_error( array( 'message' => __( 'The OKF enrichment agent is not available.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Nonce verified above; every value is sanitized individually below.
		$args = array(
			'bundle'          => isset( $_POST['bundle'] ) ? sanitize_text_field( wp_unslash( $_POST['bundle'] ) ) : 'site-content',
			'limit'           => isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 50,
			'include_terms'   => ! empty( $_POST['include_terms'] ),
			'include_content' => empty( $_POST['omit_content'] ),
		);

		$post_types = isset( $_POST['post_types'] ) && is_array( $_POST['post_types'] ) ? wp_unslash( $_POST['post_types'] ) : array();
		if ( ! empty( $post_types ) ) {
			$args['post_types'] = array_map( 'sanitize_key', $post_types );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$agent  = new WP_MCP_AI_OKF_Enrichment_Agent();
		$result = $agent->enrich( $args );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: bundle name, 2: concept count */
					__( 'Enriched bundle "%1$s" with %2$d concepts.', 'mcp-ai-wpoos-pro' ),
					$result['bundle'],
					$result['concepts']
				),
			)
		);
	}
}
