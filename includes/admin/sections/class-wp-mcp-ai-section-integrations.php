<?php
/**
 * Integrations Settings Section
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Integrations' ) ) {
	/**
	 * Integrations settings section - Gmail and Crawl4AI.
	 */
	class WP_MCP_AI_Section_Integrations extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'integrations_gmail_crawl4ai';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'External Tools Integration', 'wp-mcp-ai' );
		}

		/**
		 * Get tab ID.
		 *
		 * Note: This section has its own dedicated admin page at wp-mcp-ai-gmail-crawl4ai
		 * and should not appear in the main settings tabs.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'tools';
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Configure third-party service integrations including search APIs, email services, cloud platforms, web crawlers, and analytics.', 'wp-mcp-ai' );
		}

		/**
		 * Get section priority.
		 *
		 * @return int
		 */
		public function get_priority() {
			return 20;
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			return array(
				'gmail_client_id'     => array(
					'type'         => 'text',
					'label'        => __( 'Gmail OAuth Client ID', 'wp-mcp-ai' ),
					'description'  => __( 'OAuth 2.0 Client ID from Google Cloud Console for Gmail integration.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'off',
				),
				'gmail_client_secret' => array(
					'type'         => 'password',
					'label'        => __( 'Gmail OAuth Client Secret', 'wp-mcp-ai' ),
					'description'  => __( 'OAuth 2.0 Client Secret from Google Cloud Console.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'crawl4ai_base_url'   => array(
					'type'         => 'url',
					'label'        => __( 'Crawl4AI Base URL', 'wp-mcp-ai' ),
					'description'  => __( 'Base URL for Crawl4AI service (if using external crawler).', 'wp-mcp-ai' ),
					'placeholder'  => 'http://localhost:8000',
					'autocomplete' => 'url',
				),
				'crawl4ai_api_key'             => array(
					'type'         => 'password',
					'label'        => __( 'Crawl4AI API Key', 'wp-mcp-ai' ),
					'description'  => __( 'API key for Crawl4AI service (if required).', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),

				// Brave Search Section.
				'brave_search_api_key'         => array(
					'type'         => 'password',
					'label'        => __( 'Brave Search API Key', 'wp-mcp-ai' ),
					'description'  => sprintf(
						/* translators: %s: URL to Brave Search API */
						__( 'API key for Brave Search integration. Get your API key from %s.', 'wp-mcp-ai' ),
						'<a href="https://brave.com/search/api/" target="_blank">Brave Search API</a>'
					),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),

				// Cloudflare Section.
				'cloudflare_api_token'         => array(
					'type'         => 'password',
					'label'        => __( 'Cloudflare API Token', 'wp-mcp-ai' ),
					'description'  => __( 'API token for Cloudflare integration. Create a token in your Cloudflare dashboard.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'cloudflare_zone_id'           => array(
					'type'        => 'text',
					'label'       => __( 'Cloudflare Zone ID', 'wp-mcp-ai' ),
					'description' => __( 'Your Cloudflare zone ID for cache management.', 'wp-mcp-ai' ),
					'placeholder' => '',
				),

				// Cloudways Section.
				'cloudways_api_key'            => array(
					'type'         => 'password',
					'label'        => __( 'Cloudways API Key', 'wp-mcp-ai' ),
					'description'  => __( 'API key for Cloudways hosting integration.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'cloudways_email'              => array(
					'type'        => 'email',
					'label'       => __( 'Cloudways Account Email', 'wp-mcp-ai' ),
					'description' => __( 'Email address associated with your Cloudways account.', 'wp-mcp-ai' ),
					'placeholder' => 'you@example.com',
				),

				// Mailjet Section.
				'mailjet_api_key'              => array(
					'type'         => 'password',
					'label'        => __( 'Mailjet API Key', 'wp-mcp-ai' ),
					'description'  => __( 'API key for Mailjet email service integration.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'mailjet_api_secret'           => array(
					'type'         => 'password',
					'label'        => __( 'Mailjet API Secret', 'wp-mcp-ai' ),
					'description'  => __( 'API secret for Mailjet email service.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),

				// QuickBooks Section.
				'quickbooks_api_key'           => array(
					'type'         => 'password',
					'label'        => __( 'QuickBooks API Key', 'wp-mcp-ai' ),
					'description'  => __( 'API key for QuickBooks integration.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'quickbooks_client_id'         => array(
					'type'        => 'text',
					'label'       => __( 'QuickBooks Client ID', 'wp-mcp-ai' ),
					'description' => __( 'OAuth 2.0 Client ID from QuickBooks developer portal.', 'wp-mcp-ai' ),
					'placeholder' => '',
				),
				'quickbooks_client_secret'     => array(
					'type'         => 'password',
					'label'        => __( 'QuickBooks Client Secret', 'wp-mcp-ai' ),
					'description'  => __( 'OAuth 2.0 Client Secret from QuickBooks developer portal.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),

				// Google Analytics Section.
				'google_analytics_property_id' => array(
					'type'        => 'text',
					'label'       => __( 'Google Analytics Property ID', 'wp-mcp-ai' ),
					'description' => __( 'Google Analytics 4 Property ID (e.g., 123456789).', 'wp-mcp-ai' ),
					'placeholder' => '123456789',
				),
				'google_analytics_credentials' => array(
					'type'        => 'textarea',
					'label'       => __( 'Google Analytics Service Account JSON', 'wp-mcp-ai' ),
					'description' => __( 'Service account credentials in JSON format from Google Cloud Console.', 'wp-mcp-ai' ),
					'placeholder' => '{"type": "service_account", ...}',
				),
			);
		}

		/**
		 * Render section fields.
		 */
		public function render() {
			$fields = $this->get_fields();

			foreach ( $fields as $key => $field ) {
				$this->render_field( $key, $field );
			}
		}

		/**
		 * Validate section input.
		 *
		 * @param array $input Raw input.
		 * @return array|WP_Error Validated input or error.
		 */
		public function validate( $input ) {
			$errors = array();

			// Validate Crawl4AI URL.
			if ( isset( $input['crawl4ai_base_url'] ) && ! empty( $input['crawl4ai_base_url'] ) ) {
				$result = WP_MCP_AI_Settings_Validator::validate_url( $input['crawl4ai_base_url'] );
				if ( is_wp_error( $result ) ) {
					$errors[] = __( 'Crawl4AI Base URL: ', 'wp-mcp-ai' ) . $result->get_error_message();
				}
			}

			if ( ! empty( $errors ) ) {
				return new WP_Error( 'validation_error', implode( ' ', $errors ) );
			}

			return $input;
		}
	}
}
