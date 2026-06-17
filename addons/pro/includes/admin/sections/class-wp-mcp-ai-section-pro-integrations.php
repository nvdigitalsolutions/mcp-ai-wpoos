<?php
/**
 * Pro Integrations Settings Section
 *
 * Settings for pro-only integrations (Mailjet, Brevo, Mailgun, Google Analytics, Yahoo, ESPN).
 * These integrations have tools in the pro addon.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
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
			return __( 'Configure pro-only integration services (Mailjet, Brevo, Mailgun, Google Analytics, Fantasy Sports). These integrations are available in the Pro addon.', 'mcp-ai-wpoos' );
		}

		/**
		 * Get documentation URL for this section.
		 *
		 * @return string
		 */
		public function get_documentation_url() {
			return 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/developer/architecture/integrations/oauth-settings-architecture.md';
		}

		/**
		 * Get section priority.
		 *
		 * @return int
		 */
		public function get_priority() {
			return 25; // After base integrations (20).
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

				// Brevo.
				'brevo_api_key'                     => array(
					'type'         => 'password',
					'label'        => __( 'Brevo API Key', 'mcp-ai-wpoos' ),
					'description'  => sprintf(
						/* translators: %s: URL to Brevo developer docs */
						__( 'API key for Brevo (formerly Sendinblue) email marketing and CRM tools. Get this from your Brevo account under SMTP & API → API Keys. See %s for details.', 'mcp-ai-wpoos' ),
						'<a href="https://developers.brevo.com/docs/getting-started" target="_blank" rel="noopener noreferrer">Brevo developer docs</a>'
					),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'brevo_from_email'                  => array(
					'type'        => 'email',
					'label'       => __( 'Brevo From Email', 'mcp-ai-wpoos' ),
					'description' => __( 'Default "from" email address for Brevo messages. Must be a verified sender in your Brevo account.', 'mcp-ai-wpoos' ),
					'placeholder' => 'noreply@example.com',
				),
				'brevo_from_name'                   => array(
					'type'        => 'text',
					'label'       => __( 'Brevo From Name', 'mcp-ai-wpoos' ),
					'description' => __( 'Default "from" name for Brevo messages.', 'mcp-ai-wpoos' ),
					'placeholder' => 'My Site',
				),
				'brevo_webhook_secret'              => array(
					'type'         => 'password',
					'label'        => __( 'Brevo Webhook Secret', 'mcp-ai-wpoos' ),
					'description'  => __( 'Optional secret for verifying webhook requests from Brevo.', 'mcp-ai-wpoos' ),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),

				// Mailgun.
				'mailgun_api_key'                   => array(
					'type'         => 'password',
					'label'        => __( 'Mailgun API Key', 'mcp-ai-wpoos' ),
					'description'  => sprintf(
						/* translators: %s: URL to Mailgun API reference */
						__( 'Private API key for Mailgun email delivery. Get it from your Mailgun dashboard under API Security. See %s for details.', 'mcp-ai-wpoos' ),
						'<a href="https://documentation.mailgun.com/docs/mailgun/api-reference/send/mailgun" target="_blank" rel="noopener noreferrer">Mailgun API reference</a>'
					),
					'placeholder'  => '',
					'autocomplete' => 'new-password',
				),
				'mailgun_domain'                    => array(
					'type'        => 'text',
					'label'       => __( 'Mailgun Sending Domain', 'mcp-ai-wpoos' ),
					'description' => __( 'Verified Mailgun sending domain (e.g. mg.example.com). Found under Sending → Domains in your Mailgun dashboard.', 'mcp-ai-wpoos' ),
					'placeholder' => 'mg.example.com',
				),
				'mailgun_region'                    => array(
					'type'        => 'select',
					'label'       => __( 'Mailgun Region', 'mcp-ai-wpoos' ),
					'description' => __( 'Choose the Mailgun region that matches where your domain is registered.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'us' => __( 'US (api.mailgun.net)', 'mcp-ai-wpoos' ),
						'eu' => __( 'EU (api.eu.mailgun.net)', 'mcp-ai-wpoos' ),
					),
					'default'     => 'us',
				),
				'mailgun_from_email'                => array(
					'type'        => 'email',
					'label'       => __( 'Mailgun From Email', 'mcp-ai-wpoos' ),
					'description' => __( 'Default "from" email address for Mailgun messages. Must use your verified Mailgun sending domain.', 'mcp-ai-wpoos' ),
					'placeholder' => 'noreply@mg.example.com',
				),
				'mailgun_from_name'                 => array(
					'type'        => 'text',
					'label'       => __( 'Mailgun From Name', 'mcp-ai-wpoos' ),
					'description' => __( 'Default "from" name for Mailgun messages.', 'mcp-ai-wpoos' ),
					'placeholder' => 'My Site',
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

			);
		}

		/**
		 * Get sub-tab groups configuration.
		 *
		 * @return array
		 */
		protected function get_subtab_groups() {
			return array(
				'mailjet'   => array(
					'id'     => 'mailjet',
					'label'  => __( 'Mailjet', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-email',
					'fields' => array( 'mailjet_api_key', 'mailjet_api_secret', 'mailjet_from_email', 'mailjet_from_name', 'mailjet_webhook_secret' ),
				),
				'brevo'     => array(
					'id'     => 'brevo',
					'label'  => __( 'Brevo', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-email-alt',
					'fields' => array( 'brevo_api_key', 'brevo_from_email', 'brevo_from_name', 'brevo_webhook_secret' ),
				),
				'mailgun'   => array(
					'id'     => 'mailgun',
					'label'  => __( 'Mailgun', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-email-alt2',
					'fields' => array( 'mailgun_api_key', 'mailgun_domain', 'mailgun_region', 'mailgun_from_email', 'mailgun_from_name' ),
				),
				'analytics' => array(
					'id'     => 'analytics',
					'label'  => __( 'Google Analytics', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-chart-line',
					'fields' => array( 'google_analytics_property_id', 'google_analytics_credentials', 'google_analytics_credentials_json' ),
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
					<?php
					// Only emit the generic 'subtab' hidden field when this section's own
					// subtab is active in the URL. Otherwise a sibling section that also
					// emits the generic 'subtab' field (e.g. WP_MCP_AI_Section_Tools)
					// may have already set the correct value, and we would overwrite it
					// with our default (mailjet), causing the post-save redirect to land
					// on the wrong subtab.
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only UI state parameter.
					$url_subtab = isset( $_GET['subtab'] ) ? sanitize_key( wp_unslash( $_GET['subtab'] ) ) : '';
					if ( isset( $subtab_groups[ $url_subtab ] ) ) :
						?>
						<input type="hidden" name="subtab" value="<?php echo esc_attr( $active_subtab ); ?>" />
						<?php
					endif;
					?>

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
