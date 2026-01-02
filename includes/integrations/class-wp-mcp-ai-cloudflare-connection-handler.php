<?php
/**
 * Cloudflare Connection Handler for WP MCP AI
 *
 * Handles 1-click connection testing and validation for Cloudflare integration.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Cloudflare_Connection_Handler' ) ) {
	/**
	 * Manages Cloudflare API connection testing and validation.
	 *
	 * Note: Cloudflare uses API tokens (not OAuth), so this provides
	 * 1-click connection testing/verification instead of OAuth flow.
	 */
	class WP_MCP_AI_Cloudflare_Connection_Handler {
		const CLOUDFLARE_API_BASE         = 'https://api.cloudflare.com/client/v4';
		const CLOUDFLARE_VERIFY_ENDPOINT  = 'https://api.cloudflare.com/client/v4/user/tokens/verify';
		const CLOUDFLARE_ZONES_ENDPOINT   = 'https://api.cloudflare.com/client/v4/zones';

		/**
		 * Handle the 1-click connection test for Cloudflare.
		 *
		 * Validates the API token and zone ID.
		 */
		public function handle_cloudflare_test_connection() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'wp-mcp-ai' ) );
			}

			check_admin_referer( 'wp_mcp_ai_cloudflare_test_connection' );

			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			if ( empty( $settings['cloudflare_api_token'] ) ) {
				$this->add_settings_redirect_notice(
					'cloudflare_missing_token',
					__( 'Enter your Cloudflare API token before testing the connection.', 'wp-mcp-ai' ),
					'error'
				);
				$this->redirect_to_settings_page();
			}

			$api_token = $settings['cloudflare_api_token'];

			// Step 1: Verify the API token is valid.
			$token_verification = $this->verify_api_token( $api_token );

			if ( is_wp_error( $token_verification ) ) {
				WP_MCP_AI_Admin_Settings::log(
					'Cloudflare token verification failed.',
					array( 'error' => $token_verification->get_error_message() )
				);
				$this->add_settings_redirect_notice(
					'cloudflare_token_invalid',
					sprintf(
						/* translators: %s: Error message. */
						__( 'Cloudflare API token is invalid: %s', 'wp-mcp-ai' ),
						$token_verification->get_error_message()
					),
					'error'
				);
				$this->redirect_to_settings_page();
			}

			// Step 2: If zone ID is provided, verify it exists and is accessible.
			if ( ! empty( $settings['cloudflare_zone_id'] ) ) {
				$zone_verification = $this->verify_zone_access( $api_token, $settings['cloudflare_zone_id'] );

				if ( is_wp_error( $zone_verification ) ) {
					WP_MCP_AI_Admin_Settings::log(
						'Cloudflare zone verification failed.',
						array( 'error' => $zone_verification->get_error_message() )
					);
					$this->add_settings_redirect_notice(
						'cloudflare_zone_invalid',
						sprintf(
							/* translators: %s: Error message. */
							__( 'Cloudflare Zone ID verification failed: %s', 'wp-mcp-ai' ),
							$zone_verification->get_error_message()
						),
						'error'
					);
					$this->redirect_to_settings_page();
				}

				// Store zone information.
				$settings['cloudflare_zone_name']     = isset( $zone_verification['name'] ) ? $zone_verification['name'] : '';
				$settings['cloudflare_zone_status']   = isset( $zone_verification['status'] ) ? $zone_verification['status'] : '';
			}

			// Store connection status.
			$settings['cloudflare_connected']      = true;
			$settings['cloudflare_connection_time'] = time();
			$settings['cloudflare_token_status']   = isset( $token_verification['status'] ) ? $token_verification['status'] : 'active';

			update_option( 'wp_mcp_ai_settings', $settings );

			$message = __( 'Successfully connected to Cloudflare! Your API token is valid.', 'wp-mcp-ai' );
			if ( ! empty( $settings['cloudflare_zone_name'] ) ) {
				$message .= ' ' . sprintf(
					/* translators: %s: Zone name. */
					__( 'Zone "%s" is accessible.', 'wp-mcp-ai' ),
					$settings['cloudflare_zone_name']
				);
			}

			$this->add_settings_redirect_notice(
				'cloudflare_connected',
				$message,
				'success'
			);
			$this->redirect_to_settings_page();
		}

		/**
		 * Handle disconnecting from Cloudflare.
		 */
		public function handle_cloudflare_disconnect() {
			if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'wp-mcp-ai' ) );
			}

			check_admin_referer( 'wp_mcp_ai_cloudflare_disconnect' );

			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			// Clear connection status (but keep API token and zone ID).
			unset( $settings['cloudflare_connected'] );
			unset( $settings['cloudflare_connection_time'] );
			unset( $settings['cloudflare_token_status'] );
			unset( $settings['cloudflare_zone_name'] );
			unset( $settings['cloudflare_zone_status'] );

			update_option( 'wp_mcp_ai_settings', $settings );

			$this->add_settings_redirect_notice(
				'cloudflare_disconnected',
				__( 'Disconnected from Cloudflare. Your API token remains saved for future connections.', 'wp-mcp-ai' ),
				'success'
			);
			$this->redirect_to_settings_page();
		}

		/**
		 * Verify that the API token is valid.
		 *
		 * @param string $api_token The Cloudflare API token.
		 * @return array|WP_Error Token info on success.
		 */
		protected function verify_api_token( $api_token ) {
			/**
			 * Filter the Cloudflare verify endpoint.
			 *
			 * @since 1.0.0
			 *
			 * @param string $endpoint Verify endpoint.
			 */
			$verify_endpoint = apply_filters( 'wp_mcp_ai_cloudflare_verify_endpoint', self::CLOUDFLARE_VERIFY_ENDPOINT );

			$response = wp_remote_get(
				$verify_endpoint,
				array(
					'timeout' => 15,
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_token,
						'Content-Type'  => 'application/json',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudflare_request_failed',
					$response->get_error_message()
				);
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body        = wp_remote_retrieve_body( $response );

			if ( 200 !== (int) $status_code ) {
				$decoded = json_decode( $body, true );
				$error_message = '';

				if ( is_array( $decoded ) && isset( $decoded['errors'][0]['message'] ) ) {
					$error_message = $decoded['errors'][0]['message'];
				} else {
					$error_message = sprintf(
						/* translators: %d: HTTP status code. */
						__( 'HTTP %d response', 'wp-mcp-ai' ),
						$status_code
					);
				}

				return new WP_Error(
					'wp_mcp_ai_cloudflare_token_invalid',
					$error_message
				);
			}

			$decoded = json_decode( $body, true );

			if ( ! is_array( $decoded ) || ! isset( $decoded['success'] ) || true !== $decoded['success'] ) {
				return new WP_Error(
					'wp_mcp_ai_cloudflare_invalid_response',
					__( 'Cloudflare returned an invalid response.', 'wp-mcp-ai' )
				);
			}

			return isset( $decoded['result'] ) ? $decoded['result'] : array();
		}

		/**
		 * Verify that the zone ID is accessible with the API token.
		 *
		 * @param string $api_token The Cloudflare API token.
		 * @param string $zone_id   The Cloudflare zone ID.
		 * @return array|WP_Error Zone info on success.
		 */
		protected function verify_zone_access( $api_token, $zone_id ) {
			/**
			 * Filter the Cloudflare zones endpoint.
			 *
			 * @since 1.0.0
			 *
			 * @param string $endpoint Zones endpoint.
			 */
			$zones_endpoint = apply_filters( 'wp_mcp_ai_cloudflare_zones_endpoint', self::CLOUDFLARE_ZONES_ENDPOINT );
			$zone_url       = trailingslashit( $zones_endpoint ) . $zone_id;

			$response = wp_remote_get(
				$zone_url,
				array(
					'timeout' => 15,
					'headers' => array(
						'Authorization' => 'Bearer ' . $api_token,
						'Content-Type'  => 'application/json',
					),
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body        = wp_remote_retrieve_body( $response );

			if ( 200 !== (int) $status_code ) {
				$decoded = json_decode( $body, true );
				$error_message = '';

				if ( is_array( $decoded ) && isset( $decoded['errors'][0]['message'] ) ) {
					$error_message = $decoded['errors'][0]['message'];
				} else {
					$error_message = sprintf(
						/* translators: %d: HTTP status code. */
						__( 'HTTP %d response', 'wp-mcp-ai' ),
						$status_code
					);
				}

				return new WP_Error(
					'wp_mcp_ai_cloudflare_zone_error',
					$error_message
				);
			}

			$decoded = json_decode( $body, true );

			if ( ! is_array( $decoded ) || ! isset( $decoded['success'] ) || true !== $decoded['success'] ) {
				return new WP_Error(
					'wp_mcp_ai_cloudflare_invalid_zone_response',
					__( 'Could not verify zone information.', 'wp-mcp-ai' )
				);
			}

			return isset( $decoded['result'] ) ? $decoded['result'] : array();
		}

		/**
		 * Check if Cloudflare is connected.
		 *
		 * @return bool True if connected.
		 */
		public static function is_connected() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			return ! empty( $settings['cloudflare_connected'] );
		}

		/**
		 * Add an admin notice and store it in a transient for redirect.
		 *
		 * @param string $code    Notice code.
		 * @param string $message Notice message.
		 * @param string $type    Notice type (success, error, warning, info).
		 */
		protected function add_settings_redirect_notice( $code, $message, $type = 'error' ) {
			set_transient(
				'wp_mcp_ai_cloudflare_connection_notice',
				array(
					'code'    => $code,
					'message' => $message,
					'type'    => $type,
				),
				60
			);
		}

		/**
		 * Redirect back to the settings page.
		 */
		protected function redirect_to_settings_page() {
			$redirect_url = admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=tools&subtab=connections&connection=cloudflare' );

			/**
			 * Filter the Cloudflare connection redirect URL.
			 *
			 * @since 1.0.0
			 *
			 * @param string $redirect_url The redirect URL.
			 */
			$redirect_url = apply_filters( 'wp_mcp_ai_cloudflare_connection_redirect_url', $redirect_url );

			wp_safe_redirect( $redirect_url );
			exit;
		}
	}
}
