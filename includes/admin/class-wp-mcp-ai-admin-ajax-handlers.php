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
		 *
		 * @param mixed ...$args Variable arguments passed by WordPress hooks (not used).
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
				'wp_ajax_wp_mcp_ai_test_brave_search_connection' => 'handle_test_brave_search_connection',
				'wp_ajax_wp_mcp_ai_reset_user_token_usage' => 'handle_reset_user_token_usage',
				'wp_ajax_wp_mcp_ai_reset_all_token_usage'  => 'handle_reset_all_token_usage',
				'wp_ajax_wp_mcp_ai_save_tool_limits'       => 'handle_save_tool_limits',
				'wp_ajax_wp_mcp_ai_save_tool_settings'     => 'handle_save_tool_settings',
				'wp_ajax_wp_mcp_ai_apply_orchestration_preset' => 'handle_apply_orchestration_preset',
				'wp_ajax_wp_mcp_ai_export_token_usage_csv' => 'handle_export_token_usage_csv',
				'wp_ajax_wp_mcp_ai_bulk_assign_tier'       => 'handle_bulk_assign_tier',
				'wp_ajax_wp_mcp_ai_apply_all_recommendations' => 'handle_apply_all_recommendations',
				'wp_ajax_wp_mcp_ai_apply_preset'           => 'handle_apply_preset',
				'wp_ajax_wp_mcp_ai_get_usage_trend'        => 'handle_get_usage_trend',
				'wp_ajax_wp_mcp_ai_get_tier_distribution'  => 'handle_get_tier_distribution',
				'wp_ajax_wp_mcp_ai_get_tool_breakdown'     => 'handle_get_tool_breakdown',
				'wp_ajax_wp_mcp_ai_get_provider_distribution' => 'handle_get_provider_distribution',
				'wp_ajax_wp_mcp_ai_get_model_distribution' => 'handle_get_model_distribution',
				'wp_ajax_wp_mcp_ai_update_chart_period'    => 'handle_update_chart_period',
				'wp_ajax_wp_mcp_ai_refresh_chart'          => 'handle_refresh_chart',
				'wp_ajax_wp_mcp_ai_toggle_tool'            => 'handle_toggle_tool',
				'wp_ajax_wp_mcp_ai_reseed_professions'     => 'handle_reseed_professions',
				'wp_ajax_wp_mcp_ai_reseed_teams'           => 'handle_reseed_teams',
				'wp_ajax_wp_mcp_ai_migrate_gemini_costs'   => 'handle_migrate_gemini_costs',
				'wp_ajax_wp_mcp_ai_regenerate_playbook'    => 'handle_regenerate_playbook',
				'wp_ajax_wp_mcp_ai_sync_all_playbooks'     => 'handle_sync_all_playbooks',
				'wp_ajax_wp_mcp_ai_delete_old_playbooks'   => 'handle_delete_old_playbooks',
				'wp_ajax_wp_mcp_ai_get_models_for_provider' => 'handle_get_models_for_provider',
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
			// Use a minimum of 30 seconds for connection tests to local providers.
			// Local network connections may have higher latency than localhost.
			$settings     = WP_MCP_AI_Admin_Settings::get_settings();
			$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
			// Use ignore_execution_time=true for external HTTP requests to local AI providers
			// since these don't consume PHP execution time while waiting.
			$timeout = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : $resource_mgr->get_request_timeout( true );
			$timeout = max( 30, $timeout );

			// Ensure PHP execution time is sufficient for the HTTP request timeout.
			// Add 10 second buffer to prevent "Maximum execution time exceeded" errors.
			$resource_mgr->ensure_execution_time( $timeout + 10 );

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
			// Use a minimum of 30 seconds for connection tests to local providers.
			// Local network connections may have higher latency than localhost.
			$settings     = WP_MCP_AI_Admin_Settings::get_settings();
			$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
			// Use ignore_execution_time=true for external HTTP requests to local AI providers
			// since these don't consume PHP execution time while waiting.
			$timeout = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : $resource_mgr->get_request_timeout( true );
			$timeout = max( 30, $timeout );

			// Ensure PHP execution time is sufficient for the HTTP request timeout.
			// Add 10 second buffer to prevent "Maximum execution time exceeded" errors.
			$resource_mgr->ensure_execution_time( $timeout + 10 );

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

			// Get timeout from settings.
			// Use a minimum of 30 seconds for connection tests to local providers.
			// Local network connections may have higher latency than localhost.
			$settings     = WP_MCP_AI_Admin_Settings::get_settings();
			$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
			// Use ignore_execution_time=true for external HTTP requests to local AI providers
			// since these don't consume PHP execution time while waiting.
			$timeout = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : $resource_mgr->get_request_timeout( true );
			$timeout = max( 30, $timeout );

			// Ensure PHP execution time is sufficient for the HTTP request timeout.
			// Add 10 second buffer to prevent "Maximum execution time exceeded" errors.
			$resource_mgr->ensure_execution_time( $timeout + 10 );

			$api_url  = trailingslashit( $endpoint_url ) . 'v1/models';
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

			// Get timeout from settings.
			// Use a minimum of 30 seconds for connection tests to local providers.
			// Local network connections may have higher latency than localhost.
			$settings     = WP_MCP_AI_Admin_Settings::get_settings();
			$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
			// Use ignore_execution_time=true for external HTTP requests to local AI providers
			// since these don't consume PHP execution time while waiting.
			$timeout = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : $resource_mgr->get_request_timeout( true );
			$timeout = max( 30, $timeout );

			// Ensure PHP execution time is sufficient for the HTTP request timeout.
			// Add 10 second buffer to prevent "Maximum execution time exceeded" errors.
			$resource_mgr->ensure_execution_time( $timeout + 10 );

			$api_url  = trailingslashit( $endpoint_url ) . 'v1/models';
			$response = wp_remote_get( $api_url, array( 'timeout' => $timeout ) );

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

			// Get timeout from settings.
			$settings     = WP_MCP_AI_Admin_Settings::get_settings();
			$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
			$timeout      = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : $resource_mgr->get_request_timeout();
			$timeout      = max( 5, $timeout );

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
					'timeout' => $timeout,
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
					'timeout' => $timeout,
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
						'timeout' => $timeout,
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

			// Get timeout from settings.
			$settings     = WP_MCP_AI_Admin_Settings::get_settings();
			$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
			$timeout      = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : $resource_mgr->get_request_timeout();
			$timeout      = max( 5, $timeout );

			// Test the connection by fetching zone details.
			$api_url = 'https://api.cloudflare.com/client/v4/zones/' . sanitize_key( $zone_id );

			$response = wp_remote_get(
				$api_url,
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_token,
						'Content-Type'  => 'application/json',
					),
					'timeout' => $timeout,
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
		 * Handle AJAX request to test Brave Search API connection.
		 */
		public function handle_test_brave_search_connection() {
			check_ajax_referer( 'wp-mcp-ai-settings', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
				return;
			}

			$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

			if ( empty( $api_key ) ) {
				wp_send_json_error( array( 'message' => __( 'Please provide a Brave Search API key.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Get timeout from settings.
			$settings     = WP_MCP_AI_Admin_Settings::get_settings();
			$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
			$timeout      = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : $resource_mgr->get_request_timeout();
			$timeout      = max( 5, $timeout );

			// Test the connection by making a simple search request.
			// Brave Search API endpoint for web search.
			$api_url = add_query_arg(
				array(
					'q'     => 'test',
					'count' => 1,
				),
				'https://api.search.brave.com/res/v1/web/search'
			);

			$response = wp_remote_get(
				$api_url,
				array(
					'headers' => array(
						'Accept'               => 'application/json',
						'X-Subscription-Token' => $api_key,
					),
					'timeout' => $timeout,
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

			if ( 401 === $response_code ) {
				wp_send_json_error( array( 'message' => __( 'Invalid API key. Please check your Brave Search API key.', 'wp-mcp-ai' ) ) );
				return;
			}

			if ( 429 === $response_code ) {
				wp_send_json_error( array( 'message' => __( 'Rate limit exceeded. Your API key is valid but you have exceeded your rate limit.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Handle HTTP 202 (Accepted) - search is being processed asynchronously.
			// This is still a valid response indicating the API key works.
			if ( 202 === $response_code ) {
				wp_send_json_success(
					array(
						'message' => __( 'Successfully connected to Brave Search API! (Search processing asynchronously)', 'wp-mcp-ai' ),
					)
				);
				return;
			}

			if ( 200 !== $response_code ) {
				$error_message = __( 'Invalid API key or connection failed.', 'wp-mcp-ai' );
				if ( isset( $data['message'] ) ) {
					$error_message = sanitize_text_field( $data['message'] );
				}
				wp_send_json_error( array( 'message' => $error_message ) );
				return;
			}

			wp_send_json_success(
				array(
					'message' => __( 'Successfully connected to Brave Search API!', 'wp-mcp-ai' ),
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$user_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = %s",
					$meta_key
				)
			);

			// Delete all usage data.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
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
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
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
			$limits            = isset( $_POST['limits'] ) ? (array) wp_unslash( $_POST['limits'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$multipliers       = isset( $_POST['multipliers'] ) ? (array) wp_unslash( $_POST['multipliers'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$model_preferences = isset( $_POST['model_preferences'] ) ? (array) wp_unslash( $_POST['model_preferences'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

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
				$tool_slug          = sanitize_key( $tool_slug );
				$model              = sanitize_text_field( $model );
				$current_preference = isset( $current_preferences[ $tool_slug ] ) ? $current_preferences[ $tool_slug ] : 'default';

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

		/**
		 * Handle AJAX request to get tool breakdown data for charts.
		 */
		public function handle_get_tool_breakdown() {
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

			// Get parameters.
			$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
			$days    = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 7;
			$limit   = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 10;

			$data = WP_MCP_AI_Chart_JS_Helper::get_tool_breakdown_data(
				array(
					'user_id' => $user_id,
					'days'    => $days,
					'limit'   => $limit,
				)
			);

			wp_send_json_success( $data );
		}

		/**
		 * Handle AJAX request to get provider distribution data for charts.
		 */
		public function handle_get_provider_distribution() {
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

			// Get parameters.
			$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;

			$data = WP_MCP_AI_Chart_JS_Helper::get_provider_distribution_data(
				array(
					'user_id' => $user_id,
				)
			);

			wp_send_json_success( $data );
		}

		/**
		 * Handle AJAX request to get model distribution data for charts.
		 */
		public function handle_get_model_distribution() {
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

			// Get parameters.
			$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : 0;
			$limit   = isset( $_POST['limit'] ) ? absint( $_POST['limit'] ) : 10;

			$data = WP_MCP_AI_Chart_JS_Helper::get_model_distribution_data(
				array(
					'user_id' => $user_id,
					'limit'   => $limit,
				)
			);

			wp_send_json_success( $data );
		}

		/**
		 * Handle AJAX request to update chart period.
		 *
		 * Following SoC: Delegates data retrieval to Chart Helper.
		 */
		public function handle_update_chart_period() {
			// Verify nonce - using analytics nonce for dashboard widgets.
			$nonce_actions = array( 'wp_mcp_ai_token_charts', 'wp_mcp_ai_analytics' );
			$nonce_valid   = false;

			foreach ( $nonce_actions as $nonce_action ) {
				if ( check_ajax_referer( $nonce_action, 'nonce', false ) ) {
					$nonce_valid = true;
					break;
				}
			}

			if ( ! $nonce_valid ) {
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Check capabilities.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Get chart ID and period.
			$chart_id = isset( $_POST['chart_id'] ) ? sanitize_key( $_POST['chart_id'] ) : '';
			$period   = isset( $_POST['period'] ) ? absint( $_POST['period'] ) : 7;

			// Validate chart ID.
			$valid_charts = array(
				'wp-mcp-ai-dashboard-usage-trend',
				'wp-mcp-ai-usage-trend-chart',
				'wp-mcp-ai-tool-breakdown-chart',
			);

			if ( ! in_array( $chart_id, $valid_charts, true ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid chart ID.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Get updated data based on chart type (following SoC - delegate to helper).
			if ( ! class_exists( 'WP_MCP_AI_Chart_JS_Helper' ) ) {
				wp_send_json_error( array( 'message' => __( 'Chart helper class not found.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Route to appropriate data method based on chart ID.
			$data = array();
			if ( false !== strpos( $chart_id, 'usage-trend' ) ) {
				$data = WP_MCP_AI_Chart_JS_Helper::get_usage_trend_data( array( 'days' => $period ) );
			} elseif ( false !== strpos( $chart_id, 'tool-breakdown' ) ) {
				$data = WP_MCP_AI_Chart_JS_Helper::get_tool_breakdown_data(
					array(
						'days'  => $period,
						'limit' => 10,
					)
				);
			}

			wp_send_json_success( $data );
		}

		/**
		 * Handle AJAX request to refresh chart data.
		 *
		 * Following SoC: Delegates data retrieval to Chart Helper.
		 */
		public function handle_refresh_chart() {
			// Verify nonce - using analytics nonce for dashboard widgets.
			$nonce_actions = array( 'wp_mcp_ai_token_charts', 'wp_mcp_ai_analytics' );
			$nonce_valid   = false;

			foreach ( $nonce_actions as $nonce_action ) {
				if ( check_ajax_referer( $nonce_action, 'nonce', false ) ) {
					$nonce_valid = true;
					break;
				}
			}

			if ( ! $nonce_valid ) {
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Check capabilities.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Get chart ID.
			$chart_id = isset( $_POST['chart_id'] ) ? sanitize_key( $_POST['chart_id'] ) : '';

			// Validate chart ID.
			$valid_charts = array(
				'wp-mcp-ai-dashboard-usage-trend',
				'wp-mcp-ai-usage-trend-chart',
				'wp-mcp-ai-tier-distribution-chart',
				'wp-mcp-ai-tool-breakdown-chart',
				'wp-mcp-ai-dashboard-cost-breakdown',
			);

			if ( ! in_array( $chart_id, $valid_charts, true ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid chart ID.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Get fresh data based on chart type (following SoC - delegate to helper).
			if ( ! class_exists( 'WP_MCP_AI_Chart_JS_Helper' ) ) {
				wp_send_json_error( array( 'message' => __( 'Chart helper class not found.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Route to appropriate data method based on chart ID.
			$data = array();
			if ( false !== strpos( $chart_id, 'usage-trend' ) ) {
				$data = WP_MCP_AI_Chart_JS_Helper::get_usage_trend_data( array( 'days' => 7 ) );
			} elseif ( false !== strpos( $chart_id, 'tier-distribution' ) ) {
				$data = WP_MCP_AI_Chart_JS_Helper::get_tier_distribution_data();
			} elseif ( false !== strpos( $chart_id, 'tool-breakdown' ) ) {
				$data = WP_MCP_AI_Chart_JS_Helper::get_tool_breakdown_data( array( 'days' => 7 ) );
			} elseif ( false !== strpos( $chart_id, 'cost-breakdown' ) ) {
				// Cost data from Cost Tracking Service (following SoC).
				if ( class_exists( 'WP_MCP_AI_Cost_Tracking_Service' ) ) {
					$cost_data = WP_MCP_AI_Cost_Tracking_Service::get_dashboard_cost_summary( 7 );
					$data      = WP_MCP_AI_Cost_Tracking_Service::get_cost_by_provider_data( 7 );
				}
			}

			wp_send_json_success( $data );
		}

		/**
		 * Handle tool toggle (enable/disable).
		 *
		 * @since 1.0.0
		 */
		public function handle_toggle_tool() {
			// Verify nonce.
			check_ajax_referer( 'wp-mcp-ai-settings', 'nonce' );

			// Check permissions.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'You do not have permission to manage tools.', 'wp-mcp-ai' ),
					)
				);
			}

			// Get tool slug.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below with sanitize_key.
			$tool_slug = isset( $_POST['tool_slug'] ) ? sanitize_key( wp_unslash( $_POST['tool_slug'] ) ) : '';
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below with sanitize_key.
			$action = isset( $_POST['tool_action'] ) ? sanitize_key( wp_unslash( $_POST['tool_action'] ) ) : '';

			if ( empty( $tool_slug ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Tool slug is required.', 'wp-mcp-ai' ),
					)
				);
			}

			if ( ! in_array( $action, array( 'enable', 'disable' ), true ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Invalid action.', 'wp-mcp-ai' ),
					)
				);
			}

			// Get tool registry.
			$registry = WP_MCP_AI_Tool_Registry::get_instance();

			// Check if tool exists.
			if ( ! $registry->is_tool_registered( $tool_slug ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Tool not found.', 'wp-mcp-ai' ),
					)
				);
			}

			// Perform action.
			$success = false;
			if ( 'enable' === $action ) {
				$success = $registry->enable_tool( $tool_slug );
			} else {
				$success = $registry->disable_tool( $tool_slug );
			}

			if ( $success ) {
				wp_send_json_success(
					array(
						'message' => 'enable' === $action
							? __( 'Tool enabled successfully.', 'wp-mcp-ai' )
							: __( 'Tool disabled successfully.', 'wp-mcp-ai' ),
						'enabled' => 'enable' === $action,
					)
				);
			} else {
				wp_send_json_error(
					array(
						'message' => __( 'Failed to update tool status.', 'wp-mcp-ai' ),
					)
				);
			}
		}

		/**
		 * Handle profession re-seeding AJAX request.
		 */
		private function handle_reseed_professions() {
			check_ajax_referer( 'wp_mcp_ai_reseed_professions', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'You do not have permission to perform this action.', 'wp-mcp-ai' ),
					)
				);
				return;
			}

			// Get action type: 'update' or 'replace'.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below with sanitize_key.
			$action = isset( $_POST['action_type'] ) ? sanitize_key( wp_unslash( $_POST['action_type'] ) ) : 'update';

			if ( ! in_array( $action, array( 'update', 'replace' ), true ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Invalid action type.', 'wp-mcp-ai' ),
					)
				);
				return;
			}

			// Load profession seeder.
			if ( ! class_exists( 'WP_MCP_AI_Profession_Seeder' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-seeder.php';
			}

			if ( ! class_exists( 'WP_MCP_AI_Profession_Repository' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/repositories/class-wp-mcp-ai-profession-repository.php';
			}

			if ( ! class_exists( 'WP_MCP_AI_Profession_Knowledge_Base_Loader' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-profession-knowledge-base-loader.php';
			}

			if ( ! class_exists( 'WP_MCP_AI_Profession_Base_Knowledge_Seeder' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-base-knowledge-seeder.php';
			}

			if ( ! class_exists( 'WP_MCP_AI_Profession_CPT' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-cpt.php';
			}

			// Preserve preferred datasets before any updates/deletions.
			$preserved_datasets = array();
			$existing_posts     = get_posts(
				array(
					'post_type'      => 'mcp_ai_profession',
					'posts_per_page' => -1,
					'post_status'    => 'any',
				)
			);

			foreach ( $existing_posts as $post ) {
				$datasets = get_post_meta( $post->ID, WP_MCP_AI_Profession_CPT::META_PREFERRED_DATASETS, true );
				if ( ! empty( $datasets ) && is_array( $datasets ) ) {
					$preserved_datasets[ $post->post_name ] = $datasets;
				}
			}

			// If replace action, delete all existing professions.
			if ( 'replace' === $action ) {
				foreach ( $existing_posts as $post ) {
					wp_delete_post( $post->ID, true );
				}
			}

			// Clear the seeded option to allow re-seeding.
			delete_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION );

			// Load professions from JSON files.
			$loader      = new WP_MCP_AI_Profession_Knowledge_Base_Loader();
			$professions = $loader->load_all();

			if ( is_wp_error( $professions ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: Error message */
							__( 'Failed to load profession data: %s', 'wp-mcp-ai' ),
							$professions->get_error_message()
						),
					)
				);
				return;
			}

			// Save professions.
			$repository = new WP_MCP_AI_Profession_Repository();
			$saved      = 0;
			$updated    = 0;
			$errors     = array();

			foreach ( $professions as $profession_data ) {
				// Restore preserved datasets if they exist for this profession slug.
				$slug = sanitize_title( $profession_data['slug'] );
				if ( isset( $preserved_datasets[ $slug ] ) ) {
					$profession_data['preferred_datasets'] = $preserved_datasets[ $slug ];
				}

				// Check if profession already exists by slug.
				$existing = null;
				if ( 'update' === $action && ! empty( $profession_data['slug'] ) ) {
					$existing = $repository->find_one( $profession_data['slug'] );
				}

				if ( $existing ) {
					// Update existing profession - preserve datasets if not already in profession_data.
					if ( ! isset( $profession_data['preferred_datasets'] ) ) {
						$existing_datasets = get_post_meta( $existing->ID, WP_MCP_AI_Profession_CPT::META_PREFERRED_DATASETS, true );
						if ( ! empty( $existing_datasets ) && is_array( $existing_datasets ) ) {
							$profession_data['preferred_datasets'] = $existing_datasets;
						}
					}
					$profession_data['id'] = $existing->ID;
					$result                = $repository->save( $profession_data );
					if ( ! is_wp_error( $result ) ) {
						++$updated;
					} else {
						$errors[] = $result->get_error_message();
					}
				} else {
					// Create new profession.
					$result = $repository->save( $profession_data );
					if ( ! is_wp_error( $result ) ) {
						++$saved;
					} else {
						$errors[] = $result->get_error_message();
					}
				}
			}

			// Mark as seeded.
			update_option( WP_MCP_AI_Profession_Seeder::SEEDED_OPTION, true, false );

			// Clear cache.
			$repository->clear_cache();

			// Refresh base knowledge documents and MIME types.
			WP_MCP_AI_Profession_Base_Knowledge_Seeder::seed_base_knowledge( true );

			// Refresh profession playbooks from txt files.
			if ( ! class_exists( 'WP_MCP_AI_Profession_Playbook_Seeder' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-playbook-seeder.php';
			}
			WP_MCP_AI_Profession_Playbook_Seeder::sync_all( true );

			$message = sprintf(
				/* translators: 1: Number of professions created, 2: Number of professions updated */
				__( 'Professions reloaded successfully. Created: %1$d, Updated: %2$d', 'wp-mcp-ai' ),
				$saved,
				$updated
			);

			if ( ! empty( $errors ) ) {
				$message .= ' ' . sprintf(
					/* translators: %d: Number of errors */
					__( 'Errors: %d', 'wp-mcp-ai' ),
					count( $errors )
				);
			}

			wp_send_json_success(
				array(
					'message' => $message,
					'created' => $saved,
					'updated' => $updated,
					'errors'  => count( $errors ),
				)
			);
		}

		/**
		 * Handle team reseed AJAX request.
		 *
		 * @return void
		 */
		private function handle_reseed_teams() {
			check_ajax_referer( 'wp_mcp_ai_reseed_teams', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'You do not have permission to perform this action.', 'wp-mcp-ai' ),
					)
				);
				return;
			}

			// Get action type: 'update' or 'replace'.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below with sanitize_key.
			$action = isset( $_POST['action_type'] ) ? sanitize_key( wp_unslash( $_POST['action_type'] ) ) : 'update';

			if ( ! in_array( $action, array( 'update', 'replace' ), true ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Invalid action type.', 'wp-mcp-ai' ),
					)
				);
				return;
			}

			// Load team seeder.
			if ( ! class_exists( 'WP_MCP_AI_Team_Seeder' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/teams/class-wp-mcp-ai-team-seeder.php';
			}

			if ( ! class_exists( 'WP_MCP_AI_Team_Repository' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/repositories/class-wp-mcp-ai-team-repository.php';
			}

			if ( ! class_exists( 'WP_MCP_AI_Team_Knowledge_Base_Loader' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-team-knowledge-base-loader.php';
			}

			// Check if professions are seeded - teams need professions to exist first.
			$profession_count      = wp_count_posts( 'mcp_ai_profession' );
			$published_professions = isset( $profession_count->publish ) ? $profession_count->publish : 0;

			if ( $published_professions < 10 ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %d: Number of professions found */
							__( 'Not enough professions found in database (%d). Please reseed professions first using "Update Professions" or "Replace All Professions" button above before reseeding teams.', 'wp-mcp-ai' ),
							$published_professions
						),
					)
				);
				return;
			}

			// If replace action, delete all existing teams.
			if ( 'replace' === $action ) {
				$existing_teams = get_posts(
					array(
						'post_type'      => 'mcp_ai_team',
						'posts_per_page' => -1,
						'post_status'    => 'any',
						'fields'         => 'ids',
					)
				);

				foreach ( $existing_teams as $post_id ) {
					wp_delete_post( $post_id, true );
				}
			}

			// Clear the seeded option to allow re-seeding.
			delete_option( WP_MCP_AI_Team_Seeder::SEEDED_OPTION );

			// Load teams from JSON files.
			$loader = new WP_MCP_AI_Team_Knowledge_Base_Loader();
			$teams  = $loader->load_all();

			if ( is_wp_error( $teams ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: Error message */
							__( 'Failed to load team data: %s', 'wp-mcp-ai' ),
							$teams->get_error_message()
						),
					)
				);
				return;
			}

			// Save teams.
			$repository = new WP_MCP_AI_Team_Repository();
			$saved      = 0;
			$updated    = 0;
			$errors     = array();
			$warnings   = array();

			foreach ( $teams as $team_data ) {
				// Check if team already exists by slug.
				$existing = null;
				if ( 'update' === $action && ! empty( $team_data['slug'] ) ) {
					$existing = $repository->find_one( $team_data['slug'] );
				}

				if ( $existing ) {
					// Update existing team.
					$team_data['id'] = $existing->ID;
					$result          = $repository->save( $team_data );
					if ( ! is_wp_error( $result ) ) {
						++$updated;

						// Check if team has members.
						$member_ids = get_post_meta( $result, WP_MCP_AI_Team_CPT::META_TEAM_MEMBERS, true );
						if ( empty( $member_ids ) && ! empty( $team_data['members'] ) ) {
							$warnings[] = sprintf(
								/* translators: %s: Team slug */
								__( 'Team "%s" has no members - profession posts may not exist', 'wp-mcp-ai' ),
								$team_data['slug']
							);
						}
					} else {
						$errors[] = $result->get_error_message();
					}
				} else {
					// Create new team.
					$result = $repository->save( $team_data );
					if ( ! is_wp_error( $result ) ) {
						++$saved;

						// Check if team has members.
						$member_ids = get_post_meta( $result, WP_MCP_AI_Team_CPT::META_TEAM_MEMBERS, true );
						if ( empty( $member_ids ) && ! empty( $team_data['members'] ) ) {
							$warnings[] = sprintf(
								/* translators: %s: Team slug */
								__( 'Team "%s" has no members - profession posts may not exist', 'wp-mcp-ai' ),
								$team_data['slug']
							);
						}
					} else {
						$errors[] = $result->get_error_message();
					}
				}
			}

			// Mark as seeded.
			update_option( WP_MCP_AI_Team_Seeder::SEEDED_OPTION, true, false );

			// Clear cache.
			$repository->clear_cache();

			$message = sprintf(
			/* translators: 1: Number of teams created, 2: Number of teams updated */
				__( 'Teams reloaded successfully. Created: %1$d, Updated: %2$d', 'wp-mcp-ai' ),
				$saved,
				$updated
			);

			if ( ! empty( $errors ) ) {
				$message .= ' ' . sprintf(
				/* translators: %d: Number of errors */
					__( 'Errors: %d', 'wp-mcp-ai' ),
					count( $errors )
				);
			}

			if ( ! empty( $warnings ) ) {
				$message .= ' ' . sprintf(
				/* translators: %d: Number of warnings */
					__( 'Warnings: %d teams have no members. Try reseeding professions first.', 'wp-mcp-ai' ),
					count( $warnings )
				);
				// Log the warnings for debugging.
				error_log( 'WP_MCP_AI Team Reseed Warnings: ' . implode( '; ', $warnings ) );
			}

			wp_send_json_success(
				array(
					'message'  => $message,
					'created'  => $saved,
					'updated'  => $updated,
					'errors'   => count( $errors ),
					'warnings' => count( $warnings ),
				)
			);
		}

		/**
		 * Handle Gemini cost tracking migration AJAX request.
		 *
		 * Migrates historical token tracking records where Gemini tools were
		 * incorrectly attributed to OpenAI provider, fixing provider attribution
		 * and recalculating costs with correct Gemini pricing.
		 *
		 * @since 1.1.0
		 */
		private function handle_migrate_gemini_costs() {
			check_ajax_referer( 'wp_mcp_ai_migrate_gemini_costs', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'You do not have permission to perform this action.', 'wp-mcp-ai' ),
					)
				);
				return;
			}

			// Get action type: 'preview' or 'migrate'.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below with sanitize_key.
			$action = isset( $_POST['action_type'] ) ? sanitize_key( wp_unslash( $_POST['action_type'] ) ) : 'preview';

			if ( ! in_array( $action, array( 'preview', 'migrate' ), true ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Invalid action type.', 'wp-mcp-ai' ),
					)
				);
				return;
			}

			// Load enhanced token tracking class.
			if ( ! class_exists( 'WP_MCP_AI_Enhanced_Token_Tracking' ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Enhanced token tracking is not available.', 'wp-mcp-ai' ),
					)
				);
				return;
			}

			// Determine if this is a dry run (preview).
			$dry_run = ( 'preview' === $action );
			$limit   = 1000; // Process up to 1000 records at a time.

			// Run the migration.
			$results = WP_MCP_AI_Enhanced_Token_Tracking::migrate_provider_misattributions( $dry_run, $limit );

			// Build response message.
			if ( $dry_run ) {
				if ( 0 === $results['total_gemini_records'] ) {
					// No Gemini tool records exist at all.
					$message = __( 'No Gemini tool usage records found in the database. This is expected if you haven\'t used any Gemini tools yet.', 'wp-mcp-ai' );
				} elseif ( 0 === $results['total_needing_migration'] ) {
					// All Gemini records are correctly attributed.
					$message = sprintf(
					/* translators: %d: Number of correctly attributed Gemini records */
						__( 'Found %d Gemini tool records, all correctly attributed to Gemini provider. No migration needed.', 'wp-mcp-ai' ),
						$results['correctly_attributed']
					);
				} else {
					// Some records need migration.
					if ( $results['total_needing_migration'] > $limit ) {
						// More records than batch limit - warn user.
						$message = sprintf(
						/* translators: 1: Total records needing migration, 2: Batch size that will be processed, 3: Total Gemini records, 4: Already correct records */
							__( 'Preview: Found %1$d records that need migration (out of %2$d total Gemini tool records). This migration will process the first %3$d records. %4$d records are already correctly attributed to Gemini. You may need to run the migration multiple times to update all records.', 'wp-mcp-ai' ),
							$results['total_needing_migration'],
							$results['total_gemini_records'],
							$limit,
							$results['correctly_attributed']
						);
					} else {
						$message = sprintf(
						/* translators: 1: Number of records that would be updated, 2: Total Gemini records, 3: Already correct records */
							__( 'Preview: Found %1$d records that need migration (out of %2$d total Gemini tool records). %3$d records are already correctly attributed to Gemini.', 'wp-mcp-ai' ),
							$results['total_needing_migration'],
							$results['total_gemini_records'],
							$results['correctly_attributed']
						);
					}
				}
			} elseif ( 0 === $results['records_updated'] ) {
				if ( 0 === $results['total_gemini_records'] ) {
					$message = __( 'No Gemini tool usage records found in the database.', 'wp-mcp-ai' );
				} else {
					$message = sprintf(
					/* translators: %d: Number of correctly attributed records */
						__( 'No records were updated. All %d Gemini tool records are already correctly attributed.', 'wp-mcp-ai' ),
						$results['correctly_attributed']
					);
				}
			} else {
				// Check if there are more records to migrate.
				$remaining = $results['total_needing_migration'] - $results['records_updated'];
				if ( $remaining > 0 ) {
					$message = sprintf(
					/* translators: 1: Number of records updated, 2: Total records needing migration, 3: Remaining records */
						__( 'Migration complete! Successfully updated %1$d records with corrected Gemini provider attribution and costs. %2$d records still need migration. Please run the migration again to process the remaining records.', 'wp-mcp-ai' ),
						$results['records_updated'],
						$results['total_needing_migration'],
						$remaining
					);
				} else {
					$message = sprintf(
					/* translators: 1: Number of records updated, 2: Total Gemini records */
						__( 'Migration complete! Successfully updated %1$d records with corrected Gemini provider attribution and costs. All Gemini tool records are now correctly attributed. Total Gemini tool records: %2$d', 'wp-mcp-ai' ),
						$results['records_updated'],
						$results['total_gemini_records']
					);
				}
			}

			wp_send_json_success(
				array(
					'message'                 => $message,
					'dry_run'                 => $dry_run,
					'total_checked'           => $results['total_checked'],
					'records_updated'         => $results['records_updated'],
					'total_gemini_records'    => $results['total_gemini_records'],
					'correctly_attributed'    => $results['correctly_attributed'],
					'total_needing_migration' => $results['total_needing_migration'],
				)
			);
		}

		/**
		 * Handle AJAX request to save model configuration.
		 *
		 * Follows SoC: delegates data operations to WP_MCP_AI_Model_Config.
		 *
		 * @since 1.0.0
		 */
		public function handle_save_model_config() {
			// Verify nonce.
			check_ajax_referer( 'wp_mcp_ai_admin', 'nonce' );

			// Check permissions.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( __( 'Insufficient permissions.', 'wp-mcp-ai' ) );
			}

			// Get model ID.
			$model = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';

			if ( empty( $model ) ) {
				wp_send_json_error( __( 'Model ID is required.', 'wp-mcp-ai' ) );
			}

			// Get config data.
			$config = isset( $_POST['config'] ) ? (array) wp_unslash( $_POST['config'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( empty( $config ) ) {
				wp_send_json_error( __( 'Configuration data is required.', 'wp-mcp-ai' ) );
			}

			// Load model config class if not already loaded.
			if ( ! class_exists( 'WP_MCP_AI_Model_Config' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-model-config.php';
			}

			// Get existing config to merge.
			$existing_config = WP_MCP_AI_Model_Config::get_model_config( $model );

			if ( ! $existing_config ) {
				$existing_config = array();
			}

			// Merge with incoming changes.
			$updated_config = array_merge( $existing_config, $config );

			// Delegate save operation to model config class (SoC).
			$result = WP_MCP_AI_Model_Config::set_model_config( $model, $updated_config );

			if ( $result ) {
				wp_send_json_success(
					array(
						'message' => __( 'Model configuration saved successfully.', 'wp-mcp-ai' ),
						'model'   => $model,
						'config'  => $updated_config,
					)
				);
			} else {
				wp_send_json_error( __( 'Failed to save model configuration.', 'wp-mcp-ai' ) );
			}
		}

		/**
		 * Handle AJAX request to save tool settings (capability flags and force-sync).
		 *
		 * @since 1.0.0
		 */
		private function handle_save_tool_settings() {
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

			// Get tool slug from request.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below with sanitize_key.
			$tool_slug = isset( $_POST['tool_slug'] ) ? sanitize_key( wp_unslash( $_POST['tool_slug'] ) ) : '';

			if ( empty( $tool_slug ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid tool slug.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Verify tool exists.
			$registry = WP_MCP_AI_Tool_Registry::get_instance();
			if ( ! $registry->is_tool_registered( $tool_slug ) ) {
				wp_send_json_error( array( 'message' => __( 'Tool not found.', 'wp-mcp-ai' ) ) );
				return;
			}

			// Load tool settings manager.
			if ( ! class_exists( 'WP_MCP_AI_Tool_Settings_Manager' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-tool-settings-manager.php';
			}

			// Get capability flags from request.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below with array_map.
			$flags = isset( $_POST['capability_flags'] ) && is_array( $_POST['capability_flags'] )
				? array_map( 'sanitize_key', wp_unslash( $_POST['capability_flags'] ) )
				: array();

			// Get force-sync setting from request.
			$force_sync = isset( $_POST['force_sync'] ) && 'true' === $_POST['force_sync'];

			// Save settings.
			$flags_saved = WP_MCP_AI_Tool_Settings_Manager::update_capability_flags( $tool_slug, $flags );
			$sync_saved  = WP_MCP_AI_Tool_Settings_Manager::set_force_sync( $tool_slug, $force_sync );

			if ( $flags_saved && $sync_saved ) {
				// Clear orchestration caches.
				if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) ) {
					WP_MCP_AI_Cache_Helper::invalidate_orchestration_caches();
				}

				wp_send_json_success(
					array(
						'message' => __( 'Tool settings saved successfully.', 'wp-mcp-ai' ),
					)
				);
			} else {
				wp_send_json_error(
					array(
						'message' => __( 'Failed to save tool settings.', 'wp-mcp-ai' ),
					)
				);
			}
		}

		/**
		 * Handle regenerate single playbook AJAX request.
		 *
		 * @since 1.7.0
		 */
		private function handle_regenerate_playbook() {
			check_ajax_referer( 'wp_mcp_ai_regenerate_playbook', 'nonce' );

			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'You do not have permission to perform this action.', 'wp-mcp-ai' ),
					)
				);
				return;
			}

			// Get profession ID.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below with absint.
			$profession_id = isset( $_POST['profession_id'] ) ? absint( wp_unslash( $_POST['profession_id'] ) ) : 0;

			if ( ! $profession_id ) {
				wp_send_json_error(
					array(
						'message' => __( 'Invalid profession ID.', 'wp-mcp-ai' ),
					)
				);
				return;
			}

			// Verify profession exists.
			$profession = get_post( $profession_id );
			if ( ! $profession || WP_MCP_AI_Profession_CPT::POST_TYPE !== $profession->post_type ) {
				wp_send_json_error(
					array(
						'message' => __( 'Profession not found.', 'wp-mcp-ai' ),
					)
				);
				return;
			}

			// Load playbook loader and seeder.
			if ( ! class_exists( 'WP_MCP_AI_Profession_Playbook_Loader' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-profession-playbook-loader.php';
			}

			if ( ! class_exists( 'WP_MCP_AI_Profession_Playbook_Seeder' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-playbook-seeder.php';
			}

			// Use reflection to call protected method.
			try {
				$loader = new WP_MCP_AI_Profession_Playbook_Loader();
				$seeder = new WP_MCP_AI_Profession_Playbook_Seeder();

				$method = new ReflectionMethod( 'WP_MCP_AI_Profession_Playbook_Seeder', 'sync_profession_playbook' );
				$method->setAccessible( true );
				$method->invoke( null, $profession, $loader, true ); // Force regeneration.

				wp_send_json_success(
					array(
						'message' => sprintf(
							/* translators: %s: Profession title */
							__( 'Playbook for "%s" regenerated successfully!', 'wp-mcp-ai' ),
							$profession->post_title
						),
					)
				);
			} catch ( Exception $e ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: Error message */
							__( 'Failed to regenerate playbook: %s', 'wp-mcp-ai' ),
							$e->getMessage()
						),
					)
				);
			}
		}

		/**
		 * Handle sync all playbooks AJAX request.
		 *
		 * @since 1.7.0
		 */
		private function handle_sync_all_playbooks() {
			check_ajax_referer( 'wp_mcp_ai_sync_all_playbooks', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'You do not have permission to perform this action.', 'wp-mcp-ai' ),
					)
				);
				return;
			}

			// Load playbook seeder.
			if ( ! class_exists( 'WP_MCP_AI_Profession_Playbook_Seeder' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-playbook-seeder.php';
			}

			// Get force parameter.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below with sanitize_key.
			$force = isset( $_POST['force'] ) && 'true' === sanitize_key( wp_unslash( $_POST['force'] ) );

			// Sync all playbooks.
			WP_MCP_AI_Profession_Playbook_Seeder::sync_all( $force );

			// Update last sync timestamp.
			update_option( 'wp_mcp_ai_playbooks_last_sync', current_time( 'timestamp' ) );

			$message = $force
				? __( 'All profession playbooks regenerated successfully! Duplicates removed.', 'wp-mcp-ai' )
				: __( 'Profession playbooks synced successfully! Only changed playbooks were updated and duplicates removed.', 'wp-mcp-ai' );

			wp_send_json_success(
				array(
					'message' => $message,
				)
			);
		}

		/**
		 * Handle delete old playbooks AJAX request.
		 *
		 * Permanently deletes orphaned playbook attachments from the media library.
		 *
		 * @since 1.7.0
		 */
		private function handle_delete_old_playbooks() {
			check_ajax_referer( 'wp_mcp_ai_delete_old_playbooks', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'You do not have permission to perform this action.', 'wp-mcp-ai' ),
					)
				);
				return;
			}

			global $wpdb;

			// Find all playbook attachments that are NOT associated with any profession.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$orphaned_attachments = $wpdb->get_col(
				"SELECT p.ID 
				FROM {$wpdb->posts} p
				WHERE p.post_type = 'attachment'
				AND p.post_mime_type = 'text/plain'
				AND p.post_title LIKE '%playbook%'
				AND NOT EXISTS (
					SELECT 1 
					FROM {$wpdb->postmeta} pm 
					WHERE pm.post_id = p.ID 
					AND pm.meta_key = '_wp_mcp_ai_playbook_profession_id'
				)"
			);

			if ( empty( $orphaned_attachments ) ) {
				wp_send_json_success(
					array(
						'message' => __( 'No orphaned playbook attachments found to delete.', 'wp-mcp-ai' ),
					)
				);
				return;
			}

			$deleted_count = 0;

			// Delete each orphaned attachment permanently.
			foreach ( $orphaned_attachments as $attachment_id ) {
				// Use wp_delete_attachment with force_delete = true to permanently delete.
				if ( wp_delete_attachment( $attachment_id, true ) ) {
					++$deleted_count;
				}
			}

			/* translators: %d: number of deleted playbook attachments */
			$message = sprintf(
				_n(
					'Successfully deleted %d orphaned playbook attachment from media library.',
					'Successfully deleted %d orphaned playbook attachments from media library.',
					$deleted_count,
					'wp-mcp-ai'
				),
				$deleted_count
			);

			wp_send_json_success(
				array(
					'message' => $message,
				)
			);
		}

		/**
		 * Handle AJAX request to get models for a provider.
		 *
		 * @since 1.0.0
		 */
		public function handle_get_models_for_provider() {
			// Verify nonce for security.
			// Accept nonce from either admin model selector or professional selector widget.
			$nonce_actions = array( 'wp-mcp-ai-model-selector', 'wp-mcp-ai-professional-selector' );
			$nonce_valid   = false;

			foreach ( $nonce_actions as $nonce_action ) {
				if ( check_ajax_referer( $nonce_action, 'nonce', false ) ) {
					$nonce_valid = true;
					break;
				}
			}

			if ( ! $nonce_valid ) {
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'wp-mcp-ai' ) ) );
				return;
			}
			// Check user capabilities.
			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Insufficient permissions.', 'wp-mcp-ai' ),
					)
				);
				return;
			}

			// Get provider from request.
			$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';

			if ( empty( $provider ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Provider is required.', 'wp-mcp-ai' ),
					)
				);
				return;
			}

			// Validate provider.
			$allowed_providers = apply_filters( 'wp_mcp_ai_allowed_providers', array( 'openai', 'anthropic', 'gemini', 'huggingface', 'ollama', 'lm_studio' ) );
			if ( ! in_array( $provider, $allowed_providers, true ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Invalid provider.', 'wp-mcp-ai' ),
					)
				);
				return;
			}

			// Load model service if not already loaded.
			if ( ! class_exists( 'WP_MCP_AI_Model_Service' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-model-service.php';
			}

			// Get models for the provider.
			$model_service = new WP_MCP_AI_Model_Service();
			$models        = $model_service->get_models_for_provider( $provider );

			if ( empty( $models ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: provider name */
							__( 'No models available for provider: %s. Please configure API keys in settings.', 'wp-mcp-ai' ),
							ucfirst( str_replace( '_', ' ', $provider ) )
						),
					)
				);
				return;
			}

			// Return models as success response.
			wp_send_json_success(
				array(
					'models' => $models,
				)
			);
		}
	}
}
