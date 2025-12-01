<?php
/**
 * Custom AI Filters Settings Section
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Custom_Filters' ) ) {
	/**
	 * Custom AI filters settings section.
	 *
	 * Allows administrators to configure WordPress filter values that customize
	 * AI behavior without writing code.
	 */
	class WP_MCP_AI_Section_Custom_Filters extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'custom_filters';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Custom AI Settings (Filters)', 'wp-mcp-ai' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			// Hide this section as it's now integrated into General Settings as a sub-tab.
			return '__hidden__';
		}

		/**
		 * Get section priority.
		 *
		 * Lower numbers render first. Using 90 to position second-to-last
		 * (assuming last section uses 100).
		 *
		 * @return int
		 */
		public function get_priority() {
			return 90;
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Configure advanced AI behavior settings through a user-friendly interface. These settings override WordPress filter defaults and allow you to customize AI operations without writing code. <strong>Leave fields empty to use system defaults.</strong>', 'wp-mcp-ai' );
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			return array(
				// Model Selection Filters.
				'filter_default_light_model'            => array(
					'type'        => 'text',
					'label'       => __( 'Default Light Model', 'wp-mcp-ai' ),
					'description' => __( 'Default AI model for simple tasks. Overrides the wp_mcp_ai_default_light_model filter. Default: gpt-4o-mini', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => 'gpt-4o-mini',
				),
				'filter_default_advanced_model'         => array(
					'type'        => 'text',
					'label'       => __( 'Default Advanced Model', 'wp-mcp-ai' ),
					'description' => __( 'Default AI model for complex tasks. Overrides the wp_mcp_ai_default_advanced_model filter. Default: gpt-4o', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => 'gpt-4o',
				),

				// Resource Management Filters.
				'filter_max_agentic_iterations'         => array(
					'type'        => 'number',
					'label'       => __( 'Max Agentic Iterations', 'wp-mcp-ai' ),
					'description' => __( 'Maximum number of tool execution loops per request. Prevents infinite loops. Range: 1-50. Default: 5', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => '5',
				),
				'filter_resource_max_tokens'            => array(
					'type'        => 'number',
					'label'       => __( 'Resource Max Tokens', 'wp-mcp-ai' ),
					'description' => __( 'Maximum tokens for AI responses based on workload tier. Overrides the wp_mcp_ai_resource_max_tokens filter. Leave empty for auto-detection.', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => 'Auto',
				),
				'filter_resource_request_timeout'       => array(
					'type'        => 'number',
					'label'       => __( 'Resource Request Timeout (seconds)', 'wp-mcp-ai' ),
					'description' => __( 'Request timeout based on workload tier. Overrides the wp_mcp_ai_resource_request_timeout filter. Leave empty for auto-detection.', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => 'Auto',
				),

				// Retry and Error Handling.
				'filter_max_retries'                    => array(
					'type'        => 'number',
					'label'       => __( 'Max Retries', 'wp-mcp-ai' ),
					'description' => __( 'Maximum retry attempts for failed API requests. Overrides the wp_mcp_ai_max_retries filter. Default: 3', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => '3',
				),
				'filter_max_retry_delay'                => array(
					'type'        => 'number',
					'label'       => __( 'Max Retry Delay (seconds)', 'wp-mcp-ai' ),
					'description' => __( 'Maximum delay between retry attempts. Overrides the wp_mcp_ai_max_retry_delay filter. Default: 60', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => '60',
				),

				// File and Attachment Limits.
				'filter_max_attachment_bytes'           => array(
					'type'        => 'number',
					'label'       => __( 'Max Attachment Size (bytes)', 'wp-mcp-ai' ),
					'description' => __( 'Maximum size for file attachments in chat. Overrides the wp_mcp_ai_max_attachment_bytes filter. Default: 10485760 (10MB)', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => '10485760',
				),

				// Endpoint URLs.
				'filter_default_ollama_endpoint_url'    => array(
					'type'        => 'url',
					'label'       => __( 'Default Ollama Endpoint URL', 'wp-mcp-ai' ),
					'description' => __( 'Default endpoint URL for Ollama local AI. Overrides the wp_mcp_ai_default_ollama_endpoint_url filter. Default: http://localhost:11434', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => 'http://localhost:11434',
				),
				'filter_default_lm_studio_endpoint_url' => array(
					'type'        => 'url',
					'label'       => __( 'Default LM Studio Endpoint URL', 'wp-mcp-ai' ),
					'description' => __( 'Default endpoint URL for LM Studio local AI. Overrides the wp_mcp_ai_default_lm_studio_endpoint_url filter. Default: http://localhost:1234', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => 'http://localhost:1234',
				),
				'filter_lm_studio_fallback_model'       => array(
					'type'        => 'text',
					'label'       => __( 'LM Studio Fallback Model', 'wp-mcp-ai' ),
					'description' => __( 'Model to use when LM Studio provider is selected but no model is specified. Overrides the wp_mcp_ai_lm_studio_fallback_model filter. Defaults to the "Default Model" setting for OpenAI compatibility. Leave empty to use default behavior.', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => __( 'Uses Default Model setting', 'wp-mcp-ai' ),
				),
			);
		}

		/**
		 * Render section fields.
		 */
		public function render() {
			$fields = $this->get_fields();

			// Organize fields into logical groups for better UX.
			$groups = array(
				'model_selection'     => array(
					'title'  => __( 'AI Model Selection', 'wp-mcp-ai' ),
					'fields' => array( 'filter_default_light_model', 'filter_default_advanced_model' ),
				),
				'resource_management' => array(
					'title'  => __( 'Resource Management', 'wp-mcp-ai' ),
					'fields' => array( 'filter_max_agentic_iterations', 'filter_resource_max_tokens', 'filter_resource_request_timeout' ),
				),
				'retry_handling'      => array(
					'title'  => __( 'Retry & Error Handling', 'wp-mcp-ai' ),
					'fields' => array( 'filter_max_retries', 'filter_max_retry_delay' ),
				),
				'file_limits'         => array(
					'title'  => __( 'File & Attachment Limits', 'wp-mcp-ai' ),
					'fields' => array( 'filter_max_attachment_bytes' ),
				),
				'endpoint_urls'       => array(
					'title'  => __( 'Local AI Endpoint URLs', 'wp-mcp-ai' ),
					'fields' => array( 'filter_default_ollama_endpoint_url', 'filter_default_lm_studio_endpoint_url', 'filter_lm_studio_fallback_model' ),
				),
			);

			foreach ( $groups as $group_id => $group ) {
				?>
				<tr class="filter-group-header">
					<td colspan="2">
						<h3 class="filter-group-title">
							<?php echo esc_html( $group['title'] ); ?>
						</h3>
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
		 * Sanitize section input.
		 *
		 * Override parent to handle empty number fields properly.
		 *
		 * @param array $input Raw input from form.
		 * @return array Sanitized input.
		 */
		public function sanitize( $input ) {
			$sanitized = array();
			$fields    = $this->get_fields();

			foreach ( $fields as $key => $field ) {
				if ( ! isset( $input[ $key ] ) ) {
					continue;
				}

				$type  = isset( $field['type'] ) ? $field['type'] : 'text';
				$value = $input[ $key ];

				switch ( $type ) {
					case 'text':
					case 'password':
						$sanitized[ $key ] = sanitize_text_field( $value );
						break;

					case 'url':
						// Keep empty strings, only sanitize non-empty values.
						$sanitized[ $key ] = '' === $value ? '' : esc_url_raw( $value );
						break;

					case 'number':
						// Keep empty strings for "use default" functionality.
						$sanitized[ $key ] = '' === $value ? '' : absint( $value );
						break;

					default:
						$sanitized[ $key ] = sanitize_text_field( $value );
						break;
				}
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

			// Validate filter_max_agentic_iterations.
			if ( isset( $input['filter_max_agentic_iterations'] ) && '' !== $input['filter_max_agentic_iterations'] ) {
				$result = WP_MCP_AI_Settings_Validator::validate_number(
					$input['filter_max_agentic_iterations'],
					1,
					50
				);

				if ( is_wp_error( $result ) ) {
					$errors[] = __( 'Max Agentic Iterations: ', 'wp-mcp-ai' ) . $result->get_error_message();
				}
			}

			// Validate filter_resource_max_tokens.
			if ( isset( $input['filter_resource_max_tokens'] ) && '' !== $input['filter_resource_max_tokens'] ) {
				$result = WP_MCP_AI_Settings_Validator::validate_number(
					$input['filter_resource_max_tokens'],
					100,
					128000
				);

				if ( is_wp_error( $result ) ) {
					$errors[] = __( 'Resource Max Tokens: ', 'wp-mcp-ai' ) . $result->get_error_message();
				}
			}

			// Validate filter_resource_request_timeout.
			if ( isset( $input['filter_resource_request_timeout'] ) && '' !== $input['filter_resource_request_timeout'] ) {
				$result = WP_MCP_AI_Settings_Validator::validate_number(
					$input['filter_resource_request_timeout'],
					10,
					600
				);

				if ( is_wp_error( $result ) ) {
					$errors[] = __( 'Resource Request Timeout: ', 'wp-mcp-ai' ) . $result->get_error_message();
				}
			}

			// Validate filter_max_retries.
			if ( isset( $input['filter_max_retries'] ) && '' !== $input['filter_max_retries'] ) {
				$result = WP_MCP_AI_Settings_Validator::validate_number(
					$input['filter_max_retries'],
					0,
					10
				);

				if ( is_wp_error( $result ) ) {
					$errors[] = __( 'Max Retries: ', 'wp-mcp-ai' ) . $result->get_error_message();
				}
			}

			// Validate filter_max_retry_delay.
			if ( isset( $input['filter_max_retry_delay'] ) && '' !== $input['filter_max_retry_delay'] ) {
				$result = WP_MCP_AI_Settings_Validator::validate_number(
					$input['filter_max_retry_delay'],
					1,
					300
				);

				if ( is_wp_error( $result ) ) {
					$errors[] = __( 'Max Retry Delay: ', 'wp-mcp-ai' ) . $result->get_error_message();
				}
			}

			// Validate filter_max_attachment_bytes.
			if ( isset( $input['filter_max_attachment_bytes'] ) && '' !== $input['filter_max_attachment_bytes'] ) {
				$result = WP_MCP_AI_Settings_Validator::validate_number(
					$input['filter_max_attachment_bytes'],
					1024,
					104857600 // 100MB max.
				);

				if ( is_wp_error( $result ) ) {
					$errors[] = __( 'Max Attachment Size: ', 'wp-mcp-ai' ) . $result->get_error_message();
				}
			}

			// Validate URLs.
			if ( isset( $input['filter_default_ollama_endpoint_url'] ) && '' !== $input['filter_default_ollama_endpoint_url'] ) {
				$url = filter_var( $input['filter_default_ollama_endpoint_url'], FILTER_VALIDATE_URL );
				if ( false === $url ) {
					$errors[] = __( 'Default Ollama Endpoint URL must be a valid URL.', 'wp-mcp-ai' );
				}
			}

			if ( isset( $input['filter_default_lm_studio_endpoint_url'] ) && '' !== $input['filter_default_lm_studio_endpoint_url'] ) {
				$url = filter_var( $input['filter_default_lm_studio_endpoint_url'], FILTER_VALIDATE_URL );
				if ( false === $url ) {
					$errors[] = __( 'Default LM Studio Endpoint URL must be a valid URL.', 'wp-mcp-ai' );
				}
			}

			if ( ! empty( $errors ) ) {
				return new WP_Error( 'validation_error', implode( ' ', $errors ) );
			}

			return $input;
		}
	}
}
