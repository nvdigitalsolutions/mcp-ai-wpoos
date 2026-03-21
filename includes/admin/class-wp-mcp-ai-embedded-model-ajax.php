<?php
/**
 * Embedded Model AJAX Handlers
 *
 * Provides admin AJAX endpoints for managing server-side GGUF model files:
 *   - wp_mcp_ai_list_embedded_models   — list all available models with status
 *   - wp_mcp_ai_download_embedded_model — download a model from Hugging Face
 *   - wp_mcp_ai_delete_embedded_model  — delete a downloaded model file
 *   - wp_mcp_ai_test_embedded_server   — verify binary + model are working
 *
 * All actions require the manage_options capability and a valid nonce.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Embedded_Model_Ajax' ) ) {

	/**
	 * Static class that registers and handles AJAX actions for model management.
	 */
	class WP_MCP_AI_Embedded_Model_Ajax {

		/**
		 * Register all WordPress AJAX hooks.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'wp_ajax_wp_mcp_ai_list_embedded_models',    array( __CLASS__, 'list_models' ) );
			add_action( 'wp_ajax_wp_mcp_ai_download_embedded_model', array( __CLASS__, 'download_model' ) );
			add_action( 'wp_ajax_wp_mcp_ai_delete_embedded_model',   array( __CLASS__, 'delete_model' ) );
			add_action( 'wp_ajax_wp_mcp_ai_test_embedded_server',    array( __CLASS__, 'test_server' ) );
		}

		/**
		 * Get the shared embedded client instance.
		 *
		 * Uses the DI container when available so that the same singleton is
		 * reused across all AJAX actions within a single request.
		 *
		 * @return WP_MCP_AI_Embedded_Client
		 */
		private static function get_client() {
			if ( function_exists( 'wp_mcp_ai_container' ) ) {
				$container = wp_mcp_ai_container();
				if ( $container && $container->has( 'client.embedded' ) ) {
					return $container->get( 'client.embedded' );
				}
			}
			return new WP_MCP_AI_Embedded_Client();
		}

		/**
		 * Return the list of available models with their current download status.
		 *
		 * Expected POST fields: nonce
		 *
		 * @return void Sends JSON response.
		 */
		public static function list_models() {
			check_ajax_referer( 'wp_mcp_ai_embedded_models', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
			}

			$client = self::get_client();
			$models = $client->get_available_models();

			wp_send_json_success(
				array(
					'models'    => array_values( $models ),
					'available' => $client->is_available(),
					'binary'    => ! is_wp_error( $client->get_binary_path() ),
					'proc_open' => function_exists( 'proc_open' ),
					'models_dir' => ! is_wp_error( $client->get_models_directory() )
						? $client->get_models_directory()
						: '',
				)
			);
		}

		/**
		 * Download a model file from Hugging Face.
		 *
		 * Expected POST fields: nonce, slug
		 *
		 * @return void Sends JSON response.
		 */
		public static function download_model() {
			check_ajax_referer( 'wp_mcp_ai_embedded_models', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
			}

			$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';

			if ( empty( $slug ) ) {
				wp_send_json_error( array( 'message' => __( 'Model slug is required.', 'mcp-ai-wpoos' ) ) );
			}

			$client = self::get_client();
			$result = $client->download_model( $slug );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			wp_send_json_success( $result );
		}

		/**
		 * Delete a downloaded model file.
		 *
		 * Expected POST fields: nonce, slug
		 *
		 * @return void Sends JSON response.
		 */
		public static function delete_model() {
			check_ajax_referer( 'wp_mcp_ai_embedded_models', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
			}

			$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';

			if ( empty( $slug ) ) {
				wp_send_json_error( array( 'message' => __( 'Model slug is required.', 'mcp-ai-wpoos' ) ) );
			}

			$client = self::get_client();
			$result = $client->delete_model( $slug );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			wp_send_json_success( $result );
		}

		/**
		 * Run a smoke-test of the binary + active model to verify inference works.
		 *
		 * Expected POST fields: nonce
		 *
		 * @return void Sends JSON response.
		 */
		public static function test_server() {
			check_ajax_referer( 'wp_mcp_ai_embedded_models', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
			}

			$client = self::get_client();
			$result = $client->test_connection();

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
			}

			wp_send_json_success( $result );
		}
	}
}

// Register AJAX hooks.
WP_MCP_AI_Embedded_Model_Ajax::init();
