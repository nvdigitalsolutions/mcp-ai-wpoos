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
			$fields = $this->get_fields();

			foreach ( $fields as $key => $field ) {
				$this->render_field( $key, $field );
			}
		}

		/**
		 * Sanitize section input.
		 *
		 * @param array $input Raw input from form.
		 * @return array Sanitized input.
		 */
		public function sanitize( $input ) {
			// Call parent sanitization first.
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
