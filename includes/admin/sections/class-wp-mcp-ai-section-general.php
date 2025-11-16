<?php
/**
 * General Settings Section
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_General' ) ) {
	/**
	 * General settings section.
	 */
	class WP_MCP_AI_Section_General extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'general';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'General Settings', 'wp-mcp-ai' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'general';
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
			return __( 'Configure basic plugin settings and operational parameters.', 'wp-mcp-ai' );
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			// Get available providers for dropdown.
			$provider_options = array(
				'openai'    => __( 'OpenAI', 'wp-mcp-ai' ),
				'gemini'    => __( 'Google Gemini', 'wp-mcp-ai' ),
				'ollama'    => __( 'Ollama (Local AI)', 'wp-mcp-ai' ),
				'lm_studio' => __( 'LM Studio (Local AI)', 'wp-mcp-ai' ),
			);

			// Get available assistants for dropdown.
			$assistant_options = $this->get_assistant_options();

			return array(
				'default_provider'     => array(
					'type'        => 'select',
					'label'       => __( 'Default AI Provider', 'wp-mcp-ai' ),
					'description' => __( 'The primary AI provider used when no specific provider is specified. This affects new conversations and REST API requests. Make sure the selected provider is properly configured in the Providers tab.', 'wp-mcp-ai' ),
					'options'     => $provider_options,
					'default'     => 'openai',
				),
				'default_assistant'    => array(
					'type'        => 'select',
					'label'       => __( 'Default Assistant', 'wp-mcp-ai' ),
					'description' => __( 'The assistant used by default when one is not explicitly specified in REST API interactions. Leave as "None" to require explicit assistant selection.', 'wp-mcp-ai' ),
					'options'     => $assistant_options,
					'default'     => 0,
				),
				'enable_logging'       => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Logging', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable basic error and activity logging', 'wp-mcp-ai' ),
					'description'    => __( 'Records errors, warnings, and key activity (tool executions, API requests) to help troubleshoot issues. View logs in the Advanced tab.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'delete_on_uninstall'  => array(
					'type'           => 'checkbox',
					'label'          => __( 'Delete Data on Uninstall', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Remove all plugin data when uninstalling', 'wp-mcp-ai' ),
					'description'    => __( 'When enabled, all settings and data will be deleted when the plugin is uninstalled.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'max_history_messages' => array(
					'type'        => 'number',
					'label'       => __( 'Max History Messages', 'wp-mcp-ai' ),
					'description' => __( 'Maximum number of previous messages to include in chat context. Higher values provide more context but increase token usage.', 'wp-mcp-ai' ),
					'default'     => 10,
					'placeholder' => '10',
					'min'         => 1,
					'max'         => 100,
				),
				'request_timeout'      => array(
					'type'        => 'number',
					'label'       => __( 'Request Timeout (seconds)', 'wp-mcp-ai' ),
					'description' => __( 'How long to wait for AI provider responses before timing out. Increase for complex requests or slower providers.', 'wp-mcp-ai' ),
					'default'     => 60,
					'placeholder' => '60',
					'min'         => 10,
					'max'         => 600,
				),
			);
		}

		/**
		 * Get sub-tab groups configuration.
		 *
		 * @return array
		 */
		private function get_subtab_groups() {
			return array(
				'core'     => array(
					'id'     => 'core',
					'label'  => __( 'Core Settings', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-admin-settings',
					'fields' => array( 'default_provider', 'default_assistant' ),
				),
				'behavior' => array(
					'id'     => 'behavior',
					'label'  => __( 'Behavior & Limits', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-performance',
					'fields' => array( 'max_history_messages', 'request_timeout' ),
				),
				'data'     => array(
					'id'     => 'data',
					'label'  => __( 'Data Management', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-database',
					'fields' => array( 'enable_logging', 'delete_on_uninstall' ),
				),
			);
		}

		/**
		 * Get active sub-tab.
		 *
		 * @return string
		 */
		private function get_active_subtab() {
			$subtab_groups = $this->get_subtab_groups();
			$subtab        = '';

			// Check POST data first (when form is being submitted), then fall back to GET.
			// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Read-only parameter check.
			if ( isset( $_POST['subtab'] ) ) {
				$subtab = sanitize_key( $_POST['subtab'] );
			} elseif ( isset( $_GET['subtab'] ) ) {
				$subtab = sanitize_key( $_GET['subtab'] );
			}

			// Default to 'core' if not set or invalid.
			if ( empty( $subtab ) || ! isset( $subtab_groups[ $subtab ] ) ) {
				$subtab = 'core';
			}

			return $subtab;
		}

		/**
		 * Get assistant options for select dropdown.
		 *
		 * @return array
		 */
		private function get_assistant_options() {
			$options = array(
				0 => __( 'None (explicit selection required)', 'wp-mcp-ai' ),
			);

			// Get published assistants.
			$assistants = get_posts(
				array(
					'post_type'      => 'mcp_ai_assistant',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'orderby'        => 'title',
					'order'          => 'ASC',
				)
			);

			if ( ! empty( $assistants ) ) {
				foreach ( $assistants as $assistant ) {
					$options[ $assistant->ID ] = $assistant->post_title;
				}
			}

			return $options;
		}

		/**
		 * Render section fields.
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

		/**
		 * Override render_wrapper to include sub-tab navigation.
		 */
		public function render_wrapper() {
			$description   = $this->get_description();
			$subtab_groups = $this->get_subtab_groups();
			$active_subtab = $this->get_active_subtab();
			?>
		<div class="settings-section" id="section-<?php echo esc_attr( $this->get_id() ); ?>">
			<h2><?php echo esc_html( $this->get_title() ); ?></h2>
			<?php if ( $description ) : ?>
				<p class="section-description"><?php echo wp_kses_post( $description ); ?></p>
			<?php endif; ?>

			<div class="wp-mcp-ai-provider-subtabs">
				<nav class="wp-mcp-ai-subtab-nav" aria-label="<?php esc_attr_e( 'General settings sub-tabs', 'wp-mcp-ai' ); ?>">
					<?php foreach ( $subtab_groups as $group ) : ?>
						<?php
						$subtab_url = add_query_arg(
							array(
								'page'   => 'wp-mcp-ai-dashboard',
								'tab'    => 'general',
								'subtab' => $group['id'],
							),
							admin_url( 'admin.php' )
						);
						$is_active  = ( $group['id'] === $active_subtab );
						?>
						<a href="<?php echo esc_url( $subtab_url ); ?>" 
							class="wp-mcp-ai-subtab <?php echo $is_active ? 'wp-mcp-ai-subtab-active' : ''; ?>"
							data-subtab="<?php echo esc_attr( $group['id'] ); ?>">
							<span class="dashicons <?php echo esc_attr( $group['icon'] ); ?>"></span>
							<?php echo esc_html( $group['label'] ); ?>
						</a>
					<?php endforeach; ?>
				</nav>

				<!-- Hidden field to preserve subtab during form submission -->
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
		 * Sanitize section input.
		 *
		 * @param array $input Raw input from form.
		 * @return array Sanitized input.
		 */
		public function sanitize( $input ) {
			// Use parent's subtab-aware sanitization.
			$sanitized = parent::sanitize( $input );

			// Special handling for default_assistant: convert to integer.
			if ( isset( $sanitized['default_assistant'] ) ) {
				$sanitized['default_assistant'] = absint( $sanitized['default_assistant'] );
			}

			return $sanitized;
		}

		/**
		 * Validate section input.
		 *
		 * @param array $input Raw input.
		 * @return array|WP_Error Validated input or error.
		 */
		public function validate( $input ) {
			$errors = array();

			// Validate default_provider.
			if ( isset( $input['default_provider'] ) ) {
				$valid_providers = array( 'openai', 'anthropic', 'gemini', 'ollama', 'lm_studio' );
				if ( ! in_array( $input['default_provider'], $valid_providers, true ) ) {
					$errors[] = __( 'Invalid AI provider selected.', 'wp-mcp-ai' );
				}
			}

			// Validate default_assistant.
			if ( isset( $input['default_assistant'] ) ) {
				$assistant_id = absint( $input['default_assistant'] );
				if ( $assistant_id > 0 ) {
					$assistant = get_post( $assistant_id );
					if ( ! $assistant || 'mcp_ai_assistant' !== $assistant->post_type ) {
						$errors[] = __( 'Invalid assistant selected.', 'wp-mcp-ai' );
					}
				}
			}

			// Validate max_history_messages.
			if ( isset( $input['max_history_messages'] ) ) {
				$result = WP_MCP_AI_Settings_Validator::validate_number(
					$input['max_history_messages'],
					1,
					100
				);

				if ( is_wp_error( $result ) ) {
					$errors[] = $result->get_error_message();
				}
			}

			// Validate request_timeout.
			if ( isset( $input['request_timeout'] ) ) {
				$result = WP_MCP_AI_Settings_Validator::validate_number(
					$input['request_timeout'],
					10,
					600
				);

				if ( is_wp_error( $result ) ) {
					$errors[] = $result->get_error_message();
				}
			}

			if ( ! empty( $errors ) ) {
				return new WP_Error( 'validation_error', implode( ' ', $errors ) );
			}

			return $input;
		}
	}
}
