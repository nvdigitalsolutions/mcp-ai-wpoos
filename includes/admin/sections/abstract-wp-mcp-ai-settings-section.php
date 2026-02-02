<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Descriptive file names follow WordPress kebab-case conventions for better readability.
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
		 * Federation/mesh checkboxes that need special logging for debugging display issues.
		 *
		 * @var array
		 */
		const FEDERATION_CHECKBOXES = array( 'enable_mesh', 'enable_federation', 'enable_federation_directory' );

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
		 * @param array $input Raw input from form.
		 * @return array Sanitized input.
		 */
		public function sanitize( $input ) {
			// Check if this section has subtabs by looking for get_subtab_groups method.
			if ( method_exists( $this, 'get_subtab_groups' ) ) {
				return $this->sanitize_with_subtabs( $input );
			}

			// Default sanitization for sections without subtabs.
			return $this->sanitize_fields( $input, $this->get_fields() );
		}

		/**
		 * Sanitize input for sections with sub-tabs.
		 *
		 * Only processes fields from the active sub-tab to prevent clearing
		 * settings from inactive sub-tabs when saving.
		 *
		 * @param array $input Raw input from form.
		 * @return array Sanitized input for active sub-tab only.
		 */
		protected function sanitize_with_subtabs( $input ) {
			$active_subtab = $this->get_active_subtab();
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
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller (handle_save_settings).
			$subtab_field_name = 'subtab_' . $this->get_id();
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller (handle_save_settings).
			$submitted_subtab = isset( $_POST[ $subtab_field_name ] ) ? sanitize_key( $_POST[ $subtab_field_name ] ) : '';

			// FALLBACK: Also check the legacy 'subtab' field for backward compatibility.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller (handle_save_settings).
			if ( empty( $submitted_subtab ) && isset( $_POST['subtab'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller (handle_save_settings).
				$submitted_subtab = sanitize_key( $_POST['subtab'] );
			}

			// ADDITIONAL FALLBACK: If still empty but we have settings data in POST,
			// and the active subtab is valid, assume this IS a form submission for the active subtab.
			// This handles edge cases where the hidden field might not be set correctly.
			// SECURITY NOTE: This is safe because the nonce is verified by the caller (handle_save_settings)
			// before this method is ever invoked, so we know this is a legitimate form submission.
			// We're simply being more tolerant of which specific subtab field is used to indicate the active tab.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller (handle_save_settings).
			//
			// ENHANCED: Also check if ANY fields from the active subtab are present in POST.
			// This is a stronger indicator that this is a form submission for this subtab.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller (handle_save_settings).
			if ( empty( $submitted_subtab ) && ! empty( $_POST['wp_mcp_ai_settings'] ) && isset( $subtab_groups[ $active_subtab ] ) ) {
				// Check if any fields from this subtab are in the POST data.
				$has_subtab_fields = false;
				$active_field_keys = $subtab_groups[ $active_subtab ]['fields'];
				foreach ( $active_field_keys as $field_key ) {
					// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller (handle_save_settings).
					if ( isset( $_POST['wp_mcp_ai_settings'][ $field_key ] ) ) {
						$has_subtab_fields = true;
						break;
					}
				}

				// If we have fields from this subtab in POST, it's definitely a submission for this subtab.
				if ( $has_subtab_fields ) {
					$submitted_subtab = $active_subtab;
				}
			}

			// Only consider this a form submit if the submitted subtab matches the active subtab.
			// AND the submitted subtab actually exists in this section's subtab groups.
			// This prevents cross-subtab data clearing when saving one subtab shouldn't affect others.
			$is_form_submit = ( $submitted_subtab === $active_subtab ) && isset( $subtab_groups[ $submitted_subtab ] );

			// Debug logging for subtab sanitization.
			// ENHANCED: Always log for debugging checkbox persistence issues.
			$settings       = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
			$enable_logging = ! empty( $settings['enable_logging'] ) || ! empty( $settings['enable_extended_logging'] );
			if ( $enable_logging || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
				// Enhanced logging: Also log the actual POST subtab field values.
				$post_subtab_fields = array();
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by caller (handle_save_settings).
				foreach ( $_POST as $key => $value ) {
					if ( strpos( $key, 'subtab_' ) === 0 ) {
						$post_subtab_fields[ $key ] = $value;
					}
				}
				error_log(
					sprintf(
						'[NV oOS Subtab Sanitize] Section: %s, Active: %s, Submitted: %s, Is Form Submit: %s, Field Count: %d, Fields: %s, POST field name: %s, POST subtab fields: %s',
						$this->get_id(),
						$active_subtab,
						$submitted_subtab,
						$is_form_submit ? 'YES' : 'NO',
						count( $active_field_keys ),
						implode( ', ', array_slice( $active_field_keys, 0, 10 ) ),
						$subtab_field_name,
						wp_json_encode( $post_subtab_fields )
					)
				);
			}

			// If this is not the subtab being submitted, return empty array to avoid.
			// processing fields from inactive subtabs and preserve their existing values.
			if ( ! $is_form_submit ) {
				if ( $enable_logging || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
					error_log(
						sprintf(
							'[NV oOS Subtab Sanitize] NOT processing section %s - not a form submit for this subtab',
							$this->get_id()
						)
					);
				}
				return array();
			}

			$result = $this->sanitize_fields( $input, $active_fields, $is_form_submit );

			// DEBUG: Log what's being returned from sanitize_with_subtabs.
			if ( $enable_logging || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
				$checkbox_keys     = array( 'enable_mesh', 'enable_federation', 'enable_federation_directory' );
				$result_checkboxes = array();
				foreach ( $checkbox_keys as $key ) {
					if ( isset( $result[ $key ] ) ) {
						$result_checkboxes[ $key ] = $result[ $key ] ? 'true' : 'false';
					}
				}
				if ( ! empty( $result_checkboxes ) ) {
					error_log(
						sprintf(
							'[NV oOS Subtab Sanitize] Section %s returning checkboxes: %s',
							$this->get_id(),
							wp_json_encode( $result_checkboxes )
						)
					);
				}
			}

			// CRITICAL: Always log federation checkbox sanitization for debugging.
			// This helps diagnose the persistent issue where enable_federation_directory doesn't save.
			if ( 'advanced' === $this->get_id() && ! empty( $result ) ) {
				$fed_keys     = array( 'enable_mesh', 'enable_federation', 'enable_federation_directory' );
				$has_fed_keys = false;
				$fed_values   = array();
				foreach ( $fed_keys as $key ) {
					if ( isset( $result[ $key ] ) ) {
						$has_fed_keys       = true;
						$fed_values[ $key ] = var_export( $result[ $key ], true );
					}
				}
				if ( $has_fed_keys ) {
					error_log(
						sprintf(
							'[NV oOS FEDERATION DEBUG] Section: %s, Subtab: %s, Is Form Submit: %s, Checkboxes: %s',
							$this->get_id(),
							$active_subtab,
							$is_form_submit ? 'YES' : 'NO',
							wp_json_encode( $fed_values )
						)
					);
				}
			}

			return $result;
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

			// DEFENSIVE: Filter input to only include fields that are defined in $fields.
			// This prevents fields from other subtabs from being processed if they somehow
			// end up in the POST data (e.g., browser autofill, JavaScript manipulation, etc.).
			$filtered_input = array();
			foreach ( $fields as $key => $field ) {
				if ( isset( $input[ $key ] ) ) {
					$filtered_input[ $key ] = $input[ $key ];
				}
			}

			// DEBUG: Log filtered input for checkboxes to trace the issue.
			$settings       = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
			$enable_logging = ! empty( $settings['enable_logging'] ) || ! empty( $settings['enable_extended_logging'] );
			if ( $enable_logging || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
				$checkbox_fields = array();
				foreach ( $fields as $key => $field ) {
					if ( isset( $field['type'] ) && 'checkbox' === $field['type'] ) {
						$checkbox_fields[ $key ] = isset( $input[ $key ] ) ? $input[ $key ] : 'NOT_IN_INPUT';
					}
				}
				if ( ! empty( $checkbox_fields ) ) {
					error_log(
						sprintf(
							'[NV oOS Checkbox Debug] Section: %s, Is Form Submit: %s, Checkbox values in input: %s',
							$this->get_id(),
							$is_form_submit ? 'YES' : 'NO',
							wp_json_encode( $checkbox_fields )
						)
					);
				}
			}

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
						// Checkbox is checked if present in input with truthy value, unchecked otherwise.
						// Explicitly check for '0' string (from hidden fields) and treat it as false.
						$checkbox_value  = false;
						$raw_value_debug = 'NOT SET';
						if ( isset( $filtered_input[ $key ] ) ) {
							$raw_value       = $filtered_input[ $key ];
							$raw_value_debug = var_export( $raw_value, true );
							// Convert '0' string to false, '1' string to true, and use bool cast for other values.
							$checkbox_value = ( '0' === $raw_value || 0 === $raw_value ) ? false : (bool) $raw_value;
						}
						$sanitized[ $key ] = $checkbox_value;

						// Enhanced logging for checkbox processing - especially for federation/mesh checkboxes.
						$settings               = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
						$enable_logging         = ! empty( $settings['enable_logging'] ) || ! empty( $settings['enable_extended_logging'] );
						$is_federation_checkbox = in_array( $key, self::FEDERATION_CHECKBOXES, true );
						if ( ( $enable_logging || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) && $is_federation_checkbox ) {
							error_log(
								sprintf(
									'[NV oOS Checkbox Save] Key: %s, In Input: %s, Raw Value: %s, Final Value: %s (boolean %s)',
									$key,
									isset( $filtered_input[ $key ] ) ? 'YES' : 'NO',
									$raw_value_debug,
									$checkbox_value ? 'true' : 'false',
									var_export( $checkbox_value, true )
								)
							);
						}
					}
					// If not the submitted form, skip this checkbox entirely to preserve existing value.
					continue;
				}

				// For other field types, skip if not present in filtered input.
				if ( ! isset( $filtered_input[ $key ] ) ) {
					continue;
				}

				$value = $filtered_input[ $key ];

				switch ( $type ) {
					case 'text':
						// Handle array values to prevent warnings.
						if ( is_array( $value ) ) {
							$sanitized[ $key ] = wp_json_encode( $value );
						} else {
							$sanitized[ $key ] = sanitize_text_field( $value );
						}
						break;

					case 'password':
						// CRITICAL: Only save password fields if they contain a value.
						// Empty password fields should not overwrite existing saved values.
						// This prevents accidental deletion of API keys when saving other subtabs.
						// Handle array values to prevent warnings.
						if ( is_array( $value ) ) {
							// Arrays should not be password fields, skip.
							break;
						}
						$trimmed_value = trim( sanitize_text_field( $value ) );
						if ( '' !== $trimmed_value ) {
							$sanitized[ $key ] = $trimmed_value;
						}
						// If empty, skip adding to sanitized array to preserve existing value.
						break;

					case 'url':
						// Keep empty strings, only sanitize non-empty values with proper URL function.
						// Handle array values to prevent warnings.
						if ( is_array( $value ) ) {
							$sanitized[ $key ] = wp_json_encode( $value );
						} else {
							$sanitized[ $key ] = '' === $value ? '' : esc_url_raw( $value );
						}
						break;

					case 'textarea':
						// Handle array values to prevent warnings.
						if ( is_array( $value ) ) {
							$sanitized[ $key ] = wp_json_encode( $value );
						} elseif ( 'mesh_peer_sites' === $key ) {
							// Special handling for mesh_peer_sites: decode JSON string to array.
							// This field stores peer site configurations as JSON in a textarea.
							// Empty or invalid JSON should default to empty array (not validation error).
							$trimmed = trim( $value );
							if ( empty( $trimmed ) ) {
								// Empty textarea = empty array (valid, no peers configured yet).
								$sanitized[ $key ] = array();
							} else {
								// Attempt to decode JSON string to array.
								$decoded = json_decode( $trimmed, true );
								if ( is_array( $decoded ) ) {
									// Valid JSON array - will be further sanitized by sanitize_mesh_peer_sites().
									$sanitized[ $key ] = $decoded;
								} else {
									// Invalid JSON - log error and default to empty array.
									$settings       = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
									$enable_logging = ! empty( $settings['enable_logging'] ) || ! empty( $settings['enable_extended_logging'] );
									if ( $enable_logging || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
										error_log(
											sprintf(
												'[NV oOS Settings] Invalid JSON in mesh_peer_sites field. Value: %s, JSON Error: %s',
												substr( $trimmed, 0, 100 ),
												json_last_error_msg()
											)
										);
									}
									$sanitized[ $key ] = array();
								}
							}
						} else {
							$sanitized[ $key ] = sanitize_textarea_field( $value );
						}
						break;

					case 'email':
						// Handle array values to prevent warnings.
						if ( is_array( $value ) ) {
							$sanitized[ $key ] = wp_json_encode( $value );
						} else {
							$sanitized[ $key ] = sanitize_email( $value );
						}
						break;

					case 'number':
						// Handle empty strings differently based on field definition:
						// 1. If field explicitly allows empty string (e.g., filter fields with default=''),
						// preserve the empty string for "use auto-detection" functionality.
						// 2. Otherwise, skip empty values to prevent overwriting existing settings.
						if ( '' === $value ) {
							// Check if this field explicitly allows empty strings by checking if default is ''.
							if ( isset( $fields[ $key ]['default'] ) && '' === $fields[ $key ]['default'] ) {
								// Field intentionally uses empty string (e.g., filter fields for auto-detection).
								$sanitized[ $key ] = '';
							}
							// Otherwise skip - don't overwrite existing value with empty string.
							break;
						}
						$sanitized[ $key ] = absint( $value );
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
						// Use strict comparison for in_array check.
						if ( in_array( $typed_value, $options, true ) ) {
							$sanitized[ $key ] = $typed_value;
						}
						break;

					default:
						// Handle array values to prevent "Array to string conversion" warnings.
						if ( is_array( $value ) ) {
							// If value is an array, serialize it to JSON for storage.
							$sanitized[ $key ] = wp_json_encode( $value );
						} else {
							$sanitized[ $key ] = sanitize_text_field( $value );
						}
						break;
				}
			}

			return $sanitized;
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

			// Enhanced logging for federation/mesh checkboxes to help debug display issues.
			if ( 'checkbox' === $type && in_array( $key, self::FEDERATION_CHECKBOXES, true ) ) {
				$settings       = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
				$enable_logging = ! empty( $settings['enable_logging'] ) || ! empty( $settings['enable_extended_logging'] );
				if ( $enable_logging || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
					error_log(
						sprintf(
							'[NV oOS Checkbox Render] Key: %s, Raw Value: %s (type: %s), Default: %s',
							$key,
							var_export( $value, true ),
							gettype( $value ),
							var_export( isset( $field['default'] ) ? $field['default'] : '', true )
						)
					);
				}
			}

			?>
			<tr>
				<th scope="row">
					<label for="<?php echo esc_attr( $key ); ?>">
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
								class="regular-text"
								placeholder="<?php echo esc_attr( $placeholder ); ?>"
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
								class="regular-text"
								placeholder="<?php echo esc_attr( $placeholder ); ?>"
								autocomplete="<?php echo esc_attr( ! empty( $autocomplete ) ? $autocomplete : 'new-password' ); ?>"
								<?php echo esc_attr( $required ? 'required' : '' ); ?>
							/>
							<?php
							break;

						case 'textarea':
							// Handle array values - convert to JSON string for display.
							if ( is_array( $value ) ) {
								$json_value = wp_json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
								// Handle encoding failure gracefully.
								$textarea_value = ( false !== $json_value ) ? $json_value : wp_json_encode( $value );
							} else {
								$textarea_value = $value;
							}
							?>
							<textarea
								id="<?php echo esc_attr( $key ); ?>"
								name="wp_mcp_ai_settings[<?php echo esc_attr( $key ); ?>]"
								rows="5"
								class="large-text code"
								placeholder="<?php echo esc_attr( $placeholder ); ?>"
								<?php echo esc_attr( $required ? 'required' : '' ); ?>
							><?php echo esc_textarea( $textarea_value ); ?></textarea>
							<?php
							break;

						case 'checkbox':
							// CRITICAL FIX: Normalize checkbox value to boolean for reliable checked() comparison.
							// The value might be stored as string "1", integer 1, boolean true, or other truthy values.
							// Convert to proper boolean to ensure checked() works correctly.
							$is_checked     = ! empty( $value ) && '0' !== $value && 0 !== $value;
							$checkbox_label = isset( $field['checkbox_label'] ) ? $field['checkbox_label'] : '';

							// Enhanced logging for federation/mesh checkboxes to verify render state.
							if ( in_array( $key, self::FEDERATION_CHECKBOXES, true ) ) {
								$settings       = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
								$enable_logging = ! empty( $settings['enable_logging'] ) || ! empty( $settings['enable_extended_logging'] );
								if ( $enable_logging || ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
									error_log(
										sprintf(
											'[NV oOS Federation Mesh] Checkbox Render: %s, $is_checked: %s, Will render checked attr: %s',
											$key,
											$is_checked ? 'true' : 'false',
											checked( $is_checked, true, false )
										)
									);
								}
							}
							?>
							<div class="wp-mcp-ai-settings-toggle-wrapper">
								<label class="wp-mcp-ai-settings-toggle-switch" for="<?php echo esc_attr( $key ); ?>">
									<input
										type="checkbox"
										id="<?php echo esc_attr( $key ); ?>"
										name="wp_mcp_ai_settings[<?php echo esc_attr( $key ); ?>]"
										value="1"
										<?php checked( $is_checked, true ); ?>
										<?php disabled( $disabled ); ?>
									/>
									<span class="wp-mcp-ai-settings-toggle-slider"></span>
								</label>
								<?php if ( ! empty( $checkbox_label ) ) : ?>
									<label class="wp-mcp-ai-settings-toggle-label" for="<?php echo esc_attr( $key ); ?>">
										<?php echo esc_html( $checkbox_label ); ?>
									</label>
								<?php endif; ?>
							</div>
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

						case 'custom':
							// For custom field types, call the provided callback function.
							if ( isset( $field['callback'] ) && is_callable( $field['callback'] ) ) {
								call_user_func( $field['callback'], $field );
							}
							break;
					}

					if ( $description && 'custom' !== $type ) :
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
