<?php
/**
 * Service Connectors Settings Section
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Service_Connectors' ) ) {
	/**
	 * Service connectors settings section.
	 */
	class WP_MCP_AI_Section_Service_Connectors extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'service_connectors';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'External Tools', 'wp-mcp-ai' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'external_tools';
		}

		/**
		 * Get section priority.
		 *
		 * @return int
		 */
		public function get_priority() {
			return 10;
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
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			return array(
				// Brave Search.
				'brave_search_api_key'           => array(
					'type'         => 'password',
					'label'        => __( 'Brave Search API Key', 'wp-mcp-ai' ),
					'description'  => __( 'API key for Brave Search integration. Get your API key from <a href="https://brave.com/search/api/" target="_blank">Brave Search API</a>.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),

				// Cloudflare.
				'cloudflare_api_token'           => array(
					'type'         => 'password',
					'label'        => __( 'Cloudflare API Token', 'wp-mcp-ai' ),
					'description'  => __( 'API token for Cloudflare integration. Create a token in your Cloudflare dashboard.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'cloudflare_zone_id'             => array(
					'type'        => 'text',
					'label'       => __( 'Cloudflare Zone ID', 'wp-mcp-ai' ),
					'description' => __( 'Your Cloudflare zone ID for cache management.', 'wp-mcp-ai' ),
					'placeholder' => '',
				),

				// Cloudways.
				'cloudways_api_key'              => array(
					'type'         => 'password',
					'label'        => __( 'Cloudways API Key', 'wp-mcp-ai' ),
					'description'  => __( 'API key for Cloudways hosting integration.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'cloudways_email'                => array(
					'type'        => 'email',
					'label'       => __( 'Cloudways Account Email', 'wp-mcp-ai' ),
					'description' => __( 'Email address associated with your Cloudways account.', 'wp-mcp-ai' ),
					'placeholder' => 'you@example.com',
				),

				// Mailjet.
				'mailjet_api_key'                => array(
					'type'         => 'password',
					'label'        => __( 'Mailjet API Key', 'wp-mcp-ai' ),
					'description'  => __( 'API key for Mailjet email service integration.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'mailjet_api_secret'             => array(
					'type'         => 'password',
					'label'        => __( 'Mailjet API Secret', 'wp-mcp-ai' ),
					'description'  => __( 'API secret for Mailjet email service.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),

				// QuickBooks.
				'quickbooks_api_key'             => array(
					'type'         => 'password',
					'label'        => __( 'QuickBooks API Key', 'wp-mcp-ai' ),
					'description'  => __( 'API key for QuickBooks integration.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'quickbooks_client_id'           => array(
					'type'        => 'text',
					'label'       => __( 'QuickBooks Client ID', 'wp-mcp-ai' ),
					'description' => __( 'OAuth 2.0 Client ID from QuickBooks developer portal.', 'wp-mcp-ai' ),
					'placeholder' => '',
				),
				'quickbooks_client_secret'       => array(
					'type'         => 'password',
					'label'        => __( 'QuickBooks Client Secret', 'wp-mcp-ai' ),
					'description'  => __( 'OAuth 2.0 Client Secret from QuickBooks developer portal.', 'wp-mcp-ai' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),

				// Google Analytics.
				'google_analytics_property_id'   => array(
					'type'        => 'text',
					'label'       => __( 'Google Analytics Property ID', 'wp-mcp-ai' ),
					'description' => __( 'Google Analytics 4 Property ID (e.g., 123456789).', 'wp-mcp-ai' ),
					'placeholder' => '123456789',
				),
				'google_analytics_credentials'   => array(
					'type'        => 'textarea',
					'label'       => __( 'Google Analytics Service Account JSON', 'wp-mcp-ai' ),
					'description' => __( 'Service account credentials in JSON format from Google Cloud Console.', 'wp-mcp-ai' ),
					'placeholder' => '{"type": "service_account", ...}',
					'rows'        => 10,
				),
			);
		}

		/**
		 * Render section fields.
		 */
		public function render() {
			$fields = $this->get_fields();

			// Group fields by service.
			$groups = array(
				'brave'     => array(
					'title'  => __( 'Brave Search', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-search',
					'fields' => array( 'brave_search_api_key' ),
				),
				'cloudflare' => array(
					'title'  => __( 'Cloudflare', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-cloud',
					'fields' => array( 'cloudflare_api_token', 'cloudflare_zone_id' ),
				),
				'cloudways' => array(
					'title'  => __( 'Cloudways', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-admin-site',
					'fields' => array( 'cloudways_api_key', 'cloudways_email' ),
				),
				'mailjet'   => array(
					'title'  => __( 'Mailjet', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-email',
					'fields' => array( 'mailjet_api_key', 'mailjet_api_secret' ),
				),
				'quickbooks' => array(
					'title'  => __( 'QuickBooks', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-money-alt',
					'fields' => array( 'quickbooks_api_key', 'quickbooks_client_id', 'quickbooks_client_secret' ),
				),
				'google_analytics' => array(
					'title'  => __( 'Google Analytics', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-chart-line',
					'fields' => array( 'google_analytics_property_id', 'google_analytics_credentials' ),
				),
			);

			foreach ( $groups as $group_id => $group ) {
				?>
				<tr>
					<td colspan="2" style="padding: 20px 0 10px 0;">
						<h3 style="margin: 0; display: flex; align-items: center; gap: 8px;">
							<span class="dashicons <?php echo esc_attr( $group['icon'] ); ?>"></span>
							<?php echo esc_html( $group['title'] ); ?>
						</h3>
						<hr style="margin: 10px 0; border: none; border-top: 1px solid #ddd;">
					</td>
				</tr>
				<?php
				foreach ( $group['fields'] as $field_key ) {
					if ( isset( $fields[ $field_key ] ) ) {
						$this->render_field( $field_key, $fields[ $field_key ] );
					}
				}
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

			// Validate email fields.
			if ( isset( $input['cloudways_email'] ) && ! empty( $input['cloudways_email'] ) ) {
				if ( ! is_email( $input['cloudways_email'] ) ) {
					$errors[] = __( 'Cloudways Account Email must be a valid email address.', 'wp-mcp-ai' );
				}
			}

			// Validate Google Analytics property ID.
			if ( isset( $input['google_analytics_property_id'] ) && ! empty( $input['google_analytics_property_id'] ) ) {
				if ( ! is_numeric( $input['google_analytics_property_id'] ) ) {
					$errors[] = __( 'Google Analytics Property ID must be numeric.', 'wp-mcp-ai' );
				}
			}

			// Validate Google Analytics JSON.
			if ( isset( $input['google_analytics_credentials'] ) && ! empty( $input['google_analytics_credentials'] ) ) {
				$json = json_decode( $input['google_analytics_credentials'], true );
				if ( json_last_error() !== JSON_ERROR_NONE ) {
					$errors[] = __( 'Google Analytics Service Account JSON is invalid. Please check the format.', 'wp-mcp-ai' );
				}
			}

			if ( ! empty( $errors ) ) {
				return new WP_Error( 'validation_error', implode( ' ', $errors ) );
			}

			return $input;
		}
	}
}
