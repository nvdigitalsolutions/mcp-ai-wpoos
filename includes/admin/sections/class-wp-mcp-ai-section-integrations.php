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
			return __( 'Gmail & Crawl4AI Integration', 'wp-mcp-ai' );
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
			return 'integrations';
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Configure Gmail OAuth credentials for email integration and Crawl4AI service for web scraping capabilities.', 'wp-mcp-ai' );
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
				'crawl4ai_api_key'    => array(
					'type'         => 'password',
					'label'        => __( 'Crawl4AI API Key', 'wp-mcp-ai' ),
					'description'  => __( 'API key for Crawl4AI service (if required).', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
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
