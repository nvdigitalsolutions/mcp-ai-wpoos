<?php
/**
 * Admin AJAX Handlers for NV oOS.
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
		public function safe_ajax_handler( ...$args ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter accepts variable WordPress hook arguments.
			// Clean any previous output.
			$this->clean_all_buffers();

			// Map action to handler method.
			$action_map = array(
				'wp_ajax_wp_mcp_ai_test_ollama_connection' => 'handle_test_ollama_connection',
				'wp_ajax_wp_mcp_ai_fetch_ollama_models'    => 'handle_fetch_ollama_models',
				'wp_ajax_wp_mcp_ai_test_lm_studio_connection' => 'handle_test_lm_studio_connection',
				'wp_ajax_wp_mcp_ai_fetch_lm_studio_models' => 'handle_fetch_lm_studio_models',
				'wp_ajax_wp_mcp_ai_fetch_cloudways_data'   => 'handle_fetch_cloudways_data',
				'wp_ajax_wp_mcp_ai_test_cloudways_connection' => 'handle_test_cloudways_connection',
				'wp_ajax_wp_mcp_ai_test_cloudflare_connection' => 'handle_test_cloudflare_connection',
				'wp_ajax_wp_mcp_ai_test_brave_search_connection' => 'handle_test_brave_search_connection',
				'wp_ajax_wp_mcp_ai_test_tavily_connection'       => 'handle_test_tavily_connection',
				'wp_ajax_wp_mcp_ai_test_mubert_connection' => 'handle_test_mubert_connection',
				'wp_ajax_wp_mcp_ai_test_plaid_connection'  => 'handle_test_plaid_connection',
				'wp_ajax_wp_mcp_ai_test_yahoo_connection'  => 'handle_test_yahoo_connection',
				'wp_ajax_wp_mcp_ai_test_removebg_connection' => 'handle_test_removebg_connection',
				'wp_ajax_wp_mcp_ai_test_flowhub_connection' => 'handle_test_flowhub_connection',
				'wp_ajax_wp_mcp_ai_test_isams_connection'  => 'handle_test_isams_connection',
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
				'wp_ajax_wp_mcp_ai_seed_task_templates'    => 'handle_seed_task_templates',
				'wp_ajax_wp_mcp_ai_seed_orchestration'     => 'handle_seed_orchestration',
				'wp_ajax_wp_mcp_ai_migrate_gemini_costs'   => 'handle_migrate_gemini_costs',
				'wp_ajax_wp_mcp_ai_refresh_skills'         => 'handle_refresh_skills',
				'wp_ajax_wp_mcp_ai_regenerate_playbook'    => 'handle_regenerate_playbook',
				'wp_ajax_wp_mcp_ai_sync_all_playbooks'     => 'handle_sync_all_playbooks',
				'wp_ajax_wp_mcp_ai_delete_old_playbooks'   => 'handle_delete_old_playbooks',
				'wp_ajax_wp_mcp_ai_get_models_for_provider' => 'handle_get_models_for_provider',
			);

			$action         = current_action();
			$handler_method = isset( $action_map[ $action ] ) ? $action_map[ $action ] : '';

			if ( ! $handler_method || ! method_exists( $this, $handler_method ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid action.', 'mcp-ai-wpoos' ) ) );
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
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			$endpoint_url = isset( $_POST['endpoint_url'] ) ? esc_url_raw( wp_unslash( $_POST['endpoint_url'] ) ) : '';

			if ( empty( $endpoint_url ) ) {
				wp_send_json_error( array( 'message' => __( 'Please provide an endpoint URL.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Get timeout from settings.
			// Use a minimum of 30 seconds for connection tests to local providers.
			// Local network connections may have higher latency than localhost.
			$settings     = WP_MCP_AI_Admin_Settings::get_settings();
			$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
			// Use ignore_execution_time=true for external HTTP requests to local AI providers.
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
							__( 'Connection failed: %s', 'mcp-ai-wpoos' ),
							$response->get_error_message()
						),
					)
				);
				return;
			}

			$response_code = wp_remote_retrieve_response_code( $response );

			if ( 200 !== $response_code ) {
				wp_send_json_error( array( 'message' => __( 'Invalid endpoint or connection failed.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			wp_send_json_success( array( 'message' => __( 'Successfully connected to Ollama!', 'mcp-ai-wpoos' ) ) );
		}

		/**
		 * Handle AJAX request to fetch Ollama models.
		 */
		public function handle_fetch_ollama_models() {
			check_ajax_referer( 'wp-mcp-ai-settings', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			$endpoint_url = isset( $_POST['endpoint_url'] ) ? esc_url_raw( wp_unslash( $_POST['endpoint_url'] ) ) : '';

			if ( empty( $endpoint_url ) ) {
				wp_send_json_error( array( 'message' => __( 'Please provide an endpoint URL.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Get timeout from settings.
			// Use a minimum of 30 seconds for connection tests to local providers.
			// Local network connections may have higher latency than localhost.
			$settings     = WP_MCP_AI_Admin_Settings::get_settings();
			$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
			// Use ignore_execution_time=true for external HTTP requests to local AI providers.
			// since these don't consume PHP execution time while waiting.
			$timeout = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : $resource_mgr->get_request_timeout( true );
			$timeout = max( 30, $timeout );

			// Ensure PHP execution time is sufficient for the HTTP request timeout.
			// Add 10 second buffer to prevent "Maximum execution time exceeded" errors.
			$resource_mgr->ensure_execution_time( $timeout + 10 );

			$api_url  = trailingslashit( $endpoint_url ) . 'api/tags';
			$response = wp_remote_get( $api_url, array( 'timeout' => $timeout ) );

			if ( is_wp_error( $response ) ) {
				wp_send_json_error( array( 'message' => __( 'Failed to fetch models.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( ! isset( $data['models'] ) || ! is_array( $data['models'] ) ) {
				wp_send_json_error( array( 'message' => __( 'No models found or invalid response.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			$models = array();
			foreach ( $data['models'] as $model ) {
				if ( isset( $model['name'] ) ) {
					$models[] = array(
						'name'   => $model['name'],
						'size'   => isset( $model['size'] ) ? $model['size'] : 0,
						'family' => isset( $model['details']['family'] ) ? $model['details']['family'] : '',
					);
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
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			$endpoint_url = isset( $_POST['endpoint_url'] ) ? esc_url_raw( wp_unslash( $_POST['endpoint_url'] ) ) : '';

			if ( empty( $endpoint_url ) ) {
				wp_send_json_error( array( 'message' => __( 'Please provide an endpoint URL.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Get timeout from settings.
			// Use a minimum of 30 seconds for connection tests to local providers.
			// Local network connections may have higher latency than localhost.
			$settings     = WP_MCP_AI_Admin_Settings::get_settings();
			$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
			// Use ignore_execution_time=true for external HTTP requests to local AI providers.
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
							__( 'Connection failed: %s', 'mcp-ai-wpoos' ),
							$response->get_error_message()
						),
					)
				);
				return;
			}

			$response_code = wp_remote_retrieve_response_code( $response );

			if ( 200 !== $response_code ) {
				wp_send_json_error( array( 'message' => __( 'Invalid endpoint or connection failed.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			wp_send_json_success( array( 'message' => __( 'Successfully connected to LM Studio!', 'mcp-ai-wpoos' ) ) );
		}

		/**
		 * Handle AJAX request to fetch LM Studio models.
		 */
		public function handle_fetch_lm_studio_models() {
			check_ajax_referer( 'wp-mcp-ai-settings', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			$endpoint_url = isset( $_POST['endpoint_url'] ) ? esc_url_raw( wp_unslash( $_POST['endpoint_url'] ) ) : '';

			if ( empty( $endpoint_url ) ) {
				wp_send_json_error( array( 'message' => __( 'Please provide an endpoint URL.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Get timeout from settings.
			// Use a minimum of 30 seconds for connection tests to local providers.
			// Local network connections may have higher latency than localhost.
			$settings     = WP_MCP_AI_Admin_Settings::get_settings();
			$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
			// Use ignore_execution_time=true for external HTTP requests to local AI providers.
			// since these don't consume PHP execution time while waiting.
			$timeout = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : $resource_mgr->get_request_timeout( true );
			$timeout = max( 30, $timeout );

			// Ensure PHP execution time is sufficient for the HTTP request timeout.
			// Add 10 second buffer to prevent "Maximum execution time exceeded" errors.
			$resource_mgr->ensure_execution_time( $timeout + 10 );

			$api_url  = trailingslashit( $endpoint_url ) . 'v1/models';
			$response = wp_remote_get( $api_url, array( 'timeout' => $timeout ) );

			if ( is_wp_error( $response ) ) {
				wp_send_json_error( array( 'message' => __( 'Failed to fetch models.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			$body = wp_remote_retrieve_body( $response );
			$data = json_decode( $body, true );

			if ( ! isset( $data['data'] ) || ! is_array( $data['data'] ) ) {
				wp_send_json_error( array( 'message' => __( 'No models found or invalid response.', 'mcp-ai-wpoos' ) ) );
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
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
			$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

			if ( empty( $email ) || empty( $api_key ) ) {
				wp_send_json_error( array( 'message' => __( 'Please provide both email and API key.', 'mcp-ai-wpoos' ) ) );
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
				wp_send_json_error( array( 'message' => __( 'Failed to connect to Cloudways API: ', 'mcp-ai-wpoos' ) . $oauth_response->get_error_message() ) );
				return;
			}

			$oauth_code = wp_remote_retrieve_response_code( $oauth_response );
			$oauth_data = json_decode( wp_remote_retrieve_body( $oauth_response ), true );

			if ( 200 !== $oauth_code || empty( $oauth_data['access_token'] ) ) {
				$error_message = ! empty( $oauth_data['message'] ) ? $oauth_data['message'] : __( 'Invalid credentials.', 'mcp-ai-wpoos' );
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
				wp_send_json_error( array( 'message' => __( 'Failed to fetch servers: ', 'mcp-ai-wpoos' ) . $servers_response->get_error_message() ) );
				return;
			}

			$servers_code = wp_remote_retrieve_response_code( $servers_response );
			$servers_data = json_decode( wp_remote_retrieve_body( $servers_response ), true );

			if ( 200 !== $servers_code || empty( $servers_data['servers'] ) ) {
				wp_send_json_error( array( 'message' => __( 'No servers found or failed to fetch servers.', 'mcp-ai-wpoos' ) ) );
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
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			$zone_id   = isset( $_POST['zone_id'] ) ? sanitize_text_field( wp_unslash( $_POST['zone_id'] ) ) : '';
			$api_token = isset( $_POST['api_token'] ) ? sanitize_text_field( wp_unslash( $_POST['api_token'] ) ) : '';

			if ( empty( $zone_id ) || empty( $api_token ) ) {
				wp_send_json_error( array( 'message' => __( 'Please provide both Zone ID and API Token.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Validate Zone ID format (should be 32 hexadecimal characters).
			if ( ! preg_match( '/^[a-f0-9]{32}$/i', $zone_id ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid Zone ID format. Zone ID should be a 32-character hexadecimal string.', 'mcp-ai-wpoos' ) ) );
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
							__( 'Connection failed: %s', 'mcp-ai-wpoos' ),
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
				$error_message = __( 'Invalid credentials or zone not found.', 'mcp-ai-wpoos' );
				if ( isset( $data['errors'][0]['message'] ) ) {
					$error_message = sanitize_text_field( $data['errors'][0]['message'] );
				}
				wp_send_json_error( array( 'message' => $error_message ) );
				return;
			}

			if ( ! isset( $data['success'] ) || ! $data['success'] || ! isset( $data['result'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Unexpected response from Cloudflare API.', 'mcp-ai-wpoos' ) ) );
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
					'message'   => __( 'Successfully connected to Cloudflare!', 'mcp-ai-wpoos' ),
					'zone_info' => $zone_info,
				)
			);
		}

		/**
		 * Handle AJAX request to test Cloudways connection.
		 */
		public function handle_test_cloudways_connection() {
			check_ajax_referer( 'wp-mcp-ai-settings', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
			$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

			if ( empty( $email ) || empty( $api_key ) ) {
				wp_send_json_error( array( 'message' => __( 'Please provide both email and API key.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Get timeout from settings.
			$settings     = WP_MCP_AI_Admin_Settings::get_settings();
			$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
			$timeout      = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : $resource_mgr->get_request_timeout();
			$timeout      = max( 5, $timeout );

			// Step 1: Exchange email + API key for OAuth access token.
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
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: error message */
							__( 'Connection failed: %s', 'mcp-ai-wpoos' ),
							$oauth_response->get_error_message()
						),
					)
				);
				return;
			}

			$oauth_code = wp_remote_retrieve_response_code( $oauth_response );
			$oauth_body = wp_remote_retrieve_body( $oauth_response );
			$oauth_data = json_decode( $oauth_body, true );

			if ( 200 !== $oauth_code ) {
				$error_message = __( 'Invalid credentials.', 'mcp-ai-wpoos' );
				if ( ! empty( $oauth_data['message'] ) ) {
					$error_message = sanitize_text_field( $oauth_data['message'] );
				}
				wp_send_json_error( array( 'message' => $error_message ) );
				return;
			}

			if ( empty( $oauth_data['access_token'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Failed to obtain access token.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			$access_token = $oauth_data['access_token'];

			// Step 2: Verify the token by fetching account servers.
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
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: error message */
							__( 'Token obtained but failed to verify account: %s', 'mcp-ai-wpoos' ),
							$servers_response->get_error_message()
						),
					)
				);
				return;
			}

			$servers_code = wp_remote_retrieve_response_code( $servers_response );
			$servers_body = wp_remote_retrieve_body( $servers_response );
			$servers_data = json_decode( $servers_body, true );

			if ( 200 !== $servers_code ) {
				wp_send_json_error( array( 'message' => __( 'Token obtained but failed to verify account access.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Prepare account information.
			$server_count = 0;
			if ( ! empty( $servers_data['servers'] ) && is_array( $servers_data['servers'] ) ) {
				$server_count = count( $servers_data['servers'] );
			}

			$account_info = array(
				'server_count' => $server_count,
				'email'        => $email,
			);

			wp_send_json_success(
				array(
					'message'      => __( 'Successfully connected to Cloudways!', 'mcp-ai-wpoos' ),
					'account_info' => $account_info,
				)
			);
		}

		/**
		 * Handle AJAX request to test Brave Search API connection.
		 */
		public function handle_test_brave_search_connection() {
			check_ajax_referer( 'wp-mcp-ai-settings', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

			if ( empty( $api_key ) ) {
				wp_send_json_error( array( 'message' => __( 'Please provide a Brave Search API key.', 'mcp-ai-wpoos' ) ) );
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
							__( 'Connection failed: %s', 'mcp-ai-wpoos' ),
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
				wp_send_json_error( array( 'message' => __( 'Invalid API key. Please check your Brave Search API key.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			if ( 429 === $response_code ) {
				wp_send_json_error( array( 'message' => __( 'Rate limit exceeded. Your API key is valid but you have exceeded your rate limit.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Handle HTTP 202 (Accepted) - search is being processed asynchronously.
			// This is still a valid response indicating the API key works.
			if ( 202 === $response_code ) {
				wp_send_json_success(
					array(
						'message' => __( 'Successfully connected to Brave Search API! (Search processing asynchronously)', 'mcp-ai-wpoos' ),
					)
				);
				return;
			}

			if ( 200 !== $response_code ) {
				$error_message = __( 'Invalid API key or connection failed.', 'mcp-ai-wpoos' );
				if ( isset( $data['message'] ) ) {
					$error_message = sanitize_text_field( $data['message'] );
				}
				wp_send_json_error( array( 'message' => $error_message ) );
				return;
			}

			wp_send_json_success(
				array(
					'message' => __( 'Successfully connected to Brave Search API!', 'mcp-ai-wpoos' ),
				)
			);
		}

		/**
		 * Handle AJAX request to test Tavily API connection.
		 */
		public function handle_test_tavily_connection() {
			check_ajax_referer( 'wp-mcp-ai-settings', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

			if ( empty( $api_key ) ) {
				wp_send_json_error( array( 'message' => __( 'Please provide a Tavily API key.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Get timeout from settings.
			$settings     = WP_MCP_AI_Admin_Settings::get_settings();
			$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
			$timeout      = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : $resource_mgr->get_request_timeout();
			$timeout      = max( 5, $timeout );

			// Test the Tavily connection by making a minimal search request.
			$api_url = 'https://api.tavily.com/search';

			$response = wp_remote_post(
				$api_url,
				array(
					'headers' => array(
						'Content-Type'  => 'application/json',
						'Authorization' => 'Bearer ' . $api_key,
					),
					'body'    => wp_json_encode(
						array(
							'query'       => 'test',
							'max_results' => 1,
						)
					),
					'timeout' => $timeout,
				)
			);

			if ( is_wp_error( $response ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: error message */
							__( 'Connection failed: %s', 'mcp-ai-wpoos' ),
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
				wp_send_json_error( array( 'message' => __( 'Invalid API key. Please check your Tavily API key.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			if ( 429 === $response_code ) {
				wp_send_json_error( array( 'message' => __( 'Rate limit exceeded. Your API key is valid but you have exceeded your rate limit.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			if ( 200 !== $response_code ) {
				$error_message = __( 'Invalid API key or connection failed.', 'mcp-ai-wpoos' );
				if ( isset( $data['message'] ) ) {
					$error_message = sanitize_text_field( $data['message'] );
				} elseif ( isset( $data['detail'] ) ) {
					$error_message = sanitize_text_field( $data['detail'] );
				}
				wp_send_json_error( array( 'message' => $error_message ) );
				return;
			}

			wp_send_json_success(
				array(
					'message' => __( 'Successfully connected to Tavily API!', 'mcp-ai-wpoos' ),
				)
			);
		}

		/**
		 * Handle AJAX request to test Mubert API connection.
		 */
		public function handle_test_mubert_connection() {
			check_ajax_referer( 'wp-mcp-ai-settings', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

			if ( empty( $api_key ) ) {
				wp_send_json_error( array( 'message' => __( 'Please provide a Mubert API key.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Load the Mubert service.
			require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-mubert-music-service.php';

			// Create service instance with test API key.
			$service = new WP_MCP_AI_Mubert_Music_Service();

			// Temporarily override the API key for testing.
			$original_settings               = WP_MCP_AI_Admin_Settings::get_settings();
			$test_settings                   = $original_settings;
			$test_settings['mubert_api_key'] = $api_key;
			update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $test_settings );

			// Test the connection.
			$result = $service->test_connection();

			// Restore original settings.
			update_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, $original_settings );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error(
					array(
						'message' => $result->get_error_message(),
					)
				);
				return;
			}

			wp_send_json_success(
				array(
					'message' => $result['message'],
				)
			);
		}

		/**
		 * Handle AJAX request to test Plaid API connection.
		 *
		 * Tests the Plaid connection by attempting to create a link token, which validates
		 * the client ID and secret without requiring actual bank linking.
		 */
		public function handle_test_plaid_connection() {
			check_ajax_referer( 'wp-mcp-ai-settings', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			$client_id   = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
			$secret      = isset( $_POST['secret'] ) ? sanitize_text_field( wp_unslash( $_POST['secret'] ) ) : '';
			$environment = isset( $_POST['environment'] ) ? sanitize_text_field( wp_unslash( $_POST['environment'] ) ) : 'sandbox';

			if ( empty( $client_id ) || empty( $secret ) ) {
				wp_send_json_error( array( 'message' => __( 'Please provide both Plaid Client ID and Secret.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Validate environment.
			if ( ! in_array( $environment, array( 'sandbox', 'development', 'production' ), true ) ) {
				$environment = 'sandbox';
			}

			// Determine Plaid API base URL based on environment.
			$base_urls = array(
				'sandbox'     => 'https://sandbox.plaid.com',
				'development' => 'https://development.plaid.com',
				'production'  => 'https://production.plaid.com',
			);

			$base_url = $base_urls[ $environment ];

			// Get timeout from settings.
			$settings     = WP_MCP_AI_Admin_Settings::get_settings();
			$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
			$timeout      = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : $resource_mgr->get_request_timeout();
			$timeout      = max( 10, $timeout );

			// Test the connection by creating a link token.
			// This validates credentials without requiring actual bank linking.
			$api_url = $base_url . '/link/token/create';

			$body = wp_json_encode(
				array(
					'client_id'     => $client_id,
					'secret'        => $secret,
					'user'          => array(
						'client_user_id' => 'test_user_' . uniqid(),
					),
					'client_name'   => get_bloginfo( 'name' ),
					'products'      => array( 'transactions' ),
					'country_codes' => array( 'US' ),
					'language'      => 'en',
				)
			);

			$response = wp_remote_post(
				$api_url,
				array(
					'headers' => array(
						'Content-Type' => 'application/json',
					),
					'body'    => $body,
					'timeout' => $timeout,
				)
			);

			if ( is_wp_error( $response ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: Error message */
							__( 'Connection failed: %s', 'mcp-ai-wpoos' ),
							$response->get_error_message()
						),
					)
				);
				return;
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
			$response_data = json_decode( $response_body, true );

			if ( 200 === $response_code && isset( $response_data['link_token'] ) ) {
				wp_send_json_success(
					array(
						'message' => sprintf(
							/* translators: %s: Environment name */
							__( 'Successfully connected to Plaid %s environment! Your credentials are valid.', 'mcp-ai-wpoos' ),
							ucfirst( $environment )
						),
					)
				);
				return;
			}

			// Handle error response.
			$error_message = __( 'Invalid credentials or connection error.', 'mcp-ai-wpoos' );

			if ( isset( $response_data['error_message'] ) ) {
				$error_message = sanitize_text_field( $response_data['error_message'] );
			} elseif ( isset( $response_data['error_code'] ) ) {
				$error_message = sprintf(
					/* translators: %s: Error code */
					__( 'Plaid error: %s', 'mcp-ai-wpoos' ),
					sanitize_text_field( $response_data['error_code'] )
				);
			}

			wp_send_json_error(
				array(
					'message'       => $error_message,
					'response_code' => $response_code,
				)
			);
		}

		/**
		 * Handle AJAX request to test Yahoo Sports API connection.
		 *
		 * Note: This performs basic validation only. Yahoo uses OAuth 1.0a which requires
		 * request signing for full API validation. Complete OAuth verification is handled
		 * by the Yahoo FF Auth tool during the actual authentication flow.
		 */
		public function handle_test_yahoo_connection() {
			check_ajax_referer( 'wp-mcp-ai-settings', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			$client_id     = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
			$client_secret = isset( $_POST['client_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['client_secret'] ) ) : '';

			if ( empty( $client_id ) || empty( $client_secret ) ) {
				wp_send_json_error( array( 'message' => __( 'Please provide both Yahoo Client ID and Client Secret.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Perform basic format validation: Check minimum credential length.
			// Yahoo credentials are typically 50+ characters, but we use a conservative check.
			if ( strlen( $client_id ) < 10 ) {
				wp_send_json_error( array( 'message' => __( 'Yahoo Client ID appears to be too short. Please check your credentials.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			if ( strlen( $client_secret ) < 10 ) {
				wp_send_json_error( array( 'message' => __( 'Yahoo Client Secret appears to be too short. Please check your credentials.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Basic validation passed - credentials format looks correct.
			// Full OAuth 1.0a validation (which requires request signing) happens during
			// the actual authentication flow via the Yahoo FF Auth tool.
			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: 1: Client ID length, 2: Client Secret length */
						__( 'Credentials format validated (Client ID: %1$d chars, Secret: %2$d chars). Use the Yahoo Fantasy Football tools to complete OAuth authentication.', 'mcp-ai-wpoos' ),
						strlen( $client_id ),
						strlen( $client_secret )
					),
					'note'    => __( 'Note: These credentials passed format validation. Full OAuth verification occurs during authentication via the Yahoo FF Auth tool.', 'mcp-ai-wpoos' ),
				)
			);
		}

		/**
		 * Handle AJAX request to test remove.bg API connection.
		 */
		public function handle_test_removebg_connection() {
			check_ajax_referer( 'wp-mcp-ai-settings', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

			if ( empty( $api_key ) ) {
				wp_send_json_error( array( 'message' => __( 'Please provide a remove.bg API key.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Get timeout from settings.
			$settings     = WP_MCP_AI_Admin_Settings::get_settings();
			$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
			$timeout      = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : $resource_mgr->get_request_timeout();
			$timeout      = max( 5, $timeout );

			// Test the connection by checking account info endpoint.
			$account_url = 'https://api.remove.bg/v1.0/account';

			$response = wp_remote_get(
				$account_url,
				array(
					'headers' => array(
						'X-Api-Key' => $api_key,
					),
					'timeout' => $timeout,
				)
			);

			if ( is_wp_error( $response ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: error message */
							__( 'Connection failed: %s', 'mcp-ai-wpoos' ),
							$response->get_error_message()
						),
					)
				);
				return;
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
			$data          = json_decode( $response_body, true );

			if ( 403 === $response_code || 401 === $response_code ) {
				wp_send_json_error( array( 'message' => __( 'Invalid API key. Please check your remove.bg API key.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			if ( 429 === $response_code ) {
				wp_send_json_error( array( 'message' => __( 'Rate limit exceeded. Your API key is valid but you have exceeded your rate limit.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			if ( 200 !== $response_code ) {
				$error_message = __( 'Invalid API key or connection failed.', 'mcp-ai-wpoos' );
				if ( isset( $data['errors'] ) && is_array( $data['errors'] ) && ! empty( $data['errors'][0]['title'] ) ) {
					$error_message = sanitize_text_field( $data['errors'][0]['title'] );
				}
				wp_send_json_error( array( 'message' => $error_message ) );
				return;
			}

			// Success - optionally include account info.
			$success_message = __( 'Successfully connected to remove.bg API!', 'mcp-ai-wpoos' );
			if ( isset( $data['data']['attributes']['credits']['total'] ) ) {
				$credits          = absint( $data['data']['attributes']['credits']['total'] );
				$success_message .= ' ' . sprintf(
					/* translators: %d: number of API credits */
					__( 'Account has %d API credits remaining.', 'mcp-ai-wpoos' ),
					$credits
				);
			}

			wp_send_json_success(
				array(
					'message' => $success_message,
				)
			);
		}

		/**
		 * Handle AJAX request to test Flowhub connection.
		 */
		public function handle_test_flowhub_connection() {
			check_ajax_referer( 'wp-mcp-ai-settings', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Flowhub uses simple header-based authentication (clientId and key headers).
			$client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
			$api_key   = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';

			if ( empty( $client_id ) || empty( $api_key ) ) {
				wp_send_json_error( array( 'message' => __( 'Please provide Flowhub Client ID and API Key.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Get timeout from settings.
			$settings     = WP_MCP_AI_Admin_Settings::get_settings();
			$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
			$timeout      = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : $resource_mgr->get_request_timeout();
			$timeout      = max( 5, $timeout );

			// Test API access with inventory endpoint.
			$inventory_url = 'https://api.flowhub.co/v0/inventoryNonZero';

			$api_response = wp_remote_get(
				$inventory_url,
				array(
					'headers' => array(
						'clientId'     => $client_id,
						'key'          => $api_key,
						'Accept'       => 'application/json',
						'Content-Type' => 'application/json',
					),
					'timeout' => $timeout,
				)
			);

			if ( is_wp_error( $api_response ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: error message */
							__( 'API connection failed: %s', 'mcp-ai-wpoos' ),
							$api_response->get_error_message()
						),
					)
				);
				return;
			}

			$api_code = wp_remote_retrieve_response_code( $api_response );
			$api_body = wp_remote_retrieve_body( $api_response );
			$api_data = json_decode( $api_body, true );

			if ( 401 === $api_code || 403 === $api_code ) {
				wp_send_json_error( array( 'message' => __( 'Invalid Client ID or API Key. Please check your credentials.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			if ( 429 === $api_code ) {
				wp_send_json_error( array( 'message' => __( 'Rate limit exceeded. Your credentials are valid but you have exceeded your rate limit.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			if ( 200 !== $api_code && 204 !== $api_code ) {
				$error_message = __( 'Invalid API credentials or connection failed.', 'mcp-ai-wpoos' );
				if ( isset( $api_data['message'] ) ) {
					$error_message = sanitize_text_field( $api_data['message'] );
				}
				wp_send_json_error( array( 'message' => $error_message ) );
				return;
			}

			// Flowhub wraps responses in { "status": 200, "data": [...] } format.
			// Extract the data array if present.
			$inventory_data = $api_data;
			if ( isset( $api_data['data'] ) && is_array( $api_data['data'] ) ) {
				$inventory_data = $api_data['data'];
			}

			// Success message with inventory count if available.
			$message = __( 'Successfully connected to Flowhub API!', 'mcp-ai-wpoos' );
			if ( is_array( $inventory_data ) && count( $inventory_data ) > 0 ) {
				$message = sprintf(
					/* translators: %d: number of inventory items */
					__( 'Successfully connected to Flowhub API! Found %d inventory items.', 'mcp-ai-wpoos' ),
					count( $inventory_data )
				);
			} elseif ( 204 === $api_code ) {
				$message = __( 'Successfully connected to Flowhub API! (No inventory items found)', 'mcp-ai-wpoos' );
			}

			wp_send_json_success( array( 'message' => $message ) );
		}

		/**
		 * Handle AJAX request to test iSAMS connection.
		 */
		public function handle_test_isams_connection() {
			check_ajax_referer( 'wp-mcp-ai-settings', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			$api_url    = isset( $_POST['api_url'] ) ? esc_url_raw( wp_unslash( $_POST['api_url'] ) ) : '';
			$api_key    = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
			$api_secret = isset( $_POST['api_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['api_secret'] ) ) : '';

			if ( empty( $api_url ) || empty( $api_key ) || empty( $api_secret ) ) {
				wp_send_json_error( array( 'message' => __( 'Please provide all required iSAMS credentials (URL, API Key, and API Secret).', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Ensure URL has trailing slash.
			$api_url = trailingslashit( $api_url );

			// Get timeout from settings.
			$settings     = WP_MCP_AI_Admin_Settings::get_settings();
			$resource_mgr = WP_MCP_AI_Resource_Manager::instance();
			$timeout      = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : $resource_mgr->get_request_timeout();
			$timeout      = max( 5, $timeout );

			// Test authentication by requesting a token.
			$auth_url = $api_url . 'api/authentication/token';

			$response = wp_remote_post(
				$auth_url,
				array(
					'headers' => array(
						'Content-Type' => 'application/json',
					),
					'body'    => wp_json_encode(
						array(
							'apiKey'    => $api_key,
							'apiSecret' => $api_secret,
						)
					),
					'timeout' => $timeout,
				)
			);

			if ( is_wp_error( $response ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: error message */
							__( 'Connection failed: %s', 'mcp-ai-wpoos' ),
							$response->get_error_message()
						),
					)
				);
				return;
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );

			if ( 200 !== $response_code ) {
				$error_message = __( 'Invalid credentials or connection failed.', 'mcp-ai-wpoos' );

				// Try to get error from response.
				$data = json_decode( $response_body, true );
				if ( isset( $data['message'] ) ) {
					$error_message = sanitize_text_field( $data['message'] );
				} elseif ( 401 === $response_code ) {
					$error_message = __( 'Authentication failed. Please check your API Key and Secret.', 'mcp-ai-wpoos' );
				} elseif ( 404 === $response_code ) {
					$error_message = __( 'API endpoint not found. Please check your iSAMS URL.', 'mcp-ai-wpoos' );
				}

				wp_send_json_error( array( 'message' => $error_message ) );
				return;
			}

			$data = json_decode( $response_body, true );

			if ( empty( $data['token'] ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid response from iSAMS API.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Test a simple API call with the token to verify it works.
			$test_url      = $api_url . 'api/school/terms';
			$test_response = wp_remote_get(
				add_query_arg(
					array(
						'page'     => 1,
						'pageSize' => 1,
					),
					$test_url
				),
				array(
					'headers' => array(
						'Authorization' => 'Bearer ' . $data['token'],
						'Accept'        => 'application/json',
					),
					'timeout' => $timeout,
				)
			);

			if ( is_wp_error( $test_response ) ) {
				wp_send_json_success(
					array(
						'message' => __( 'Successfully authenticated with iSAMS! (Warning: Test query failed, but credentials are valid)', 'mcp-ai-wpoos' ),
					)
				);
				return;
			}

			$test_code = wp_remote_retrieve_response_code( $test_response );

			if ( 200 === $test_code ) {
				wp_send_json_success(
					array(
						'message' => __( 'Successfully connected to iSAMS! All credentials are working correctly.', 'mcp-ai-wpoos' ),
					)
				);
			} else {
				wp_send_json_success(
					array(
						'message' => __( 'Successfully authenticated with iSAMS! (Note: Some API endpoints may require additional permissions)', 'mcp-ai-wpoos' ),
					)
				);
			}
		}

		/**
		 * Handle AJAX request to reset user's token usage.
		 */
		public function handle_reset_user_token_usage() {
			// Check capabilities.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Verify nonce.
			if ( ! check_ajax_referer( 'wp_mcp_ai_dashboard', 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Get user ID from request.
			$user_id = isset( $_POST['user_id'] ) ? absint( $_POST['user_id'] ) : get_current_user_id();

			if ( ! $user_id ) {
				wp_send_json_error( array( 'message' => __( 'Invalid user ID.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Verify user exists.
			if ( ! get_userdata( $user_id ) ) {
				wp_send_json_error( array( 'message' => __( 'User not found.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			delete_user_meta( $user_id, WP_MCP_AI_Usage_Tracker::USER_META_KEY );

			// Reset tool-specific token usage data.
			WP_MCP_AI_Tool_Token_Limits::reset_user_tool_usage( $user_id );

			wp_send_json_success( array( 'message' => __( 'Token usage data has been reset.', 'mcp-ai-wpoos' ) ) );
		}

		/**
		 * Handle AJAX request to reset all users' token usage.
		 */
		public function handle_reset_all_token_usage() {
			global $wpdb;

			// Check capabilities.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Verify nonce.
			if ( ! check_ajax_referer( 'wp_mcp_ai_dashboard', 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'mcp-ai-wpoos' ) ) );
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
				array( 'meta_key' => $meta_key ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- meta_key lookup required to find plugin-specific user meta; no alternative lookup method available.
				array( '%s' )
			);

			if ( false === $deleted ) {
				wp_send_json_error( array( 'message' => __( 'Failed to reset token usage data.', 'mcp-ai-wpoos' ) ) );
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
				array( 'meta_key' => $tool_meta_key ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- meta_key lookup required to find plugin-specific user meta; no alternative lookup method available.
				array( '%s' )
			);

			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: %d: number of records deleted */
						__( 'Token usage data has been reset for all users. %d records deleted.', 'mcp-ai-wpoos' ),
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
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Verify nonce.
			if ( ! check_ajax_referer( 'wp_mcp_ai_dashboard', 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Get limits, multipliers, and model preferences from request.
			// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitization handled by setter methods.
			$limits            = isset( $_POST['limits'] ) ? wp_unslash( $_POST['limits'] ) : array();
			$multipliers       = isset( $_POST['multipliers'] ) ? wp_unslash( $_POST['multipliers'] ) : array();
			$model_preferences = isset( $_POST['model_preferences'] ) ? wp_unslash( $_POST['model_preferences'] ) : array();
			// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

			if ( ! is_array( $limits ) || ! is_array( $multipliers ) || ! is_array( $model_preferences ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid data format.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			if ( empty( $limits ) && empty( $multipliers ) && empty( $model_preferences ) ) {
				wp_send_json_error( array( 'message' => __( 'No limits, multipliers, or model preferences provided.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			$changed_count = 0;

			// Check if any limits have actually changed.
			// Note: We sanitize here only for comparison, not for saving.
			// The setter methods will do final sanitization.
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
						'message'    => __( 'No changes detected. All tool settings are already set to the specified values.', 'mcp-ai-wpoos' ),
						'no_changes' => true,
					)
				);
				return;
			}

			// Save each limit.
			// Note: Setter methods handle sanitization, so we pass unsanitized data directly.
			$saved_count = 0;
			foreach ( $limits as $tool_slug => $limit ) {
				if ( WP_MCP_AI_Tool_Token_Limits::set_tool_limit( $tool_slug, $limit ) ) {
					++$saved_count;
				}
			}

			// Save each multiplier.
			foreach ( $multipliers as $tool_slug => $multiplier ) {
				if ( WP_MCP_AI_Tool_Token_Limits::set_tool_multiplier( $tool_slug, $multiplier ) ) {
					++$saved_count;
				}
			}

			// Save each model preference.
			foreach ( $model_preferences as $tool_slug => $model ) {
				if ( WP_MCP_AI_Tool_Token_Limits::set_tool_model_preference( $tool_slug, $model ) ) {
					++$saved_count;
				}
			}

			if ( $saved_count > 0 ) {
				wp_send_json_success(
					array(
						'message' => sprintf(
							/* translators: %d: number of settings saved */
							__( 'Tool settings saved successfully. %d settings updated.', 'mcp-ai-wpoos' ),
							$saved_count
						),
					)
				);
			} else {
				wp_send_json_error( array( 'message' => __( 'Failed to save tool settings.', 'mcp-ai-wpoos' ) ) );
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
				wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Get preset ID.
			$preset_id = isset( $_POST['preset_id'] ) ? sanitize_key( wp_unslash( $_POST['preset_id'] ) ) : '';

			if ( empty( $preset_id ) ) {
				wp_send_json_error( array( 'message' => __( 'Missing preset ID.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Check if preset service exists.
			if ( ! class_exists( 'WP_MCP_AI_Orchestration_Preset_Service' ) ) {
				wp_send_json_error( array( 'message' => __( 'Preset service not available.', 'mcp-ai-wpoos' ) ) );
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
					'message'   => __( 'Preset applied successfully.', 'mcp-ai-wpoos' ),
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
				wp_die( esc_html__( 'Insufficient permissions.', 'mcp-ai-wpoos' ) );
			}

			// Verify nonce.
			if ( ! check_ajax_referer( 'wp_mcp_ai_dashboard', 'nonce', false ) ) {
				wp_die( esc_html__( 'Invalid security token.', 'mcp-ai-wpoos' ) );
			}

			// Get filters from request.
			$filters = array();
			if ( isset( $_POST['tier'] ) && '' !== $_POST['tier'] ) {
				$filters['tier'] = sanitize_key( wp_unslash( $_POST['tier'] ) );
			}
			if ( isset( $_POST['tool'] ) && '' !== $_POST['tool'] ) {
				$filters['tool'] = sanitize_key( wp_unslash( $_POST['tool'] ) );
			}

			// Generate CSV content.
			$csv = WP_MCP_AI_Tool_Token_Limits::export_usage_report( $filters );

			if ( empty( $csv ) ) {
				wp_die( esc_html__( 'Failed to generate CSV export.', 'mcp-ai-wpoos' ) );
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
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Verify nonce.
			if ( ! check_ajax_referer( 'wp_mcp_ai_dashboard', 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Get user IDs from request.
			$user_ids = isset( $_POST['user_ids'] ) ? array_map( 'absint', (array) $_POST['user_ids'] ) : array();

			if ( empty( $user_ids ) ) {
				wp_send_json_error( array( 'message' => __( 'No users selected.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Get tier from request.
			$tier = isset( $_POST['tier'] ) ? sanitize_key( wp_unslash( $_POST['tier'] ) ) : '';

			if ( empty( $tier ) ) {
				wp_send_json_error( array( 'message' => __( 'No tier specified.', 'mcp-ai-wpoos' ) ) );
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
						__( 'Successfully updated %1$d users to %2$s tier.', 'mcp-ai-wpoos' ),
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
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Verify nonce.
			if ( ! check_ajax_referer( 'wp_mcp_ai_dashboard', 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Apply all recommendations.
			$results = WP_MCP_AI_Tool_Recommendations::apply_all_recommendations();

			if ( $results['failed'] > 0 ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: 1: Number of successful updates, 2: Number of failed updates, 3: Number skipped */
							__( 'Applied recommendations: %1$d succeeded, %2$d failed, %3$d skipped (already optimal).', 'mcp-ai-wpoos' ),
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
						__( 'Successfully applied recommended settings to %1$d tools. %2$d tools were already using recommended settings.', 'mcp-ai-wpoos' ),
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
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Verify nonce.
			if ( ! check_ajax_referer( 'wp_mcp_ai_dashboard', 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Get preset from request.
			$preset = isset( $_POST['preset'] ) ? sanitize_key( wp_unslash( $_POST['preset'] ) ) : 'balanced';

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
							__( 'Preset applied with some errors: %1$d succeeded, %2$d failed.', 'mcp-ai-wpoos' ),
							$results['success'],
							$results['failed']
						),
						'results' => $results,
					)
				);
				return;
			}

			$preset_names = array(
				'conservative' => __( 'Conservative', 'mcp-ai-wpoos' ),
				'balanced'     => __( 'Balanced', 'mcp-ai-wpoos' ),
				'performance'  => __( 'Performance', 'mcp-ai-wpoos' ),
				'aggressive'   => __( 'Aggressive', 'mcp-ai-wpoos' ),
			);

			wp_send_json_success(
				array(
					'message' => sprintf(
						/* translators: 1: Preset name, 2: Number of tools updated */
						__( 'Successfully applied %1$s preset to %2$d tools!', 'mcp-ai-wpoos' ),
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
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Check capabilities.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Get days parameter.
			$days = isset( $_POST['days'] ) ? absint( $_POST['days'] ) : 7;

			// Get chart data.
			if ( ! class_exists( 'WP_MCP_AI_Chart_JS_Helper' ) ) {
				wp_send_json_error( array( 'message' => __( 'Chart helper class not found.', 'mcp-ai-wpoos' ) ) );
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
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Check capabilities.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Get chart data.
			if ( ! class_exists( 'WP_MCP_AI_Chart_JS_Helper' ) ) {
				wp_send_json_error( array( 'message' => __( 'Chart helper class not found.', 'mcp-ai-wpoos' ) ) );
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
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Check capabilities.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Get chart data.
			if ( ! class_exists( 'WP_MCP_AI_Chart_JS_Helper' ) ) {
				wp_send_json_error( array( 'message' => __( 'Chart helper class not found.', 'mcp-ai-wpoos' ) ) );
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
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Check capabilities.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Get chart data.
			if ( ! class_exists( 'WP_MCP_AI_Chart_JS_Helper' ) ) {
				wp_send_json_error( array( 'message' => __( 'Chart helper class not found.', 'mcp-ai-wpoos' ) ) );
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
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Check capabilities.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Get chart data.
			if ( ! class_exists( 'WP_MCP_AI_Chart_JS_Helper' ) ) {
				wp_send_json_error( array( 'message' => __( 'Chart helper class not found.', 'mcp-ai-wpoos' ) ) );
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
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Check capabilities.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Get chart ID and period.
			$chart_id = isset( $_POST['chart_id'] ) ? sanitize_key( wp_unslash( $_POST['chart_id'] ) ) : '';
			$period   = isset( $_POST['period'] ) ? absint( wp_unslash( $_POST['period'] ) ) : 7;

			// Validate chart ID.
			$valid_charts = array(
				'wp-mcp-ai-dashboard-usage-trend',
				'wp-mcp-ai-usage-trend-chart',
				'wp-mcp-ai-tool-breakdown-chart',
			);

			if ( ! in_array( $chart_id, $valid_charts, true ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid chart ID.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Get updated data based on chart type (following SoC - delegate to helper).
			if ( ! class_exists( 'WP_MCP_AI_Chart_JS_Helper' ) ) {
				wp_send_json_error( array( 'message' => __( 'Chart helper class not found.', 'mcp-ai-wpoos' ) ) );
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
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Check capabilities.
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Get chart ID.
			$chart_id = isset( $_POST['chart_id'] ) ? sanitize_key( wp_unslash( $_POST['chart_id'] ) ) : '';

			// Validate chart ID.
			$valid_charts = array(
				'wp-mcp-ai-dashboard-usage-trend',
				'wp-mcp-ai-usage-trend-chart',
				'wp-mcp-ai-tier-distribution-chart',
				'wp-mcp-ai-tool-breakdown-chart',
				'wp-mcp-ai-dashboard-cost-breakdown',
			);

			if ( ! in_array( $chart_id, $valid_charts, true ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid chart ID.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Get fresh data based on chart type (following SoC - delegate to helper).
			if ( ! class_exists( 'WP_MCP_AI_Chart_JS_Helper' ) ) {
				wp_send_json_error( array( 'message' => __( 'Chart helper class not found.', 'mcp-ai-wpoos' ) ) );
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
						'message' => __( 'You do not have permission to manage tools.', 'mcp-ai-wpoos' ),
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
						'message' => __( 'Tool slug is required.', 'mcp-ai-wpoos' ),
					)
				);
			}

			if ( ! in_array( $action, array( 'enable', 'disable' ), true ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Invalid action.', 'mcp-ai-wpoos' ),
					)
				);
			}

			// Get tool registry.
			$registry = WP_MCP_AI_Tool_Registry::get_instance();

			// Check if tool exists.
			if ( ! $registry->is_tool_registered( $tool_slug ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Tool not found.', 'mcp-ai-wpoos' ),
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
							? __( 'Tool enabled successfully.', 'mcp-ai-wpoos' )
							: __( 'Tool disabled successfully.', 'mcp-ai-wpoos' ),
						'enabled' => 'enable' === $action,
					)
				);
			} else {
				wp_send_json_error(
					array(
						'message' => __( 'Failed to update tool status.', 'mcp-ai-wpoos' ),
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
						'message' => __( 'You do not have permission to perform this action.', 'mcp-ai-wpoos' ),
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
						'message' => __( 'Invalid action type.', 'mcp-ai-wpoos' ),
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
							__( 'Failed to load profession data: %s', 'mcp-ai-wpoos' ),
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

			// Clear available profession count cache.
			delete_transient( 'wp_mcp_ai_available_profession_count' );

			// Refresh base knowledge documents and MIME types.
			WP_MCP_AI_Profession_Base_Knowledge_Seeder::seed_base_knowledge( true );

			// Refresh profession playbooks from txt files.
			if ( ! class_exists( 'WP_MCP_AI_Profession_Playbook_Seeder' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-playbook-seeder.php';
			}
			WP_MCP_AI_Profession_Playbook_Seeder::sync_all( true );

			$message = sprintf(
				/* translators: 1: Number of professions created, 2: Number of professions updated */
				__( 'Professions reloaded successfully. Created: %1$d, Updated: %2$d', 'mcp-ai-wpoos' ),
				$saved,
				$updated
			);

			if ( ! empty( $errors ) ) {
				$message .= ' ' . sprintf(
					/* translators: %d: Number of errors */
					__( 'Errors: %d', 'mcp-ai-wpoos' ),
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
						'message' => __( 'You do not have permission to perform this action.', 'mcp-ai-wpoos' ),
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
						'message' => __( 'Invalid action type.', 'mcp-ai-wpoos' ),
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
							__( 'Not enough professions found in database (%d). Please reseed professions first using "Update Professions" or "Replace All Professions" button above before reseeding teams.', 'mcp-ai-wpoos' ),
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
							__( 'Failed to load team data: %s', 'mcp-ai-wpoos' ),
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
								__( 'Team "%s" has no members - profession posts may not exist', 'mcp-ai-wpoos' ),
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
								__( 'Team "%s" has no members - profession posts may not exist', 'mcp-ai-wpoos' ),
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
				__( 'Teams reloaded successfully. Created: %1$d, Updated: %2$d', 'mcp-ai-wpoos' ),
				$saved,
				$updated
			);

			if ( ! empty( $errors ) ) {
				$message .= ' ' . sprintf(
				/* translators: %d: Number of errors */
					__( 'Errors: %d', 'mcp-ai-wpoos' ),
					count( $errors )
				);
			}

			if ( ! empty( $warnings ) ) {
				$message .= ' ' . sprintf(
				/* translators: %d: Number of warnings */
					__( 'Warnings: %d teams have no members. Try reseeding professions first.', 'mcp-ai-wpoos' ),
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
		 * Handle agent orchestration seeding AJAX request.
		 *
		 * Seeds agent roles and task patterns for professions based on
		 * category and expertise heuristics.
		 *
		 * @since 1.9.0
		 */
		private function handle_seed_orchestration() {
			check_ajax_referer( 'wp_mcp_ai_seed_orchestration', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'You do not have permission to perform this action.', 'mcp-ai-wpoos' ),
					)
				);
				return;
			}

			// Get force flag.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below with rest_sanitize_boolean.
			$force = isset( $_POST['force'] ) ? rest_sanitize_boolean( wp_unslash( $_POST['force'] ) ) : false;

			// Load orchestration seeder.
			if ( ! class_exists( 'WP_MCP_AI_Profession_Orchestration_Seeder' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/professions/class-wp-mcp-ai-profession-orchestration-seeder.php';
			}

			$seeder = new WP_MCP_AI_Profession_Orchestration_Seeder();
			$result = $seeder->seed_all( $force );

			if ( is_wp_error( $result ) ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: Error message */
							__( 'Failed to seed orchestration settings: %s', 'mcp-ai-wpoos' ),
							$result->get_error_message()
						),
					)
				);
				return;
			}

			$message = sprintf(
				/* translators: 1: Number of agent roles seeded, 2: Number of task patterns seeded */
				__( 'Orchestration settings seeded successfully. Agent roles assigned: %1$d, Task patterns created: %2$d', 'mcp-ai-wpoos' ),
				isset( $result['roles_seeded'] ) ? $result['roles_seeded'] : 0,
				isset( $result['patterns_seeded'] ) ? $result['patterns_seeded'] : 0
			);

			wp_send_json_success(
				array(
					'message'         => $message,
					'roles_seeded'    => isset( $result['roles_seeded'] ) ? $result['roles_seeded'] : 0,
					'patterns_seeded' => isset( $result['patterns_seeded'] ) ? $result['patterns_seeded'] : 0,
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
		private function handle_seed_task_templates() {
			check_ajax_referer( 'wp_mcp_ai_seed_task_templates', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'You do not have permission to perform this action.', 'mcp-ai-wpoos' ),
					)
				);
				return;
			}

			// Check if Pro addon is active.
			if ( ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Task template seeding requires the Pro addon.', 'mcp-ai-wpoos' ),
					)
				);
				return;
			}

			// Get overwrite flag.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below with rest_sanitize_boolean.
			$overwrite = isset( $_POST['overwrite'] ) ? rest_sanitize_boolean( wp_unslash( $_POST['overwrite'] ) ) : false;

			// Load the seed tool.
			if ( ! class_exists( 'WP_MCP_AI_Pro_Tool_Seed_Template_Library' ) ) {
				require_once WP_MCP_AI_PRO_PATH . 'includes/tools/class-wp-mcp-ai-pro-tool-seed-template-library.php';
			}

			// Execute the seeding tool.
			$tool   = new WP_MCP_AI_Pro_Tool_Seed_Template_Library();
			$result = $tool->execute(
				array( 'overwrite' => $overwrite ),
				array( 'user_id' => get_current_user_id() )
			);

			if ( ! empty( $result['success'] ) ) {
				// Mark as seeded.
				update_option( 'wp_mcp_ai_task_templates_seeded', true );

				$message = sprintf(
					/* translators: 1: Number created, 2: Number skipped, 3: Number errors */
					__( 'Template library seeded successfully! Created: %1$d, Skipped: %2$d, Errors: %3$d', 'mcp-ai-wpoos' ),
					$result['templates_created'],
					$result['templates_skipped'],
					$result['templates_errors']
				);

				wp_send_json_success(
					array(
						'message' => $message,
						'created' => $result['templates_created'],
						'skipped' => $result['templates_skipped'],
						'errors'  => $result['templates_errors'],
					)
				);
			} else {
				wp_send_json_error(
					array(
						'message' => $result['message'] ?? __( 'Failed to seed template library.', 'mcp-ai-wpoos' ),
					)
				);
			}
		}

		/**
		 * Handle Gemini cost migration AJAX request.
		 *
		 * Migrates historical token tracking records for Gemini-specific tools
		 * that were incorrectly attributed to OpenAI, updating them to Gemini provider
		 * and recalculating costs with correct Gemini pricing.
		 *
		 * @since 1.1.0
		 */
		private function handle_migrate_gemini_costs() {
			check_ajax_referer( 'wp_mcp_ai_migrate_gemini_costs', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'You do not have permission to perform this action.', 'mcp-ai-wpoos' ),
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
						'message' => __( 'Invalid action type.', 'mcp-ai-wpoos' ),
					)
				);
				return;
			}

			// Load enhanced token tracking class.
			if ( ! class_exists( 'WP_MCP_AI_Enhanced_Token_Tracking' ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Enhanced token tracking is not available.', 'mcp-ai-wpoos' ),
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
					$message = __( 'No Gemini tool usage records found in the database. This is expected if you haven\'t used any Gemini tools yet.', 'mcp-ai-wpoos' );
				} elseif ( 0 === $results['total_needing_migration'] ) {
					// All Gemini records are correctly attributed.
					$message = sprintf(
					/* translators: %d: Number of correctly attributed Gemini records */
						__( 'Found %d Gemini tool records, all correctly attributed to Gemini provider. No migration needed.', 'mcp-ai-wpoos' ),
						$results['correctly_attributed']
					);
				} elseif ( $results['total_needing_migration'] > $limit ) {
					// More records than batch limit - warn user.
					$message = sprintf(
					/* translators: 1: Total records needing migration, 2: Batch size that will be processed, 3: Total Gemini records, 4: Already correct records */
						__( 'Preview: Found %1$d records that need migration (out of %2$d total Gemini tool records). This migration will process the first %3$d records. %4$d records are already correctly attributed to Gemini. You may need to run the migration multiple times to update all records.', 'mcp-ai-wpoos' ),
						$results['total_needing_migration'],
						$results['total_gemini_records'],
						$limit,
						$results['correctly_attributed']
					);
				} else {
					$message = sprintf(
					/* translators: 1: Number of records that would be updated, 2: Total Gemini records, 3: Already correct records */
						__( 'Preview: Found %1$d records that need migration (out of %2$d total Gemini tool records). %3$d records are already correctly attributed to Gemini.', 'mcp-ai-wpoos' ),
						$results['total_needing_migration'],
						$results['total_gemini_records'],
						$results['correctly_attributed']
					);
				}
			} elseif ( 0 === $results['records_updated'] ) {
				if ( 0 === $results['total_gemini_records'] ) {
					$message = __( 'No Gemini tool usage records found in the database.', 'mcp-ai-wpoos' );
				} else {
					$message = sprintf(
					/* translators: %d: Number of correctly attributed records */
						__( 'No records were updated. All %d Gemini tool records are already correctly attributed.', 'mcp-ai-wpoos' ),
						$results['correctly_attributed']
					);
				}
			} else {
				// Check if there are more records to migrate.
				$remaining = $results['total_needing_migration'] - $results['records_updated'];
				if ( $remaining > 0 ) {
					$message = sprintf(
					/* translators: 1: Number of records updated, 2: Total records needing migration, 3: Remaining records */
						__( 'Migration complete! Successfully updated %1$d records with corrected Gemini provider attribution and costs. %2$d records still need migration. Please run the migration again to process the remaining records.', 'mcp-ai-wpoos' ),
						$results['records_updated'],
						$results['total_needing_migration'],
						$remaining
					);
				} else {
					$message = sprintf(
					/* translators: 1: Number of records updated, 2: Total Gemini records */
						__( 'Migration complete! Successfully updated %1$d records with corrected Gemini provider attribution and costs. All Gemini tool records are now correctly attributed. Total Gemini tool records: %2$d', 'mcp-ai-wpoos' ),
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
				wp_send_json_error( __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) );
			}

			// Get model ID.
			$model = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';

			if ( empty( $model ) ) {
				wp_send_json_error( __( 'Model ID is required.', 'mcp-ai-wpoos' ) );
			}

			// Get config data.
			$config = isset( $_POST['config'] ) ? (array) wp_unslash( $_POST['config'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$config = wp_mcp_ai_sanitize_recursive( $config );

			if ( empty( $config ) ) {
				wp_send_json_error( __( 'Configuration data is required.', 'mcp-ai-wpoos' ) );
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
						'message' => __( 'Model configuration saved successfully.', 'mcp-ai-wpoos' ),
						'model'   => $model,
						'config'  => $updated_config,
					)
				);
			} else {
				wp_send_json_error( __( 'Failed to save model configuration.', 'mcp-ai-wpoos' ) );
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
				wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Verify nonce.
			if ( ! check_ajax_referer( 'wp_mcp_ai_dashboard', 'nonce', false ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Get tool slug from request.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below with sanitize_key.
			$tool_slug = isset( $_POST['tool_slug'] ) ? sanitize_key( wp_unslash( $_POST['tool_slug'] ) ) : '';

			if ( empty( $tool_slug ) ) {
				wp_send_json_error( array( 'message' => __( 'Invalid tool slug.', 'mcp-ai-wpoos' ) ) );
				return;
			}

			// Verify tool exists.
			$registry = WP_MCP_AI_Tool_Registry::get_instance();
			if ( ! $registry->is_tool_registered( $tool_slug ) ) {
				wp_send_json_error( array( 'message' => __( 'Tool not found.', 'mcp-ai-wpoos' ) ) );
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
						'message' => __( 'Tool settings saved successfully.', 'mcp-ai-wpoos' ),
					)
				);
			} else {
				wp_send_json_error(
					array(
						'message' => __( 'Failed to save tool settings.', 'mcp-ai-wpoos' ),
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
						'message' => __( 'You do not have permission to perform this action.', 'mcp-ai-wpoos' ),
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
						'message' => __( 'Invalid profession ID.', 'mcp-ai-wpoos' ),
					)
				);
				return;
			}

			// Verify profession exists.
			$profession = get_post( $profession_id );
			if ( ! $profession || WP_MCP_AI_Profession_CPT::POST_TYPE !== $profession->post_type ) {
				wp_send_json_error(
					array(
						'message' => __( 'Profession not found.', 'mcp-ai-wpoos' ),
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
							__( 'Playbook for "%s" regenerated successfully!', 'mcp-ai-wpoos' ),
							$profession->post_title
						),
					)
				);
			} catch ( Exception $e ) {
				wp_send_json_error(
					array(
						'message' => sprintf(
							/* translators: %s: Error message */
							__( 'Failed to regenerate playbook: %s', 'mcp-ai-wpoos' ),
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
						'message' => __( 'You do not have permission to perform this action.', 'mcp-ai-wpoos' ),
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
			update_option( 'wp_mcp_ai_playbooks_last_sync', time() );

			$message = $force
				? __( 'All profession playbooks regenerated successfully! Duplicates removed.', 'mcp-ai-wpoos' )
				: __( 'Profession playbooks synced successfully! Only changed playbooks were updated and duplicates removed.', 'mcp-ai-wpoos' );

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
						'message' => __( 'You do not have permission to perform this action.', 'mcp-ai-wpoos' ),
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
						'message' => __( 'No orphaned playbook attachments found to delete.', 'mcp-ai-wpoos' ),
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

			$message = sprintf(
				/* translators: %d: number of deleted playbook attachments */
				_n(
					'Successfully deleted %d orphaned playbook attachment from media library.',
					'Successfully deleted %d orphaned playbook attachments from media library.',
					$deleted_count,
					'mcp-ai-wpoos'
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
		 * Handle Skills refresh AJAX request.
		 *
		 * Supports 'refresh' (rescan disk index) and 'install_bundled'
		 * (install or force-reinstall bundled skills shipped with the plugin).
		 *
		 * @since 1.9.0
		 */
		private function handle_refresh_skills() {
			check_ajax_referer( 'wp_mcp_ai_refresh_skills', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'You do not have permission to perform this action.', 'mcp-ai-wpoos' ),
					)
				);
				return;
			}

			// Get action type: 'refresh', 'install_bundled', or 'force_install_bundled'.
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized below with sanitize_key.
			$action_type = isset( $_POST['action_type'] ) ? sanitize_key( wp_unslash( $_POST['action_type'] ) ) : 'refresh';

			if ( ! in_array( $action_type, array( 'refresh', 'install_bundled', 'force_install_bundled' ), true ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Invalid action type.', 'mcp-ai-wpoos' ),
					)
				);
				return;
			}

			// Load skill registry.
			if ( ! class_exists( 'WP_MCP_AI_Skill_Registry' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-skill-registry.php';
			}
			if ( ! class_exists( 'WP_MCP_AI_Skill_Parser' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-skill-parser.php';
			}

			$registry = WP_MCP_AI_Skill_Registry::instance();

			if ( 'refresh' === $action_type ) {
				// Force rescan of the skills directory.
				$skills = $registry->load_skills( true );

				wp_send_json_success(
					array(
						'message' => sprintf(
							/* translators: %d: Number of skills found */
							__( 'Skills index refreshed. Found %d installed skills.', 'mcp-ai-wpoos' ),
							count( $skills )
						),
						'count'   => count( $skills ),
					)
				);
				return;
			}

			// install_bundled or force_install_bundled.
			$force = ( 'force_install_bundled' === $action_type );

			if ( $force ) {
				// Remove existing installed bundled skills to force reinstall.
				$bundled_dir      = $registry->get_bundled_skills_dir();
				$uninstall_errors = array();
				if ( is_dir( $bundled_dir ) ) {
					$dirs = glob( $bundled_dir . '/*', GLOB_ONLYDIR );
					if ( is_array( $dirs ) ) {
						foreach ( $dirs as $dir ) {
							$skill_name       = basename( $dir );
							$uninstall_result = $registry->uninstall_skill( $skill_name );
							if ( is_wp_error( $uninstall_result ) ) {
								$uninstall_errors[] = $skill_name;
							}
						}
					}
				}
			}

			$result = $registry->install_bundled_skills();

			$message = sprintf(
				/* translators: 1: Number installed, 2: Number skipped */
				__( 'Bundled skills processed. Installed: %1$d, Skipped: %2$d', 'mcp-ai-wpoos' ),
				$result['installed'],
				$result['skipped']
			);

			if ( ! empty( $result['errors'] ) ) {
				$message .= ' ' . sprintf(
					/* translators: %d: Number of errors */
					__( 'Errors: %d', 'mcp-ai-wpoos' ),
					count( $result['errors'] )
				);
			}

			if ( $force && ! empty( $uninstall_errors ) ) {
				$message .= ' ' . sprintf(
					/* translators: %d: Number of skills that failed to uninstall */
					__( 'Failed to uninstall: %d', 'mcp-ai-wpoos' ),
					count( $uninstall_errors )
				);
			}

			wp_send_json_success(
				array(
					'message'   => $message,
					'installed' => $result['installed'],
					'skipped'   => $result['skipped'],
					'errors'    => count( $result['errors'] ),
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
				wp_send_json_error( array( 'message' => __( 'Invalid security token.', 'mcp-ai-wpoos' ) ) );
				return;
			}
			// Check user capabilities.
			if ( ! current_user_can( 'edit_posts' ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Insufficient permissions.', 'mcp-ai-wpoos' ),
					)
				);
				return;
			}

			// Get provider from request.
			$provider = isset( $_POST['provider'] ) ? sanitize_key( wp_unslash( $_POST['provider'] ) ) : '';

			if ( empty( $provider ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Provider is required.', 'mcp-ai-wpoos' ),
					)
				);
				return;
			}

			// Validate provider.
			$allowed_providers = apply_filters( 'wp_mcp_ai_allowed_providers', array( 'openai', 'anthropic', 'gemini', 'huggingface', 'ollama', 'lm_studio', 'cloudflare', 'embedded' ) );
			if ( ! in_array( $provider, $allowed_providers, true ) ) {
				wp_send_json_error(
					array(
						'message' => __( 'Invalid provider.', 'mcp-ai-wpoos' ),
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
							__( 'No models available for provider: %s. Please configure API keys in settings.', 'mcp-ai-wpoos' ),
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
