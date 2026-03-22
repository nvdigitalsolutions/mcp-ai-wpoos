<?php
/**
 * Embedded LLM Model AJAX Handlers.
 *
 * Registers WordPress AJAX actions for server-side embedded model management
 * (download, delete, list).  Requires the Pro addon (WP_MCP_AI_PRO_VERSION).
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Embedded_Model_Ajax' ) ) {
	/**
	 * AJAX handler class for server-side embedded GGUF model management.
	 */
	class WP_MCP_AI_Embedded_Model_Ajax {

		/**
		 * Register all AJAX actions.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'wp_ajax_wp_mcp_ai_download_embedded_model', array( __CLASS__, 'download_model' ) );
			add_action( 'wp_ajax_wp_mcp_ai_delete_embedded_model', array( __CLASS__, 'delete_model' ) );
			add_action( 'wp_ajax_wp_mcp_ai_list_embedded_models', array( __CLASS__, 'list_models' ) );
		}

		// -------------------------------------------------------------------------
		// AJAX handlers
		// -------------------------------------------------------------------------

		/**
		 * AJAX: download a GGUF model from Hugging Face.
		 *
		 * Expected POST fields:
		 *  - nonce  (string) Nonce created with wp_create_nonce('wp_mcp_ai_embedded_models').
		 *  - model  (string) Model slug (key from WP_MCP_AI_Embedded_Client::get_available_models()).
		 *
		 * @return void Sends JSON response and exits.
		 */
		public static function download_model() {
			self::verify_request();

			$raw        = isset( $_POST['model'] ) ? wp_unslash( $_POST['model'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in sanitize_model_slug()
			$model_slug = self::sanitize_model_slug( $raw );

			if ( empty( $model_slug ) ) {
				wp_send_json_error( __( 'Model slug is required.', 'mcp-ai-wpoos' ) );
			}

			$client = new WP_MCP_AI_Embedded_Client();
			$result = $client->download_model( $model_slug );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( $result->get_error_message() );
			}

			wp_send_json_success( $result );
		}

		/**
		 * AJAX: delete a downloaded GGUF model.
		 *
		 * Expected POST fields:
		 *  - nonce  (string) Nonce created with wp_create_nonce('wp_mcp_ai_embedded_models').
		 *  - model  (string) Model slug.
		 *
		 * @return void Sends JSON response and exits.
		 */
		public static function delete_model() {
			self::verify_request();

			$raw        = isset( $_POST['model'] ) ? wp_unslash( $_POST['model'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in sanitize_model_slug()
			$model_slug = self::sanitize_model_slug( $raw );

			if ( empty( $model_slug ) ) {
				wp_send_json_error( __( 'Model slug is required.', 'mcp-ai-wpoos' ) );
			}

			$client = new WP_MCP_AI_Embedded_Client();
			$result = $client->delete_model( $model_slug );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( $result->get_error_message() );
			}

			wp_send_json_success( $result );
		}

		/**
		 * AJAX: list available and downloaded GGUF models.
		 *
		 * Expected POST fields:
		 *  - nonce  (string) Nonce created with wp_create_nonce('wp_mcp_ai_embedded_models').
		 *
		 * @return void Sends JSON response and exits.
		 */
		public static function list_models() {
			self::verify_request();

			$client     = new WP_MCP_AI_Embedded_Client();
			$available  = $client->get_available_models();
			$downloaded = $client->get_downloaded_models();

			$models = array();
			foreach ( $available as $slug => $model ) {
				$is_downloaded   = isset( $downloaded[ $slug ] );
				$models[ $slug ] = array_merge(
					$model,
					array(
						'slug'          => $slug,
						'is_downloaded' => $is_downloaded,
						'file_size'     => $is_downloaded ? $downloaded[ $slug ]['file_size'] : 0,
					)
				);
			}

			wp_send_json_success( array( 'models' => $models ) );
		}

		// -------------------------------------------------------------------------
		// Private helper
		// -------------------------------------------------------------------------

		/**
		 * Sanitize a raw model slug from user input.
		 *
		 * Model slugs may contain dots (e.g. "qwen2-0.5b-instruct-q4_k_m").
		 * sanitize_key() strips dots, so we use a custom pattern that keeps
		 * only the characters that legitimately appear in GGUF slugs.
		 * The returned slug is validated against the known-models catalogue
		 * by the caller, so this function only needs to exclude dangerous chars.
		 *
		 * @param string $raw Raw value from user input (already wp_unslash()ed by caller).
		 * @return string Sanitized slug (may be empty string if input was empty).
		 */
		private static function sanitize_model_slug( $raw ) {
			return preg_replace( '/[^a-z0-9._-]/', '', strtolower( $raw ) );
		}

		/**
		 * Verify nonce and capability.  Sends JSON error and exits on failure.
		 *
		 * @return void
		 */
		private static function verify_request() {
			check_ajax_referer( 'wp_mcp_ai_embedded_models', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( __( 'You do not have permission to manage embedded models.', 'mcp-ai-wpoos' ) );
			}

			// Pro-only feature guard.
			if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
				wp_send_json_error( __( 'Server-side embedded LLM is a Pro-only feature.', 'mcp-ai-wpoos' ) );
			}
		}
	}
}

// Register AJAX actions immediately.
WP_MCP_AI_Embedded_Model_Ajax::init();
