<?php
/**
 * Pro Integrations Settings Section
 *
 * Settings for pro-only integrations (Mailjet, Google Analytics, Yahoo, ESPN).
 * These integrations have tools in the pro addon.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Pro_Integrations' ) ) {
	/**
	 * Pro integrations settings section.
	 */
	class WP_MCP_AI_Section_Pro_Integrations extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'pro_integrations';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Pro Integrations', 'mcp-ai-wpoos' );
		}

		/**
		 * Get tab ID.
		 *
		 * This section is integrated within the Tools tab under the Connections subtab.
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
			return __( 'Configure pro-only integration services (Mailjet, Google Analytics, Fantasy Sports). These integrations are available in the Pro addon.', 'mcp-ai-wpoos' );
		}

		/**
		 * Get documentation URL for this section.
		 *
		 * @return string
		 */
		public function get_documentation_url() {
			return 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/architecture/integrations/oauth-settings-architecture.md';
		}

		/**
		 * Get section priority.
		 *
		 * @return int
		 */
		public function get_priority() {
			return 25; // After base integrations (20)
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			return array(
				// Mailjet.
				'mailjet_api_key'                   => array(
					'type'         => 'password',
					'label'        => __( 'Mailjet API Key', 'mcp-ai-wpoos' ),
					'description'  => __( 'Get this from your Mailjet account under API Keys.', 'mcp-ai-wpoos' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'mailjet_api_secret'                => array(
					'type'         => 'password',
					'label'        => __( 'Mailjet Secret Key', 'mcp-ai-wpoos' ),
					'description'  => __( 'Mailjet uses Basic Authentication (API Key + Secret Key), not OAuth.', 'mcp-ai-wpoos' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'mailjet_from_email'                => array(
					'type'        => 'email',
					'label'       => __( 'Mailjet From Email', 'mcp-ai-wpoos' ),
					'description' => __( 'Default "from" email address for Mailjet messages. Must be a verified sender in your Mailjet account.', 'mcp-ai-wpoos' ),
					'placeholder' => 'noreply@example.com',
				),
				'mailjet_from_name'                 => array(
					'type'        => 'text',
					'label'       => __( 'Mailjet From Name', 'mcp-ai-wpoos' ),
					'description' => __( 'Default "from" name for Mailjet messages.', 'mcp-ai-wpoos' ),
					'placeholder' => 'My Site',
				),
				'mailjet_webhook_secret'            => array(
					'type'         => 'password',
					'label'        => __( 'Mailjet Webhook Secret', 'mcp-ai-wpoos' ),
					'description'  => __( 'Optional secret for verifying webhook requests from Mailjet.', 'mcp-ai-wpoos' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),

				// Google Analytics.
				'google_analytics_property_id'      => array(
					'type'        => 'text',
					'label'       => __( 'Google Analytics Property ID', 'mcp-ai-wpoos' ),
					'description' => __( 'Google Analytics 4 Property ID (e.g., 123456789).', 'mcp-ai-wpoos' ),
					'placeholder' => '123456789',
				),
				'google_analytics_credentials'      => array(
					'type'        => 'textarea',
					'label'       => __( 'Google Analytics Service Account JSON (Legacy)', 'mcp-ai-wpoos' ),
					'description' => __( 'Service account credentials in JSON format from Google Cloud Console. This field is being phased out in favor of google_analytics_credentials_json.', 'mcp-ai-wpoos' ),
					'placeholder' => '{"type": "service_account", ...}',
					'rows'        => 5,
				),
				'google_analytics_credentials_json' => array(
					'type'        => 'textarea',
					'label'       => __( 'Google Analytics 4 Credentials JSON', 'mcp-ai-wpoos' ),
					'description' => __( 'Service account JSON credentials file for Google Analytics 4 API access. Download from Google Cloud Console → IAM & Admin → Service Accounts. The JSON must be valid and contain type, project_id, private_key, and client_email fields.', 'mcp-ai-wpoos' ),
					'placeholder' => '{"type": "service_account", "project_id": "your-project", ...}',
					'rows'        => 8,
				),

				// Yahoo Fantasy Sports.
				'yahoo_client_id'                   => array(
					'type'         => 'text',
					'label'        => __( 'Yahoo Client ID', 'mcp-ai-wpoos' ),
					'description'  => sprintf(
						/* translators: %s: URL to Yahoo Developer */
						__( 'OAuth 2.0 Client ID (Consumer Key) from Yahoo Developer Network for Yahoo Fantasy Sports API. Get your credentials from %s. Used for fantasy football league management, roster analysis, and player statistics.', 'mcp-ai-wpoos' ),
						'<a href="https://developer.yahoo.com/apps/" target="_blank">Yahoo Developer Network</a>'
					),
					'placeholder'  => '',
					'autocomplete' => 'off',
				),
				'yahoo_client_secret'               => array(
					'type'         => 'password',
					'label'        => __( 'Yahoo Client Secret', 'mcp-ai-wpoos' ),
					'description'  => __( 'OAuth 2.0 Client Secret (Consumer Secret) from Yahoo Developer Network.', 'mcp-ai-wpoos' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),

				// ESPN Fantasy Sports.
				'espn_fantasy_espn_s2'              => array(
					'type'         => 'password',
					'label'        => __( 'ESPN S2 Cookie', 'mcp-ai-wpoos' ),
					'description'  => sprintf(
						/* translators: %s: URL to ESPN authentication docs */
						__( 'ESPN S2 authentication cookie for accessing private leagues. Required along with SWID cookie. See %s for how to obtain these cookies from your browser.', 'mcp-ai-wpoos' ),
						'<a href="https://github.com/cwendt94/espn-api/blob/master/README.md#espn-s2-and-swid" target="_blank">ESPN API Authentication Guide</a>'
					),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'espn_fantasy_swid'                 => array(
					'type'         => 'password',
					'label'        => __( 'ESPN SWID Cookie', 'mcp-ai-wpoos' ),
					'description'  => __( 'ESPN SWID authentication cookie for accessing private leagues. Required along with S2 cookie. Extract from browser after logging into ESPN Fantasy.', 'mcp-ai-wpoos' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
			);
		}

		/**
		 * Get sub-tab groups configuration.
		 *
		 * @return array
		 */
		protected function get_subtab_groups() {
			return array(
				'mailjet'       => array(
					'id'     => 'mailjet',
					'label'  => __( 'Mailjet', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-email',
					'fields' => array( 'mailjet_api_key', 'mailjet_api_secret', 'mailjet_from_email', 'mailjet_from_name', 'mailjet_webhook_secret' ),
				),
				'analytics'     => array(
					'id'     => 'analytics',
					'label'  => __( 'Google Analytics', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-chart-line',
					'fields' => array( 'google_analytics_property_id', 'google_analytics_credentials', 'google_analytics_credentials_json' ),
				),
				'fantasy_sports' => array(
					'id'     => 'fantasy_sports',
					'label'  => __( 'Fantasy Sports', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-games',
					'fields' => array( 'yahoo_client_id', 'yahoo_client_secret', 'espn_fantasy_espn_s2', 'espn_fantasy_swid' ),
				),
			);
		}

		/**
		 * Render section wrapper with subtab navigation.
		 */
		public function render_wrapper() {
			$description       = $this->get_description();
			$documentation_url = $this->get_documentation_url();
			$subtab_groups     = $this->get_subtab_groups();
			$active_subtab     = $this->get_active_subtab();
			?>
			<div class="settings-section" id="section-<?php echo esc_attr( $this->get_id() ); ?>">
				<h2><?php echo esc_html( $this->get_title() ); ?></h2>
				<?php if ( $description ) : ?>
					<p class="section-description"><?php echo wp_kses_post( $description ); ?></p>
				<?php endif; ?>
				<?php if ( $documentation_url ) : ?>
					<p class="section-documentation">
						<span class="dashicons dashicons-book-alt" style="color: #2271b1;"></span>
						<a href="<?php echo esc_url( $documentation_url ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'View Documentation', 'mcp-ai-wpoos' ); ?>
							<span class="dashicons dashicons-external" style="font-size: 14px; text-decoration: none;"></span>
						</a>
					</p>
				<?php endif; ?>

				<div class="wp-mcp-ai-provider-subtabs">
					<nav class="wp-mcp-ai-subtab-nav" aria-label="<?php esc_attr_e( 'Pro integrations sub-tabs', 'mcp-ai-wpoos' ); ?>">
						<?php foreach ( $subtab_groups as $group ) : ?>
							<?php
							$subtab_url = add_query_arg(
								array(
									'page'   => 'wp-mcp-ai-dashboard',
									'tab'    => 'tools',
									'subtab' => $group['id'],
								),
								admin_url( 'admin.php' )
							);
							$is_active  = ( $group['id'] === $active_subtab );
							?>
							<a href="<?php echo esc_url( $subtab_url ); ?>"
								class="wp-mcp-ai-subtab <?php echo esc_attr( $is_active ? 'wp-mcp-ai-subtab-active' : '' ); ?>"
								data-subtab="<?php echo esc_attr( $group['id'] ); ?>">
								<span class="dashicons <?php echo esc_attr( $group['icon'] ); ?>"></span>
								<?php echo esc_html( $group['label'] ); ?>
							</a>
						<?php endforeach; ?>
					</nav>

					<!-- Hidden field to preserve subtab during form submission -->
					<input type="hidden" name="subtab_<?php echo esc_attr( $this->get_id() ); ?>" value="<?php echo esc_attr( $active_subtab ); ?>" />
					<input type="hidden" name="subtab" value="<?php echo esc_attr( $active_subtab ); ?>" />

					<div class="wp-mcp-ai-subtab-content">
						<table class="form-table" role="presentation">
							<?php $this->render(); ?>
						</table>
					</div>
				</div>
			</div>
			<?php
		}

		/**
		 * Get active sub-tab.
		 *
		 * @return string
		 */
		protected function get_active_subtab() {
			$subtab_groups = $this->get_subtab_groups();
			$subtab        = '';

			// Check POST data first (when form is being submitted), then fall back to GET.
			// Use section-specific field name to avoid conflicts with other sections.
			// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Read-only parameter check for UI state.
			$subtab_field_name = 'subtab_' . $this->get_id();
			if ( isset( $_POST[ $subtab_field_name ] ) ) {
				$subtab = sanitize_key( $_POST[ $subtab_field_name ] );
			} elseif ( isset( $_POST['subtab'] ) ) {
				// Fallback to legacy field name for backward compatibility.
				$subtab = sanitize_key( $_POST['subtab'] );
			} elseif ( isset( $_GET['subtab'] ) ) {
				$subtab = sanitize_key( $_GET['subtab'] );
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended

			// Default to 'mailjet' if not set or invalid.
			if ( empty( $subtab ) || ! isset( $subtab_groups[ $subtab ] ) ) {
				$subtab = 'mailjet';
			}

			return $subtab;
		}

		/**
		 * Render the section content.
		 */
		public function render() {
			$fields        = $this->get_fields();
			$subtab_groups = $this->get_subtab_groups();
			$active_subtab = $this->get_active_subtab();

			// Get the active group.
			if ( ! isset( $subtab_groups[ $active_subtab ] ) ) {
				return;
			}

			$active_group = $subtab_groups[ $active_subtab ];

			// Render fields for the active sub-tab.
			foreach ( $active_group['fields'] as $key ) {
				if ( isset( $fields[ $key ] ) ) {
					$this->render_field( $key, $fields[ $key ] );
				}
			}
		}
	}
}
