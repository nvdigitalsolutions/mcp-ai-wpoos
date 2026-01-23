<?php
/**
 * AJAX handlers for embedded model management.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Embedded_Model_Ajax' ) ) {
	/**
	 * Handles AJAX requests for embedded model management (Pro-only).
	 */
	class WP_MCP_AI_Embedded_Model_Ajax {

		/**
		 * Initialize AJAX handlers.
		 */
		public static function init() {
			// Only register handlers for Pro version.
			if ( defined( 'WP_MCP_AI_BASE_VERSION' ) && WP_MCP_AI_BASE_VERSION ) {
				return;
			}

			add_action( 'wp_ajax_wp_mcp_ai_download_embedded_model', array( __CLASS__, 'download_model' ) );
			add_action( 'wp_ajax_wp_mcp_ai_delete_embedded_model', array( __CLASS__, 'delete_model' ) );
			add_action( 'wp_ajax_wp_mcp_ai_list_embedded_models', array( __CLASS__, 'list_models' ) );
		}

		/**
		 * Download an embedded model.
		 */
		public static function download_model() {
			// Check nonce.
			check_ajax_referer( 'wp_mcp_ai_embedded_model_management', 'nonce' );

			// Check user capability.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) );
			}

			// Check if embedded client is available.
			if ( ! class_exists( 'WP_MCP_AI_Embedded_Client' ) ) {
				wp_send_json_error( __( 'Embedded LLM feature is not available.', 'mcp-ai-wpoos' ) );
			}

			// Get model slug.
			$model_slug = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';

			if ( empty( $model_slug ) ) {
				wp_send_json_error( __( 'Model slug is required.', 'mcp-ai-wpoos' ) );
			}

			// Download model.
			$client = new WP_MCP_AI_Embedded_Client();
			$result = $client->download_model( $model_slug );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( $result->get_error_message() );
			}

			wp_send_json_success( $result );
		}

		/**
		 * Delete an embedded model.
		 */
		public static function delete_model() {
			// Check nonce.
			check_ajax_referer( 'wp_mcp_ai_embedded_model_management', 'nonce' );

			// Check user capability.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) );
			}

			// Check if embedded client is available.
			if ( ! class_exists( 'WP_MCP_AI_Embedded_Client' ) ) {
				wp_send_json_error( __( 'Embedded LLM feature is not available.', 'mcp-ai-wpoos' ) );
			}

			// Get model slug.
			$model_slug = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';

			if ( empty( $model_slug ) ) {
				wp_send_json_error( __( 'Model slug is required.', 'mcp-ai-wpoos' ) );
			}

			// Delete model.
			$client = new WP_MCP_AI_Embedded_Client();
			$result = $client->delete_model( $model_slug );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( $result->get_error_message() );
			}

			wp_send_json_success( $result );
		}

		/**
		 * List embedded models.
		 */
		public static function list_models() {
			// Check nonce.
			check_ajax_referer( 'wp_mcp_ai_embedded_model_management', 'nonce' );

			// Check user capability.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) );
			}

			// Check if embedded client is available.
			if ( ! class_exists( 'WP_MCP_AI_Embedded_Client' ) ) {
				wp_send_json_error( __( 'Embedded LLM feature is not available.', 'mcp-ai-wpoos' ) );
			}

			// Get models.
			$client            = new WP_MCP_AI_Embedded_Client();
			$available_models  = $client->get_available_models();
			$downloaded_models = $client->get_downloaded_models();

			wp_send_json_success(
				array(
					'available'  => $available_models,
					'downloaded' => $downloaded_models,
				)
			);
		}
	}
}
