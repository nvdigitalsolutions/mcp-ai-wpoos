<?php
/**
 * Abstract base class for settings sections.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Settings_Section' ) ) {
	/**
	 * Base class that all settings sections must extend.
	 */
	abstract class WP_MCP_AI_Settings_Section {
		/**
		 * Get the section ID.
		 *
		 * @return string
		 */
		abstract public function get_id();

		/**
		 * Get the section title.
		 *
		 * @return string
		 */
		abstract public function get_title();

		/**
		 * Get the tab this section belongs to.
		 *
		 * @return string
		 */
		abstract public function get_tab();

		/**
		 * Get field definitions for this section.
		 *
		 * @return array
		 */
		abstract public function get_fields();

		/**
		 * Render the section content.
		 */
		abstract public function render();

		/**
		 * Get section priority (for ordering within tab).
		 *
		 * @return int Lower numbers render first.
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
			return '';
		}

		/**
		 * Get documentation URL for this section.
		 *
		 * Override this method in child classes to provide section-specific documentation links.
		 *
		 * @return string Documentation URL or empty string if no documentation available.
		 */
		public function get_documentation_url() {
			return '';
		}

		/**
		 * Validate input for this section.
		 *
		 * @param array $input Raw input from form.
		 * @return array|WP_Error Validated input or error.
		 */
		public function validate( $input ) {
			return $input;
		}

		/**
		 * Sanitize input for this section.
		 *
		 * @param array  $input Raw input from form.
		 * @param string $active_subtab Optional. Explicit subtab to process (for sections with subtabs).
		 * @param bool   $is_active_tab Optional. Whether this section's tab is the active tab being saved.
		 * @return array Sanitized input.
		 */
		public function sanitize( $input, $active_subtab = null, $is_active_tab = false ) {
			// Check if this section has subtabs by looking for get_subtab_groups method.
			if ( method_exists( $this, 'get_subtab_groups' ) ) {
				return $this->sanitize_with_subtabs( $input, $active_subtab );
			}

			// P0 FIX #2: Default sanitization for sections without subtabs.
			// Only treat as form submission if this section's tab is active to prevent
			// checkbox clearing on non-active tabs.
			return $this->sanitize_fields( $input, $this->get_fields(), $is_active_tab );
		}

		/**
		 * Sanitize input for sections with sub-tabs.
		 *
		 * Only processes fields from the active sub-tab to prevent clearing
		 * settings from inactive sub-tabs when saving.
		 *
		 * @param array  $input Raw input from form.
		 * @param string $explicit_subtab Optional. Explicit subtab to process (passed from dashboard).
		 * @return array Sanitized input for active sub-tab only.
		 */
		protected function sanitize_with_subtabs( $input, $explicit_subtab = null ) {
			// P0 FIX #1: Use explicit subtab if provided, otherwise detect from POST/GET.
			if ( null !== $explicit_subtab ) {
				$active_subtab = $explicit_subtab;
			} else {
				$active_subtab = $this->get_active_subtab();
			}
			
			$subtab_groups = $this->get_subtab_groups();

			// Get fields that belong to the active sub-tab.
			if ( ! isset( $subtab_groups[ $active_subtab ] ) ) {
				return array();
			}

			$active_field_keys = $subtab_groups[ $active_subtab ]['fields'];
			$all_fields        = $this->get_fields();

			// Filter to only active fields.
			$active_fields = array();
			foreach ( $active_field_keys as $field_key ) {
				if ( isset( $all_fields[ $field_key ] ) ) {
					$active_fields[ $field_key ] = $all_fields[ $field_key ];
				}
			}

			// Check if we're actually processing a form submission for this subtab.
			// Use section-specific subtab field name to avoid conflicts when multiple sections have subtabs.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller.
			$subtab_field_name = 'subtab_' . $this->get_id();
			$submitted_subtab  = isset( $_POST[ $subtab_field_name ] ) ? sanitize_key( $_POST[ $subtab_field_name ] ) : '';

			// Only consider this a form submit if the submitted subtab matches the active subtab.
			// AND the submitted subtab actually exists in this section's subtab groups.
			// This prevents cross-subtab data clearing when saving one subtab shouldn't affect others.
			$is_form_submit = ( $submitted_subtab === $active_subtab ) && isset( $subtab_groups[ $submitted_subtab ] );

			// If this is not the subtab being submitted, return empty array to avoid.
			// processing fields from inactive subtabs and preserve their existing values.
			if ( ! $is_form_submit ) {
				return array();
			}

			return $this->sanitize_fields( $input, $active_fields, $is_form_submit );
		}

		/**
		 * Sanitize fields based on their type.
		 *
		 * @param array $input         Raw input from form.
		 * @param array $fields        Field definitions to sanitize.
		 * @param bool  $is_form_submit Whether this is an actual form submission (for checkbox handling).
		 * @return array Sanitized input.
		 */
		protected function sanitize_fields( $input, $fields, $is_form_submit = true ) {
			$sanitized = array();

			foreach ( $fields as $key => $field ) {
				$type = isset( $field['type'] ) ? $field['type'] : 'text';

				// Skip display-only field types (html, custom, hidden) as they don't have user input.
				// Hidden fields are used to preserve OAuth tokens and other programmatically-set values.
				if ( in_array( $type, array( 'html', 'custom', 'hidden' ), true ) ) {
					continue;
				}

				// Special handling for checkboxes.
				if ( 'checkbox' === $type ) {
					// Only process checkboxes if this is actually the form being submitted.
					// This prevents checkboxes from other subtabs from being set to false.
					if ( $is_form_submit ) {
						// Checkbox is checked if present in input, unchecked otherwise.
						$sanitized[ $key ] = isset( $input[ $key ] ) ? (bool) $input[ $key ] : false;
					}
					// If not the submitted form, skip this checkbox entirely to preserve existing value.
					continue;
				}

				// For other field types, skip if not present in input.
				if ( ! isset( $input[ $key ] ) ) {
					continue;
				}

				$value = $input[ $key ];

				switch ( $type ) {
					case 'text':
					case 'password':
						$sanitized[ $key ] = sanitize_text_field( $value );
						break;

					case 'url':
						// Keep empty strings, only sanitize non-empty values with proper URL function.
						$sanitized[ $key ] = '' === $value ? '' : esc_url_raw( $value );
						break;

					case 'textarea':
						$sanitized[ $key ] = sanitize_textarea_field( $value );
						break;

					case 'email':
						$sanitized[ $key ] = sanitize_email( $value );
						break;

					case 'number':
						// Keep empty strings for "use default" functionality (e.g., filter fields).
						$sanitized[ $key ] = '' === $value ? '' : absint( $value );
						break;

					case 'range':
					case 'slider':
						$min               = isset( $field['min'] ) ? (int) $field['min'] : 0;
						$max               = isset( $field['max'] ) ? (int) $field['max'] : 100;
						$sanitized_value   = absint( $value );
						$sanitized[ $key ] = max( $min, min( $max, $sanitized_value ) );
						break;

					case 'select':
						$options = isset( $field['options'] ) ? array_keys( $field['options'] ) : array();
						// Convert value to match the type of option keys for proper comparison.
						// Form submissions send all values as strings, but option keys might be integers.
						$typed_value = $value;
						if ( ! empty( $options ) ) {
							// Check if we have numeric option keys - if so, convert value to int for comparison.
							$first_key = $options[0];
							if ( is_int( $first_key ) && is_numeric( $value ) ) {
								$typed_value = absint( $value );
							}
						}
						// Use non-strict comparison to handle string/int type juggling.
						if ( in_array( $typed_value, $options, false ) ) {
							$sanitized[ $key ] = $typed_value;
						}
						break;

					default:
						$sanitized[ $key ] = sanitize_text_field( $value );
						break;
				}
			}

			return $sanitized;
		}

		/**
		 * Check if a field is a sensitive field that should be protected.
		 *
		 * @param string $key Field key to check.
		 * @return bool True if sensitive, false otherwise.
		 */
		protected function is_sensitive_field( $key ) {
			// List of sensitive field names.
			$sensitive_fields = array(
				'openai_api_key',
				'openai_organization_id',
				'anthropic_api_key',
				'gemini_api_key',
				'huggingface_api_key',
				'huggingface_endpoint_url',
				'huggingface_datasets_api_token',
				'ollama_endpoint_url',
				'lm_studio_endpoint_url',
				'cloudflare_account_id',
				'cloudflare_api_token',
				'cloudflare_zone_id',
				'brave_search_api_key',
				'mubert_api_key',
				'google_maps_api_key',
				'auth0_domain',
				'auth0_client_id',
				'auth0_client_secret',
				'auth0_management_client_id',
				'auth0_management_client_secret',
				'oauth_google_client_id',
				'oauth_google_client_secret',
				'gmail_client_id',
				'gmail_client_secret',
				'google_drive_client_id',
				'google_drive_client_secret',
				'github_client_id',
				'github_client_secret',
				'cloudways_api_key',
				'cloudways_api_email',
				'cloudways_server_id',
				'cloudways_app_id',
				'crawl4ai_api_key',
				'removebg_api_key',
				'mailjet_api_key',
				'mailjet_api_secret',
				'mailjet_client_id',
				'mailjet_client_secret',
				'ita_tariff_api_key',
				'google_analytics_credentials',
				'google_analytics_credentials_json',
				'mesh_inbound_api_key',
				'quickbooks_api_key',
				'quickbooks_client_id',
				'quickbooks_client_secret',
				'meta_app_id',
				'meta_business_account_id',
				'tiktok_client_secret',
			);

			// Check exact match first.
			if ( in_array( $key, $sensitive_fields, true ) ) {
				return true;
			}

			// Check pattern match for sensitive field names.
			$sensitive_patterns = array(
				'/_api_key$/',
				'/_api_secret$/',
				'/_api_token$/',
				'/_client_id$/',
				'/_client_secret$/',
				'/_access_token$/',
				'/_refresh_token$/',
				'/_private_key$/',
				'/_credentials$/',
				'/_credentials_json$/',
			);

			foreach ( $sensitive_patterns as $pattern ) {
				if ( preg_match( $pattern, $key ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Render a field based on its configuration.
		 *
		 * @param string $key Field key.
		 * @param array  $field Field configuration.
		 */
		protected function render_field( $key, $field ) {
			$type         = isset( $field['type'] ) ? $field['type'] : 'text';
			$label        = isset( $field['label'] ) ? $field['label'] : '';
			$description  = isset( $field['description'] ) ? $field['description'] : '';
			$value        = WP_MCP_AI_Settings_Registry::get_setting( $key, isset( $field['default'] ) ? $field['default'] : '' );
			$placeholder  = isset( $field['placeholder'] ) ? $field['placeholder'] : '';
			$required     = isset( $field['required'] ) ? $field['required'] : false;
			$autocomplete = isset( $field['autocomplete'] ) ? $field['autocomplete'] : '';
			$disabled     = isset( $field['disabled'] ) ? $field['disabled'] : false;
			$pro_badge    = isset( $field['pro_badge'] ) ? $field['pro_badge'] : false;
			
			// Check if this is a sensitive field that should be protected.
			$is_sensitive = $this->is_sensitive_field( $key );

			?>
			<tr>
				<th scope="row">
					<label for="<?php echo esc_attr( $key ); ?>">
						<?php if ( $is_sensitive ) : ?>
							<span class="dashicons dashicons-lock" style="color: #d63638; font-size: 16px; vertical-align: middle;" title="<?php esc_attr_e( 'Sensitive field - protected from accidental clearing', 'mcp-ai-wpoos' ); ?>"></span>
						<?php endif; ?>
						<?php echo esc_html( $label ); ?>
						<?php if ( $required ) : ?>
							<span class="required">*</span>
						<?php endif; ?>
						<?php if ( $pro_badge ) : ?>
							<span class="wp-mcp-ai-pro-badge" style="display: inline-block; margin-left: 8px; padding: 3px 8px; background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 3px; font-size: 10px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px; vertical-align: middle;">
								<?php esc_html_e( 'PRO', 'mcp-ai-wpoos' ); ?>
							</span>
						<?php endif; ?>
					</label>
				</th>
				<td>
					<?php
					switch ( $type ) {
						case 'text':
						case 'email':
						case 'url':
						case 'number':
							?>
							<input
								type="<?php echo esc_attr( $type ); ?>"
								id="<?php echo esc_attr( $key ); ?>"
								name="wp_mcp_ai_settings[<?php echo esc_attr( $key ); ?>]"
								value="<?php echo esc_attr( $value ); ?>"
								class="regular-text<?php echo esc_attr( $is_sensitive ? ' wp-mcp-ai-sensitive-field' : '' ); ?>"
								placeholder="<?php echo esc_attr( $placeholder ); ?>"
								<?php if ( $is_sensitive && ! empty( $value ) ) : ?>
									data-original-value="<?php echo esc_attr( $value ); ?>"
								<?php endif; ?>
								<?php if ( ! empty( $autocomplete ) ) : ?>
									autocomplete="<?php echo esc_attr( $autocomplete ); ?>"
								<?php endif; ?>
								<?php echo esc_attr( $required ? 'required' : '' ); ?>
							/>
							<?php
							break;

						case 'password':
							?>
							<input
								type="password"
								id="<?php echo esc_attr( $key ); ?>"
								name="wp_mcp_ai_settings[<?php echo esc_attr( $key ); ?>]"
								value="<?php echo esc_attr( $value ); ?>"
								class="regular-text<?php echo esc_attr( $is_sensitive ? ' wp-mcp-ai-sensitive-field' : '' ); ?>"
								placeholder="<?php echo esc_attr( $placeholder ); ?>"
								<?php if ( $is_sensitive && ! empty( $value ) ) : ?>
									data-original-value="<?php echo esc_attr( $value ); ?>"
								<?php endif; ?>
								autocomplete="<?php echo esc_attr( ! empty( $autocomplete ) ? $autocomplete : 'new-password' ); ?>"
								<?php echo esc_attr( $required ? 'required' : '' ); ?>
							/>
							<?php
							break;

						case 'textarea':
							?>
							<textarea
								id="<?php echo esc_attr( $key ); ?>"
								name="wp_mcp_ai_settings[<?php echo esc_attr( $key ); ?>]"
								rows="5"
								class="large-text code"
								placeholder="<?php echo esc_attr( $placeholder ); ?>"
								<?php echo esc_attr( $required ? 'required' : '' ); ?>
							><?php echo esc_textarea( $value ); ?></textarea>
							<?php
							break;

						case 'checkbox':
							?>
							<label>
								<input
									type="checkbox"
									id="<?php echo esc_attr( $key ); ?>"
									name="wp_mcp_ai_settings[<?php echo esc_attr( $key ); ?>]"
									value="1"
									<?php checked( $value, true ); ?>
									<?php disabled( $disabled ); ?>
								/>
								<?php echo isset( $field['checkbox_label'] ) ? esc_html( $field['checkbox_label'] ) : ''; ?>
							</label>
							<?php
							break;

						case 'select':
							$options = isset( $field['options'] ) ? $field['options'] : array();
							?>
							<select
								id="<?php echo esc_attr( $key ); ?>"
								name="wp_mcp_ai_settings[<?php echo esc_attr( $key ); ?>]"
								<?php echo esc_attr( $required ? 'required' : '' ); ?>
							>
								<?php foreach ( $options as $option_value => $option_label ) : ?>
									<option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $value, $option_value ); ?>>
										<?php echo esc_html( $option_label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<?php
							break;
					}

					if ( $description ) :
						?>
						<p class="description"><?php echo wp_kses_post( $description ); ?></p>
						<?php
					endif;
					?>
				</td>
			</tr>
			<?php
		}

		/**
		 * Render the section wrapper.
		 */
		public function render_wrapper() {
			$description       = $this->get_description();
			$documentation_url = $this->get_documentation_url();
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
				<table class="form-table" role="presentation">
					<?php $this->render(); ?>
				</table>
			</div>
			<?php
		}
	}
}
