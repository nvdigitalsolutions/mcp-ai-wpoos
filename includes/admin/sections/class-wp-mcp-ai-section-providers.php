<?php
/**
 * AI Providers Settings Section
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Providers' ) ) {
	/**
	 * AI Providers settings section.
	 */
	class WP_MCP_AI_Section_Providers extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'providers';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'AI Provider Configuration', 'wp-mcp-ai' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'providers';
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
			return __( 'Configure API keys and settings for AI providers (OpenAI, Google Gemini, Ollama, LM Studio).', 'wp-mcp-ai' );
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			// Get dynamic model choices (from CCT if available, or fallback).
			$model_choices = array();
			if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
				$model_choices = WP_MCP_AI_Admin_Settings::get_openai_default_model_choices_static();
			}

			// Fallback to minimal hardcoded list if static method unavailable.
			if ( empty( $model_choices ) ) {
				$model_choices = array(
					'gpt-4o'         => 'GPT-4o',
					'gpt-4o-mini'    => 'GPT-4o Mini',
					'gpt-4-turbo'    => 'GPT-4 Turbo',
					'gpt-4'          => 'GPT-4',
					'gpt-3.5-turbo'  => 'GPT-3.5 Turbo',
					'o1-preview'     => 'o1 Preview',
					'o1-mini'        => 'o1 Mini',
				);
			}

			return array(
				// Provider Priority List.
				'provider_priority_list' => array(
					'type'        => 'custom',
					'label'       => __( 'Provider Priority Order', 'wp-mcp-ai' ),
					'description' => __( 'Drag and drop to reorder providers. The system will try providers in this order when one fails or is unavailable.', 'wp-mcp-ai' ),
					'default'     => array( 'openai', 'gemini', 'ollama', 'lm_studio' ),
				),
				
				// OpenAI Settings.
				'openai_api_key'        => array(
					'type'        => 'password',
					'label'       => __( 'OpenAI API Key', 'wp-mcp-ai' ),
					'description' => sprintf(
						/* translators: %s: OpenAI API keys URL */
						__( 'Your OpenAI API key. Get one from <a href="%s" target="_blank">OpenAI Platform</a>.', 'wp-mcp-ai' ),
						'https://platform.openai.com/api-keys'
					),
					'placeholder' => 'sk-...',
				),
				'default_model'         => array(
					'type'        => 'select',
					'label'       => __( 'Default OpenAI Model', 'wp-mcp-ai' ),
					'description' => __( 'The default model to use for OpenAI requests.', 'wp-mcp-ai' ),
					'options'     => $model_choices,
					'default'     => 'gpt-4o',
				),
				'openai_embedding_model' => array(
					'type'        => 'select',
					'label'       => __( 'OpenAI Embedding Model', 'wp-mcp-ai' ),
					'description' => __( 'Model to use for generating embeddings.', 'wp-mcp-ai' ),
					'options'     => array(
						'text-embedding-3-small' => 'text-embedding-3-small',
						'text-embedding-3-large' => 'text-embedding-3-large',
						'text-embedding-ada-002' => 'text-embedding-ada-002',
					),
					'default'     => 'text-embedding-3-small',
				),
				'openai_organization_id' => array(
					'type'        => 'text',
					'label'       => __( 'OpenAI Organization ID (Optional)', 'wp-mcp-ai' ),
					'description' => __( 'Your OpenAI organization ID if applicable.', 'wp-mcp-ai' ),
					'placeholder' => 'org-...',
				),

				// Google Gemini Settings.
				'gemini_api_key'        => array(
					'type'        => 'password',
					'label'       => __( 'Gemini API Key', 'wp-mcp-ai' ),
					'description' => sprintf(
						/* translators: %s: Google AI Studio URL */
						__( 'Your Google Gemini API key. Get one from <a href="%s" target="_blank">Google AI Studio</a>.', 'wp-mcp-ai' ),
						'https://aistudio.google.com/app/apikey'
					),
					'placeholder' => 'AIza...',
				),
				'default_gemini_model'  => array(
					'type'        => 'select',
					'label'       => __( 'Default Gemini Model', 'wp-mcp-ai' ),
					'description' => __( 'The default model to use for Gemini requests.', 'wp-mcp-ai' ),
					'options'     => array(
						'gemini-1.5-pro'   => 'Gemini 1.5 Pro',
						'gemini-1.5-flash' => 'Gemini 1.5 Flash',
						'gemini-pro'       => 'Gemini Pro',
					),
					'default'     => 'gemini-1.5-pro',
				),

				// Ollama Settings.
				'ollama_endpoint_url'   => array(
					'type'        => 'url',
					'label'       => __( 'Ollama Endpoint URL', 'wp-mcp-ai' ),
					'description' => __( 'URL for your local Ollama installation (e.g., http://localhost:11434).', 'wp-mcp-ai' ),
					'placeholder' => 'http://localhost:11434',
					/**
					 * Filter the default Ollama endpoint URL.
					 *
					 * @since 1.0.0
					 *
					 * @param string $url Default URL. Default 'http://localhost:11434'.
					 */
					'default'     => apply_filters( 'wp_mcp_ai_default_ollama_endpoint_url', 'http://localhost:11434' ),
				),
				'ollama_model'          => array(
					'type'        => 'text',
					'label'       => __( 'Ollama Model', 'wp-mcp-ai' ),
					'description' => __( 'The model name to use with Ollama (e.g., llama3, mistral).', 'wp-mcp-ai' ),
					'placeholder' => 'llama3',
				),

				// LM Studio Settings.
				'lm_studio_endpoint_url' => array(
					'type'        => 'url',
					'label'       => __( 'LM Studio Endpoint URL', 'wp-mcp-ai' ),
					'description' => __( 'URL for your local LM Studio installation (e.g., http://localhost:1234/v1).', 'wp-mcp-ai' ),
					'placeholder' => 'http://localhost:1234/v1',
					/**
					 * Filter the default LM Studio endpoint URL.
					 *
					 * @since 1.0.0
					 *
					 * @param string $url Default URL. Default 'http://localhost:1234/v1'.
					 */
					'default'     => apply_filters( 'wp_mcp_ai_default_lm_studio_endpoint_url', 'http://localhost:1234/v1' ),
				),
				'lm_studio_model'       => array(
					'type'        => 'text',
					'label'       => __( 'LM Studio Model', 'wp-mcp-ai' ),
					'description' => __( 'The model name to use with LM Studio.', 'wp-mcp-ai' ),
					'placeholder' => 'local-model',
				),
			);
		}

		/**
		 * Render section fields.
		 */
		public function render() {
			$fields = $this->get_fields();

			// First, render the provider priority list.
			if ( isset( $fields['provider_priority_list'] ) ) {
				$this->render_provider_priority_list( $fields['provider_priority_list'] );
			}

			// Group fields by provider.
			$providers = array(
				'OpenAI'      => array( 'openai_api_key', 'default_model', 'openai_embedding_model', 'openai_organization_id' ),
				'Google Gemini' => array( 'gemini_api_key', 'default_gemini_model' ),
				'Ollama (Local)' => array( 'ollama_endpoint_url', 'ollama_model' ),
				'LM Studio (Local)' => array( 'lm_studio_endpoint_url', 'lm_studio_model' ),
			);

			foreach ( $providers as $provider_name => $field_keys ) {
				echo '<tr><th colspan="2"><h3 style="margin: 20px 0 10px 0;">' . esc_html( $provider_name ) . '</h3></th></tr>';

				foreach ( $field_keys as $key ) {
					if ( isset( $fields[ $key ] ) ) {
						$this->render_field( $key, $fields[ $key ] );
					}
				}
			}
		}

		/**
		 * Render the provider priority list field.
		 *
		 * @param array $field Field configuration.
		 */
		private function render_provider_priority_list( $field ) {
			$label       = isset( $field['label'] ) ? $field['label'] : '';
			$description = isset( $field['description'] ) ? $field['description'] : '';
			$value       = WP_MCP_AI_Settings_Registry::get_setting( 'provider_priority_list', isset( $field['default'] ) ? $field['default'] : array() );

			$provider_labels = array(
				'openai'     => __( 'OpenAI', 'wp-mcp-ai' ),
				'gemini'     => __( 'Gemini', 'wp-mcp-ai' ),
				'ollama'     => __( 'Ollama (Local AI)', 'wp-mcp-ai' ),
				'lm_studio'  => __( 'LM Studio (Local AI)', 'wp-mcp-ai' ),
			);
			?>
			<tr>
				<th scope="row">
					<label><?php echo esc_html( $label ); ?></label>
				</th>
				<td>
					<div id="wp-mcp-ai-provider-priority-list" class="wp-mcp-ai-sortable-list">
						<ul id="wp-mcp-ai-provider-sortable">
							<?php foreach ( $value as $provider ) : ?>
								<?php if ( isset( $provider_labels[ $provider ] ) ) : ?>
									<li class="wp-mcp-ai-provider-item" data-provider="<?php echo esc_attr( $provider ); ?>">
										<span class="dashicons dashicons-menu"></span>
										<span class="provider-label"><?php echo esc_html( $provider_labels[ $provider ] ); ?></span>
										<input type="hidden" name="wp_mcp_ai_settings[provider_priority_list][]" value="<?php echo esc_attr( $provider ); ?>">
									</li>
								<?php endif; ?>
							<?php endforeach; ?>
						</ul>
					</div>
					<?php if ( $description ) : ?>
						<p class="description"><?php echo wp_kses_post( $description ); ?></p>
					<?php endif; ?>
					<style>
						#wp-mcp-ai-provider-sortable {
							list-style: none;
							margin: 0;
							padding: 0;
						}
						.wp-mcp-ai-provider-item {
							background: #fff;
							border: 1px solid #ddd;
							padding: 10px 15px;
							margin: 5px 0;
							cursor: move;
							display: flex;
							align-items: center;
							gap: 10px;
							border-radius: 3px;
							transition: box-shadow 0.2s ease;
							max-width: 400px;
						}
						.wp-mcp-ai-provider-item:hover {
							box-shadow: 0 2px 4px rgba(0,0,0,0.1);
						}
						.wp-mcp-ai-provider-item .dashicons {
							color: #999;
							flex-shrink: 0;
						}
						.wp-mcp-ai-provider-item.ui-sortable-helper {
							background: #f0f0f0;
							border-color: #0073aa;
							box-shadow: 0 4px 8px rgba(0,0,0,0.2);
						}
						.wp-mcp-ai-provider-item.ui-sortable-placeholder {
							background: #f9f9f9;
							border: 2px dashed #ddd;
							visibility: visible !important;
							height: 42px;
						}
						.wp-mcp-ai-provider-item .provider-label {
							flex: 1;
							font-weight: 500;
						}
					</style>
				</td>
			</tr>
			<?php
		}

		/**
		 * Validate section input.
		 *
		 * @param array $input Raw input.
		 * @return array|WP_Error Validated input or error.
		 */
		public function validate( $input ) {
			$errors = array();

			// Validate URLs.
			$url_fields = array( 'ollama_endpoint_url', 'lm_studio_endpoint_url' );
			foreach ( $url_fields as $field ) {
				if ( isset( $input[ $field ] ) && ! empty( $input[ $field ] ) ) {
					$result = WP_MCP_AI_Settings_Validator::validate_url( $input[ $field ] );
					if ( is_wp_error( $result ) ) {
						$errors[] = sprintf(
							/* translators: %s: field name */
							__( '%s: ', 'wp-mcp-ai' ),
							$field
						) . $result->get_error_message();
					}
				}
			}

			if ( ! empty( $errors ) ) {
				return new WP_Error( 'validation_error', implode( ' ', $errors ) );
			}

			return $input;
		}
	}
}
