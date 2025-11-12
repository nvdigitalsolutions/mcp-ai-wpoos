<?php
/**
 * Admin AJAX Handlers for WP oOS.
 *
 * Handles all AJAX requests for the admin settings page.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Admin_AJAX_Handlers' ) ) {
	/**
	 * Handles AJAX requests for admin settings.
	 */
	class WP_MCP_AI_Admin_AJAX_Handlers {

		/**
		 * Clean all output buffers safely.
		 *
		 * Includes safety measures to prevent infinite loops.
		 */
		private function clean_all_buffers() {
			$max_iterations = 100; // Safety limit.
			$iterations     = 0;

			while ( ob_get_level() > 0 && $iterations < $max_iterations ) {
				if ( ! ob_end_clean() ) {
					break; // If ob_end_clean fails, stop trying.
				}
				++$iterations;
			}
		}

		/**
		 * Safe AJAX handler wrapper that catches any output before JSON responses.
		 *
		 * Prevents PHP errors, warnings, and notices from breaking JSON responses.
		 *
		 * Note: Accepts variable parameters for compatibility with WordPress action hooks,
		 * but does not use them. WordPress's do_action() may pass parameters to callbacks.
		 */
		public function safe_ajax_handler( ...$args ) {
			// Clean any previous output.
			$this->clean_all_buffers();

			// Map action to handler method.
			$action_map = array(
				'wp_ajax_wp_mcp_ai_test_ollama_connection' => 'handle_test_ollama_connection',
				'wp_ajax_wp_mcp_ai_fetch_ollama_models'    => 'handle_fetch_ollama_models',
				'wp_ajax_wp_mcp_ai_test_lm_studio_connection' => 'handle_test_lm_studio_connection',
				'wp_ajax_wp_mcp_ai_fetch_lm_studio_models' => 'handle_fetch_lm_studio_models',
				'wp_ajax_wp_mcp_ai_fetch_cloudways_data'   => 'handle_fetch_cloudways_data',
				'wp_ajax_wp_mcp_ai_test_cloudflare_connection' => 'handle_test_cloudflare_connection',
				'wp_ajax_wp_mcp_ai_reset_user_token_usage' => 'handle_reset_user_token_usage',
				'wp_ajax_wp_mcp_ai_reset_all_token_usage'  => 'handle_reset_all_token_usage',
				'wp_ajax_wp_mcp_ai_save_tool_limits'       => 'handle_save_tool_limits',
				'wp_ajax_wp_mcp_ai_apply_orchestration_preset' => 'handle_apply_orchestration_preset',
				'wp_ajax_wp_mcp_ai_export_token_usage_csv' => 'handle_export_token_usage_csv',
				'wp_ajax_wp_mcp_ai_bulk_assign_tier'       => 'handle_bulk_assign_tier',
				'wp_ajax_wp_mcp_ai_apply_all_recommendations' => 'handle_apply_all_recommendations',
				'wp_ajax_wp_mcp_ai_apply_preset'           => 'handle_apply_preset',
				'wp_ajax_wp_mcp_ai_get_usage_trend'        => 'handle_get_usage_trend',
				'wp_ajax_wp_mcp_ai_get_tier_distribution'  => 'handle_get_tier_distribution',
			);

			$action         = current_action();
			$handler_method = isset( $action_map[ $action ] ) ? $action_map[ $action ] : '';

			if ( ! $handler_method || ! method_exists( $this, $handler_method ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid action.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Call the actual handler.
			// Catch both Exception and Error (PHP 7+) for comprehensive error handling.
			try {
				call_user_func( array( $this, $handler_method ) );
			} catch ( \Throwable $e ) {
				// Clean any output from the exception/error.
				$this->clean_all_buffers();
				wp_send_json_error(
					array(
						'message' => $e->getMessage(),
						'code'    => $e->getCode(),
					)
				);
			}

			// Note: If handler succeeded, wp_send_json_*() already called wp_die() and execution stopped.
			// If we reach here, something went wrong - the handler didn't send a response.
		}

		/**
		 * Handle AJAX request to test Ollama connection.
		 */
		public function handle_test_ollama_connection() {
			check_ajax_referer( 'wp-mcp-ai-settings', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
				return;
			}

			$endpoint_url = isset( $_POST['endpoint_url'] ) ? esc_url_raw( wp_unslash( $_POST['endpoint_url'] ) ) : '';

			if ( empty( $endpoint_url ) ) {
				wp_send_json_error( array( 'message' => __( 'Please provide an endpoint URL.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Get timeout from settings.
			$settings     = WP_MCP_AI_Admin_Settings::get_settings();
			$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
			$timeout      = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : $resource_mgr->get_request_timeout();
			$timeout      = max( 5, $timeout );

			$api_url  = trailingslashit( $endpoint_url ) . 'api/tags';
			$response = wp_remote_get( $api_url, array( 'timeout' => $timeout ) );

			if ( is_wp_error( $response ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: error message */
							__( 'Connection failed: %s', 'wp-mcp-ai' ),
							$response->get_error_message()
						),
					)
				);
				return;
			}

			$response_code = wp_remote_retrieve_response_code( $response );

			if ( 200 !== $response_code ) {
				wp_send_json_error( array( 'message' => __( 'Invalid endpoint or connection failed.', 'wp-mcp-ai' ) ) );
				return;
			}

			wp_send_json_success( array( 'message' => __( 'Successfully connected to Ollama!', 'wp-mcp-ai' ) ) );
		}

		/**
		 * Handle AJAX request to fetch Ollama models.
		 */
		public function handle_fetch_ollama_models() {
			check_ajax_referer( 'wp-mcp-ai-settings', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
				return;
			}

			$endpoint_url = isset( $_POST['endpoint_url'] ) ? esc_url_raw( wp_unslash( $_POST['endpoint_url'] ) ) : '';

			if ( empty( $endpoint_url ) ) {
				wp_send_json_error( array( 'message' => __( 'Please provide an endpoint URL.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Get timeout from settings.
			$settings     = WP_MCP_AI_Admin_Settings::get_settings();
			$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
			$timeout      = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : $resource_mgr->get_request_timeout();
			$timeout      = max( 5, $timeout );

			$api_url  = trailingslashit( $endpoint_url ) . 'api/tags';
			$response = wp_remote_get( $api_url, array( 'timeout' => $timeout ) );

			if ( is_wp_error( $response ) ) {
				wp_send_json_error( array( 'message' => __( 'Failed to fetch models.', 'wp-mcp-ai' ) ) );
				return;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( ! isset( $data['models'] ) || ! is_array( $data['models'] ) ) {
				wp_send_json_error( array( 'message' => __( 'No models found or invalid response.', 'wp-mcp-ai' ) ) );
				return;
			}

			$models = array();
			foreach ( $data['models'] as $model ) {
				if ( isset( $model['name'] ) ) {
					$models[] = $model['name'];
				}
			}

			wp_send_json_success( array( 'models' => $models ) );
		}

		/**
		 * Handle AJAX request to test LM Studio connection.
		 */
		public function handle_test_lm_studio_connection() {
			check_ajax_referer( 'wp-mcp-ai-settings', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
				return;
			}

			$endpoint_url = isset( $_POST['endpoint_url'] ) ? esc_url_raw( wp_unslash( $_POST['endpoint_url'] ) ) : '';

			if ( empty( $endpoint_url ) ) {
				wp_send_json_error( array( 'message' => __( 'Please provide an endpoint URL.', 'wp-mcp-ai' ) ) );
				return;
			}

			$api_url  = trailingslashit( $endpoint_url ) . 'v1/models';
			$response = wp_remote_get( $api_url, array( 'timeout' => 10 ) );

			if ( is_wp_error( $response ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: error message */
							__( 'Connection failed: %s', 'wp-mcp-ai' ),
							$response->get_error_message()
						),
					)
				);
				return;
			}

			$response_code = wp_remote_retrieve_response_code( $response );

			if ( 200 !== $response_code ) {
				wp_send_json_error( array( 'message' => __( 'Invalid endpoint or connection failed.', 'wp-mcp-ai' ) ) );
				return;
			}

			wp_send_json_success( array( 'message' => __( 'Successfully connected to LM Studio!', 'wp-mcp-ai' ) ) );
		}

		/**
		 * Handle AJAX request to fetch LM Studio models.
		 */
		public function handle_fetch_lm_studio_models() {
			check_ajax_referer( 'wp-mcp-ai-settings', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
				return;
			}

			$endpoint_url = isset( $_POST['endpoint_url'] ) ? esc_url_raw( wp_unslash( $_POST['endpoint_url'] ) ) : '';

			if ( empty( $endpoint_url ) ) {
				wp_send_json_error( array( 'message' => __( 'Please provide an endpoint URL.', 'wp-mcp-ai' ) ) );
				return;
			}

			$api_url  = trailingslashit( $endpoint_url ) . 'v1/models';
			$response = wp_remote_get( $api_url, array( 'timeout' => 10 ) );

			if ( is_wp_error( $response ) ) {
				wp_send_json_error( array( 'message' => __( 'Failed to fetch models.', 'wp-mcp-ai' ) ) );
				return;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
				wp_send_json_error( array( 'message' => __( 'No models found or invalid response.', 'wp-mcp-ai' ) ) );
				return;
			}

			$models = array();
			foreach ( $data['data'] as $model ) {
				if ( isset( $model['id'] ) ) {
					$models[] = $model['id'];
				}
			}

			wp_send_json_success( array( 'models' => $models ) );
		}

		/**
		 * Handle AJAX request to fetch Cloudways data.
		 */
		public function handle_fetch_cloudways_data() {
			check_ajax_referer( 'wp-mcp-ai-settings', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
				return;
			}

			$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
			$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

			if ( empty( $email ) || empty( $api_key ) ) {
				wp_send_json_error( array( 'message' => __( 'Please provide both email and API key.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Step 1: Get OAuth token.
			$oauth_url      = 'https://api.cloudways.com/api/v1/oauth/access_token';
			$oauth_response = wp_remote_post(
				$oauth_url,
				array(
					'body'    => wp_json_encode(
						array(
							'email'   => $email,
							'api_key' => $api_key,
						)
					),
					'headers' => array(
						'Content-Type' => 'application/json',
					),
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $oauth_response ) ) {
				wp_send_json_error( array( 'message' => __( 'Failed to connect to Cloudways API: ', 'wp-mcp-ai' ) . $oauth_response->get_error_message() ) );
				return;
			}

			$oauth_code = wp_remote_retrieve_response_code( $oauth_response );
			$oauth_data = json_decode( wp_remote_retrieve_body( $oauth_response ), true );

			if ( 200 !== $oauth_code || empty( $oauth_data['access_token'] ) ) {
				$error_message = ! empty( $oauth_data['message'] ) ? $oauth_data['message'] : __( 'Invalid credentials.', 'wp-mcp-ai' );
				wp_send_json_error( array( 'message' => $error_message ) );
				return;
			}

			$access_token = $oauth_data['access_token'];

			// Step 2: Fetch servers.
			$servers_url      = 'https://api.cloudways.com/api/v1/server';
			$servers_response = wp_remote_get(
				$servers_url,
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $access_token,
						'Accept'        => 'application/json',
					),
					'timeout' => 30,
				)
			);

			if ( is_wp_error( $servers_response ) ) {
				wp_send_json_error( array( 'message' => __( 'Failed to fetch servers: ', 'wp-mcp-ai' ) . $servers_response->get_error_message() ) );
				return;
			}

			$servers_code = wp_remote_retrieve_response_code( $servers_response );
			$servers_data = json_decode( wp_remote_retrieve_body( $servers_response ), true );

			if ( 200 !== $servers_code || empty( $servers_data['servers'] ) ) {
				wp_send_json_error( array( 'message' => __( 'No servers found or failed to fetch servers.', 'wp-mcp-ai' ) ) );
				return;
			}

			$servers = array();
			foreach ( $servers_data['servers'] as $server ) {
				// Validate that expected fields exist.
				if ( ! isset( $server['id'] ) || ! isset( $server['label'] ) ) {
					continue;
				}

				$servers[] = array(
					'id'     => sanitize_text_field( $server['id'] ),
					'label'  => sanitize_text_field( $server['label'] ),
					'status' => isset( $server['status'] ) ? sanitize_text_field( $server['status'] ) : 'unknown',
				);
			}

			// Step 3: Fetch applications from the first server.
			$apps = array();
			if ( ! empty( $servers ) ) {
				$first_server_id = $servers[0]['id'];
				$apps_url        = add_query_arg( 'server_id', $first_server_id, 'https://api.cloudways.com/api/v1/apps' );
				$apps_response   = wp_remote_get(
					$apps_url,
					array(
						'headers' => array(
							'Authorization' => 'Bearer ' . $access_token,
							'Accept'        => 'application/json',
						),
						'timeout' => 30,
					)
				);

				if ( ! is_wp_error( $apps_response ) ) {
					$apps_code = wp_remote_retrieve_response_code( $apps_response );
					$apps_data = json_decode( wp_remote_retrieve_body( $apps_response ), true );

					if ( 200 === $apps_code && ! empty( $apps_data['apps'] ) ) {
						foreach ( $apps_data['apps'] as $app ) {
							// Validate that expected fields exist.
							if ( ! isset( $app['id'] ) || ! isset( $app['label'] ) ) {
								continue;
							}

							$apps[] = array(
								'id'        => sanitize_text_field( $app['id'] ),
								'label'     => sanitize_text_field( $app['label'] ),
								'server_id' => $first_server_id,
							);
						}
					}
				}
			}

			wp_send_json_success(
				array(
					'servers' => $servers,
					'apps'    => $apps,
				)
			);
		}

		/**
		 * Handle AJAX request to test Cloudflare connection.
		 */
		public function handle_test_cloudflare_connection() {
			check_ajax_referer( 'wp-mcp-ai-settings', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
				return;
			}

			$zone_id   = isset( $_POST['zone_id'] ) ? sanitize_text_field( wp_unslash( $_POST['zone_id'] ) ) : '';
			$api_token = isset( $_POST['api_token'] ) ? sanitize_text_field( wp_unslash( $_POST['api_token'] ) ) : '';

			if ( empty( $zone_id ) || empty( $api_token ) ) {
				wp_send_json_error( array( 'message' => __( 'Please provide both Zone ID and API Token.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Validate Zone ID format (should be 32 hexadecimal characters).
			if ( ! preg_match( '/^[a-f0-9]{32}$/i', $zone_id ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid Zone ID format. Zone ID should be a 32-character hexadecimal string.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Test the connection by fetching zone details.
			$api_url = 'https://api.cloudflare.com/client/v4/zones/' . sanitize_key( $zone_id );

			$response = wp_remote_get(
				$api_url,
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_token,
						'Content-Type'  => 'application/json',
					),
					'timeout' => 15,
				)
			);

			if ( is_wp_error( $response ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: error message */
							__( 'Connection failed: %s', 'wp-mcp-ai' ),
							$response->get_error_message()
						),
					)
				);
				return;
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
			$data          = json_decode( $response_body, true );

			if ( 200 !== $response_code ) {
				$error_message = __( 'Invalid credentials or zone not found.', 'wp-mcp-ai' );
				if ( isset( $data['errors'][0]['message'] ) ) {
					$error_message = sanitize_text_field( $data['errors'][0]['message'] );
				}
				wp_send_json_error( array( 'message' => $error_message ) );
				return;
			}

			if ( ! isset( $data['success'] ) || ! $data['success'] || ! isset( $data['result'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Unexpected response from Cloudflare API.', 'wp-mcp-ai' ) ) );
				return;
			}

			$zone = $data['result'];

			// Prepare zone information for display.
			$zone_info = array(
				'name'   => isset( $zone['name'] ) ? sanitize_text_field( $zone['name'] ) : '',
				'status' => isset( $zone['status'] ) ? sanitize_text_field( $zone['status'] ) : '',
				'plan'   => isset( $zone['plan']['name'] ) ? sanitize_text_field( $zone['plan']['name'] ) : '',
			);

			wp_send_json_success(
				array(
					'message'   => __( 'Successfully connected to Cloudflare!', 'wp-mcp-ai' ),
					'zone_info' => $zone_info,
				)
			);
		}

		/**
		 * Handle AJAX request to reset user's token usage.
		 */
		public function handle_reset_user_token_usage() {
			// Check capabilities.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Verify nonce.
			if ( ! check_ajax_referer( 'wp_mcp_ai_dashboard', 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Get user ID from request.
			$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : get_current_user_id();

			if ( ! $user_id ) {
				wp_send_json_error( array( 'message' => __( 'Invalid user ID.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Verify user exists.
			if ( ! get_userdata( $user_id ) ) {
				wp_send_json_error( array( 'message' => __( 'User not found.', 'wp-mcp-ai' ) ) );
				return;
			}

			delete_user_meta( $user_id, WP_MCP_AI_Usage_Tracker::USER_META_KEY );

			// Reset tool-specific token usage data.
			WP_MCP_AI_Tool_Token_Limits::reset_user_tool_usage( $user_id );

			wp_send_json_success( array( 'message' => __( 'Token usage data has been reset.', 'wp-mcp-ai' ) ) );
		}

		/**
		 * Handle AJAX request to reset all users' token usage.
		 */
		public function handle_reset_all_token_usage() {
			global $wpdb;

			// Check capabilities.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Verify nonce.
			if ( ! check_ajax_referer( 'wp_mcp_ai_dashboard', 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'wp-mcp-ai' ) ) );
				return;
			}

			$meta_key = WP_MCP_AI_Usage_Tracker::USER_META_KEY;

			// Get all user IDs with usage data before deleting.
			$user_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
					$meta_key
				)
			);

			// Delete all usage data.
			$deleted = $wpdb->delete(
				$wpdb->usermeta,
				array( 'meta_key' => $meta_key ),
				array( '%s' )
			);

			if ( false === $deleted ) {
				wp_send_json_error( array( 'message' => __( 'Failed to reset token usage data.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Clear usermeta cache for all affected users.
			foreach ( $user_ids as $user_id ) {
				clean_user_cache( $user_id );
			}

			// Also delete tool-specific token usage data.
			$tool_meta_key = WP_MCP_AI_Tool_Token_Limits::USAGE_META_KEY;
			$wpdb->delete(
				$wpdb->usermeta,
				array( 'meta_key' => $tool_meta_key ),
				array( '%s' )
			);

			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: %d: number of records deleted */
						__( 'Token usage data has been reset for all users. %d records deleted.', 'wp-mcp-ai' ),
						$deleted
					),
				)
			);
		}

		/**
		 * Handle AJAX request to save tool token limits.
		 */
		public function handle_save_tool_limits() {
			// Check capabilities.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Verify nonce.
			if ( ! check_ajax_referer( 'wp_mcp_ai_dashboard', 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Get limits, multipliers, and model preferences from request.
			$limits            = isset( $_POST['limits'] ) ? (array) $_POST['limits'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$multipliers       = isset( $_POST['multipliers'] ) ? (array) $_POST['multipliers'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$model_preferences = isset( $_POST['model_preferences'] ) ? (array) $_POST['model_preferences'] : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( empty( $limits ) && empty( $multipliers ) && empty( $model_preferences ) ) {
				wp_send_json_error( array( 'message' => __( 'No limits, multipliers, or model preferences provided.', 'wp-mcp-ai' ) ) );
				return;
			}

			$changed_count = 0;

			// Check if any limits have actually changed.
			foreach ( $limits as $tool_slug => $limit ) {
				$tool_slug     = sanitize_key( $tool_slug );
				$limit         = absint( $limit );
				$current_limit = WP_MCP_AI_Tool_Token_Limits::get_tool_limit( $tool_slug );

				if ( '' !== $tool_slug && $current_limit !== $limit ) {
					++$changed_count;
				}
			}

			// Check if any multipliers have changed.
			$current_multipliers = WP_MCP_AI_Tool_Token_Limits::get_tool_multipliers();
			foreach ( $multipliers as $tool_slug => $multiplier ) {
				$tool_slug          = sanitize_key( $tool_slug );
				$multiplier         = (float) $multiplier;
				$current_multiplier = isset( $current_multipliers[ $tool_slug ] ) ? (float) $current_multipliers[ $tool_slug ] : 1.0;

				if ( '' !== $tool_slug && abs( $current_multiplier - $multiplier ) > 0.01 ) {
					++$changed_count;
				}
			}

			// Check if any model preferences have changed.
			$current_preferences = WP_MCP_AI_Tool_Token_Limits::get_tool_model_preferences();
			foreach ( $model_preferences as $tool_slug => $model ) {
				$tool_slug           = sanitize_key( $tool_slug );
				$model               = sanitize_text_field( $model );
				$current_preference  = isset( $current_preferences[ $tool_slug ] ) ? $current_preferences[ $tool_slug ] : 'default';

				if ( '' !== $tool_slug && $current_preference !== $model ) {
					++$changed_count;
				}
			}

			// If no changes detected, notify the user.
			if ( 0 === $changed_count ) {
				wp_send_json_success(
					array(
						'message'    => __( 'No changes detected. All tool settings are already set to the specified values.', 'wp-mcp-ai' ),
						'no_changes' => true,
					)
				);
				return;
			}

			// Sanitize and save each limit.
			$saved_count = 0;
			foreach ( $limits as $tool_slug => $limit ) {
				$tool_slug = sanitize_key( $tool_slug );
				$limit     = absint( $limit );

				if ( '' !== $tool_slug ) {
					if ( WP_MCP_AI_Tool_Token_Limits::set_tool_limit( $tool_slug, $limit ) ) {
						++$saved_count;
					}
				}
			}

			// Save each multiplier.
			foreach ( $multipliers as $tool_slug => $multiplier ) {
				$tool_slug  = sanitize_key( $tool_slug );
				$multiplier = (float) $multiplier;

				if ( '' !== $tool_slug && $multiplier >= 0.1 && $multiplier <= 10 ) {
					if ( WP_MCP_AI_Tool_Token_Limits::set_tool_multiplier( $tool_slug, $multiplier ) ) {
						++$saved_count;
					}
				}
			}

			// Save each model preference.
			foreach ( $model_preferences as $tool_slug => $model ) {
				$tool_slug = sanitize_key( $tool_slug );
				$model     = sanitize_text_field( $model );

				if ( '' !== $tool_slug ) {
					if ( WP_MCP_AI_Tool_Token_Limits::set_tool_model_preference( $tool_slug, $model ) ) {
						++$saved_count;
					}
				}
			}

			if ( $saved_count > 0 ) {
				wp_send_json_success(
					array(
						'message' => sprintf(
							/* translators: %d: number of settings saved */
							__( 'Tool settings saved successfully. %d settings updated.', 'wp-mcp-ai' ),
							$saved_count
						),
					)
				);
			} else {
				wp_send_json_error( array( 'message' => __( 'Failed to save tool settings.', 'wp-mcp-ai' ) ) );
			}
		}

		/**
		 * Handle apply orchestration preset AJAX request.
		 */
		private function handle_apply_orchestration_preset() {
			// Verify nonce.
			check_ajax_referer( 'wp_mcp_ai_dashboard', 'nonce' );

			// Check user permissions.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Get preset ID.
			$preset_id = isset( $_POST['preset_id'] ) ? sanitize_key( $_POST['preset_id'] ) : '';

			if ( empty( $preset_id ) ) {
				wp_send_json_error( array( 'message' => __( 'Missing preset ID.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Check if preset service exists.
			if ( ! class_exists( 'WP_MCP_AI_Orchestration_Preset_Service' ) ) {
				wp_send_json_error( array( 'message' => __( 'Preset service not available.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Apply preset.
			$result = WP_MCP_AI_Orchestration_Preset_Service::apply_preset( $preset_id );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error( array( 'message' => $result->get_error_message() ) );
				return;
			}

			wp_send_json_success(
				array(
					'message'   => __( 'Preset applied successfully.', 'wp-mcp-ai' ),
					'preset_id' => $preset_id,
				)
			);
		}

		/**
		 * Handle AJAX request to export token usage as CSV.
		 *
		 * Note: This doesn't use wp_send_json_* because it sends CSV data.
		 */
		public function handle_export_token_usage_csv() {
			// Check capabilities.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Insufficient permissions.', 'wp-mcp-ai' ) );
			}

			// Verify nonce.
			if ( ! check_ajax_referer( 'wp_mcp_ai_dashboard', 'nonce', false ) ) {
				wp_die( esc_html__( 'Invalid security token.', 'wp-mcp-ai' ) );
			}

			// Get filters from request.
			$filters = array();
			if ( isset( $_POST['tier'] ) && '' !== $_POST['tier'] ) {
				$filters['tier'] = sanitize_key( $_POST['tier'] );
			}
			if ( isset( $_POST['tool'] ) && '' !== $_POST['tool'] ) {
				$filters['tool'] = sanitize_key( $_POST['tool'] );
			}

			// Generate CSV content.
			$csv = WP_MCP_AI_Tool_Token_Limits::export_usage_report( $filters );

			if ( empty( $csv ) ) {
				wp_die( esc_html__( 'Failed to generate CSV export.', 'wp-mcp-ai' ) );
			}

			// Set headers for file download.
			$filename = 'token-usage-' . gmdate( 'Y-m-d-H-i-s' ) . '.csv';

			header( 'Content-Type: text/csv; charset=utf-8' );
			header( 'Content-Disposition: attachment; filename=' . $filename );
			header( 'Pragma: no-cache' );
			header( 'Expires: 0' );

			// Output CSV content.
			echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped

			wp_die(); // Stop execution after sending file.
		}

		/**
		 * Handle AJAX request to bulk assign tier to multiple users.
		 */
		public function handle_bulk_assign_tier() {
			// Check capabilities.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Verify nonce.
			if ( ! check_ajax_referer( 'wp_mcp_ai_dashboard', 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Get user IDs from request.
			$user_ids = isset( $_POST['user_ids'] ) ? array_map( 'absint', (array) $_POST['user_ids'] ) : array();

			if ( empty( $user_ids ) ) {
				wp_send_json_error( array( 'message' => __( 'No users selected.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Get tier from request.
			$tier = isset( $_POST['tier'] ) ? sanitize_key( $_POST['tier'] ) : '';

			if ( empty( $tier ) ) {
				wp_send_json_error( array( 'message' => __( 'No tier specified.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Call bulk assignment method.
			$results = WP_MCP_AI_Tool_Token_Limits::bulk_set_user_tiers( $user_ids, $tier );

			if ( ! empty( $results['errors'] ) ) {
				wp_send_json_error(
					array(
						'message' => implode( ' ', $results['errors'] ),
						'results' => $results,
					)
				);
				return;
			}

			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: 1: Number of users updated, 2: Tier name */
						__( 'Successfully updated %1$d users to %2$s tier.', 'wp-mcp-ai' ),
						$results['success'],
						$tier
					),
					'results' => $results,
				)
			);
		}

		/**
		 * Handle AJAX request to apply all recommendations.
		 */
		public function handle_apply_all_recommendations() {
			// Check permissions.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Verify nonce.
			if ( ! check_ajax_referer( 'wp_mcp_ai_dashboard', 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Apply all recommendations.
			$results = WP_MCP_AI_Tool_Recommendations::apply_all_recommendations();

			if ( $results['failed'] > 0 ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: 1: Number of successful updates, 2: Number of failed updates, 3: Number skipped */
							__( 'Applied recommendations: %1$d succeeded, %2$d failed, %3$d skipped (already optimal).', 'wp-mcp-ai' ),
							$results['success'],
							$results['failed'],
							$results['skipped']
						),
						'results' => $results,
					)
				);
				return;
			}

			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: 1: Number of tools updated, 2: Number skipped */
						__( 'Successfully applied recommended settings to %1$d tools. %2$d tools were already using recommended settings.', 'wp-mcp-ai' ),
						$results['success'],
						$results['skipped']
					),
					'results' => $results,
				)
			);
		}

		/**
		 * Handle AJAX request to apply a preset.
		 */
		public function handle_apply_preset() {
			// Check permissions.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Verify nonce.
			if ( ! check_ajax_referer( 'wp_mcp_ai_dashboard', 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Get preset from request.
			$preset = isset( $_POST['preset'] ) ? sanitize_key( $_POST['preset'] ) : 'balanced';

			// Apply preset.
			$results = WP_MCP_AI_Tool_Recommendations::apply_preset( $preset );

			if ( isset( $results['error'] ) ) {
				wp_send_json_error( array( 'message' => $results['error'] ) );
				return;
			}

			if ( $results['failed'] > 0 ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: 1: Number of successful updates, 2: Number of failed updates */
							__( 'Preset applied with some errors: %1$d succeeded, %2$d failed.', 'wp-mcp-ai' ),
							$results['success'],
							$results['failed']
						),
						'results' => $results,
					)
				);
				return;
			}

			$preset_names = array(
				'conservative' => __( 'Conservative', 'wp-mcp-ai' ),
				'balanced'     => __( 'Balanced', 'wp-mcp-ai' ),
				'performance'  => __( 'Performance', 'wp-mcp-ai' ),
				'aggressive'   => __( 'Aggressive', 'wp-mcp-ai' ),
			);

			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: 1: Preset name, 2: Number of tools updated */
						__( 'Successfully applied %1$s preset to %2$d tools!', 'wp-mcp-ai' ),
						isset( $preset_names[ $preset ] ) ? $preset_names[ $preset ] : $preset,
						$results['success']
					),
					'results' => $results,
				)
			);
		}

		/**
		 * Handle AJAX request to get usage trend data for charts.
		 */
		public function handle_get_usage_trend() {
			// Verify nonce.
			if ( ! check_ajax_referer( 'wp_mcp_ai_token_charts', 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Check capabilities.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Get days parameter.
			$days = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 7;

			// Get chart data.
			if ( ! class_exists( 'WP_MCP_AI_Chart_JS_Helper' ) ) {
				wp_send_json_error( array( 'message' => __( 'Chart helper class not found.', 'wp-mcp-ai' ) ) );
				return;
			}

			$data = WP_MCP_AI_Chart_JS_Helper::get_usage_trend_data( array( 'days' => $days ) );

			wp_send_json_success( $data );
		}

		/**
		 * Handle AJAX request to get tier distribution data for charts.
		 */
		public function handle_get_tier_distribution() {
			// Verify nonce.
			if ( ! check_ajax_referer( 'wp_mcp_ai_token_charts', 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Check capabilities.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Get chart data.
			if ( ! class_exists( 'WP_MCP_AI_Chart_JS_Helper' ) ) {
				wp_send_json_error( array( 'message' => __( 'Chart helper class not found.', 'wp-mcp-ai' ) ) );
				return;
			}

			$data = WP_MCP_AI_Chart_JS_Helper::get_tier_distribution_data();

			wp_send_json_success( $data );
		}
	}
}
