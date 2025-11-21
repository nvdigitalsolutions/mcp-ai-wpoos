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
			return __( 'Configure API keys and settings for AI providers (OpenAI, Anthropic, Google Gemini, Ollama, LM Studio).', 'wp-mcp-ai' );
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
					// Latest reasoning models (thinking models).
					'o1-2024-12-17' => 'o1 (Dec 2024)',
					'o1-preview'    => 'o1 Preview',
					'o1-mini'       => 'o1 Mini',
					'o3-mini'       => 'o3 Mini (24% faster, structured outputs)',
					// GPT-4o series (current flagship).
					'gpt-4o'        => 'GPT-4o',
					'gpt-4o-mini'   => 'GPT-4o Mini',
					// Legacy models.
					'gpt-4-turbo'   => 'GPT-4 Turbo',
					'gpt-4'         => 'GPT-4',
					'gpt-3.5-turbo' => 'GPT-3.5 Turbo',
				);
			}

			return array(
				// Provider Priority List.
				'provider_priority_list'      => array(
					'type'        => 'custom',
					'label'       => __( 'Provider Priority Order', 'wp-mcp-ai' ),
					'description' => __( 'Drag and drop to reorder providers. The system will try providers in this order when one fails or is unavailable.', 'wp-mcp-ai' ),
					'default'     => array( 'openai', 'anthropic', 'gemini', 'ollama', 'lm_studio' ),
				),

				// OpenAI Settings.
				'enable_openai'               => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable OpenAI Provider', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable OpenAI as an available provider', 'wp-mcp-ai' ),
					'description'    => __( 'When disabled, OpenAI will not be available for use by assistants or API requests.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'openai_api_key'              => array(
					'type'         => 'password',
					'label'        => __( 'OpenAI API Key', 'wp-mcp-ai' ),
					'description'  => sprintf(
						/* translators: %s: OpenAI API keys URL */
						__( 'Your OpenAI API key. Get one from <a href="%s" target="_blank">OpenAI Platform</a>.', 'wp-mcp-ai' ),
						'https://platform.openai.com/api-keys'
					),
					'placeholder'  => 'sk-...',
					'autocomplete' => 'new-password',
				),
				'default_model'               => array(
					'type'        => 'select',
					'label'       => __( 'Default OpenAI Model', 'wp-mcp-ai' ),
					'description' => __( 'The default model to use for OpenAI requests. This model will be used unless overridden by an assistant or specific API call. Consider cost, speed, and capability trade-offs.', 'wp-mcp-ai' ),
					'options'     => $model_choices,
					'default'     => 'gpt-4o',
				),
				'openai_embedding_model'      => array(
					'type'        => 'select',
					'label'       => __( 'OpenAI Embedding Model', 'wp-mcp-ai' ),
					'description' => __( 'Model to use for generating text embeddings. text-embedding-3-small offers the best balance of performance and cost. text-embedding-3-large provides higher accuracy for complex tasks.', 'wp-mcp-ai' ),
					'options'     => array(
						'text-embedding-3-small' => 'text-embedding-3-small',
						'text-embedding-3-large' => 'text-embedding-3-large',
						'text-embedding-ada-002' => 'text-embedding-ada-002',
					),
					'default'     => 'text-embedding-3-small',
				),
				'openai_organization_id'      => array(
					'type'         => 'text',
					'label'        => __( 'OpenAI Organization ID (Optional)', 'wp-mcp-ai' ),
					'description'  => __( 'Your OpenAI organization ID if you belong to multiple organizations. This is optional for most users. Find it in your OpenAI account settings if needed.', 'wp-mcp-ai' ),
					'placeholder'  => 'org-...',
					'autocomplete' => 'off',
				),

				// Anthropic Settings.
				'enable_anthropic'            => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Anthropic Provider', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable Anthropic (Claude) as an available provider', 'wp-mcp-ai' ),
					'description'    => __( 'When disabled, Anthropic will not be available for use by assistants or API requests.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'anthropic_api_key'           => array(
					'type'         => 'password',
					'label'        => __( 'Anthropic API Key', 'wp-mcp-ai' ),
					'description'  => sprintf(
						/* translators: %s: Anthropic Console URL */
						__( 'Your Anthropic API key. Get one from <a href="%s" target="_blank">Anthropic Console</a>.', 'wp-mcp-ai' ),
						'https://console.anthropic.com/'
					),
					'placeholder'  => 'sk-ant-...',
					'autocomplete' => 'new-password',
				),
				'anthropic_model'             => array(
					'type'        => 'select',
					'label'       => __( 'Default Anthropic Model', 'wp-mcp-ai' ),
					'description' => __( 'The default Claude model to use for Anthropic requests. Claude 3.5 Sonnet offers the best balance of intelligence and speed. Claude 3.5 Haiku is faster and more economical for simpler tasks.', 'wp-mcp-ai' ),
					'options'     => array(
						'claude-3-5-sonnet-20241022' => 'Claude 3.5 Sonnet (Latest)',
						'claude-3-5-haiku-20241022'  => 'Claude 3.5 Haiku',
						'claude-3-opus-20240229'     => 'Claude 3 Opus',
						'claude-3-sonnet-20240229'   => 'Claude 3 Sonnet',
						'claude-3-haiku-20240307'    => 'Claude 3 Haiku',
					),
					'default'     => 'claude-3-5-sonnet-20241022',
				),

				// Google Gemini Settings.
				'enable_gemini'               => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Gemini Provider', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable Google Gemini as an available provider', 'wp-mcp-ai' ),
					'description'    => __( 'When disabled, Gemini will not be available for use by assistants or API requests.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'gemini_api_key'              => array(
					'type'         => 'password',
					'label'        => __( 'Gemini API Key', 'wp-mcp-ai' ),
					'description'  => sprintf(
						/* translators: %s: Google AI Studio URL */
						__( 'Your Google Gemini API key. Get one from <a href="%s" target="_blank">Google AI Studio</a>.', 'wp-mcp-ai' ),
						'https://aistudio.google.com/app/apikey'
					),
					'placeholder'  => 'AIza...',
					'autocomplete' => 'new-password',
				),
				'default_gemini_model'        => array(
					'type'        => 'select',
					'label'       => __( 'Default Gemini Model', 'wp-mcp-ai' ),
					'description' => __( 'The default model to use for Gemini text/chat requests. Gemini 2.5 Flash is the latest stable model with multimodal support (text, image, video). Gemini 2.0 Flash is the previous stable generation. Gemini 1.5 Pro provides proven performance, while 1.5 Flash is faster and more economical.', 'wp-mcp-ai' ),
					'options'     => array(
						'gemini-2.5-flash'     => 'Gemini 2.5 Flash (Latest - Stable)',
						'gemini-2.0-flash'     => 'Gemini 2.0 Flash',
						'gemini-exp-1206'      => 'Gemini Exp 1206 (Experimental)',
						'gemini-1.5-pro'       => 'Gemini 1.5 Pro',
						'gemini-1.5-flash'     => 'Gemini 1.5 Flash',
						'gemini-pro'           => 'Gemini Pro',
					),
					'default'     => 'gemini-2.5-flash',
				),
				'default_gemini_video_model'  => array(
					'type'        => 'select',
					'label'       => __( 'Default Gemini Video Model', 'wp-mcp-ai' ),
					'description' => __( 'The default model to use for video generation with Veo. Veo 2.0 is the stable default (720p, 4-8 seconds). Veo 3.1 is optional for higher resolution (1080p support, requires 8 seconds for 1080p). Both models support automatic fallback on quota/availability issues.', 'wp-mcp-ai' ),
					'options'     => array(
						'veo-2.0' => 'Veo 2.0 (Stable - 720p, 4-8s)',
						'veo-3.1' => 'Veo 3.1 (1080p support, 4-8s)',
					),
					'default'     => 'veo-2.0',
				),

				// Ollama Settings.
				'enable_ollama'               => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Ollama Provider', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable Ollama (Local AI) as an available provider', 'wp-mcp-ai' ),
					'description'    => __( 'When disabled, Ollama will not be available for use by assistants or API requests.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'ollama_endpoint_url'         => array(
					'type'        => 'url',
					'label'       => __( 'Ollama Endpoint URL', 'wp-mcp-ai' ),
					'description' => __( 'URL where your Ollama server is running. Examples: "http://localhost:11434" (same machine), "http://192.168.2.222:11434" (private network). For remote WordPress (e.g., Cloudways) connecting to private LAN Ollama: ensure network routing/VPN is configured, then enter the private IP. The plugin handles SSL verification and connection timeouts automatically.', 'wp-mcp-ai' ),
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
				'ollama_model'                => array(
					'type'        => 'text',
					'label'       => __( 'Ollama Model', 'wp-mcp-ai' ),
					'description' => __( 'The model name to use with Ollama. Must match exactly a model you have pulled (e.g., llama3, mistral, codellama). Use \"ollama list\" in terminal to see available models.', 'wp-mcp-ai' ),
					'placeholder' => 'llama3',
				),
				'ollama_network_interface'    => array(
					'type'        => 'text',
					'label'       => __( 'Ollama Network Interface (Optional)', 'wp-mcp-ai' ),
					'description' => __( 'Advanced: Bind HTTP requests to a specific LOCAL network interface on THIS WordPress server. Examples: "eth0", "wlan0", or a LOCAL IP like "192.168.1.50" assigned to THIS server. Leave EMPTY for most setups (default routing works). NOTE: If your Ollama is on a different machine (e.g., 192.168.2.100), put that IP in the Endpoint URL field above, NOT here. This field is for source binding only.', 'wp-mcp-ai' ),
					'placeholder' => '',
				),

				// LM Studio Settings.
				'enable_lm_studio'            => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable LM Studio Provider', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Enable LM Studio (Local AI) as an available provider', 'wp-mcp-ai' ),
					'description'    => __( 'When disabled, LM Studio will not be available for use by assistants or API requests.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'lm_studio_endpoint_url'      => array(
					'type'        => 'url',
					'label'       => __( 'LM Studio Endpoint URL', 'wp-mcp-ai' ),
					'description' => __( 'URL where your LM Studio server is running. Examples: "http://localhost:1234" (same machine), "http://192.168.2.222:1234" (private network). For remote WordPress (e.g., Cloudways) connecting to private LAN LM Studio: ensure network routing/VPN is configured, then enter the private IP. The plugin handles SSL verification and connection timeouts automatically.', 'wp-mcp-ai' ),
					'placeholder' => 'http://localhost:1234',
					/**
					 * Filter the default LM Studio endpoint URL.
					 *
					 * @since 1.0.0
					 *
					 * @param string $url Default URL. Default 'http://localhost:1234'.
					 */
					'default'     => apply_filters( 'wp_mcp_ai_default_lm_studio_endpoint_url', 'http://localhost:1234' ),
				),
				'lm_studio_model'             => array(
					'type'        => 'text',
					'label'       => __( 'LM Studio Model', 'wp-mcp-ai' ),
					'description' => __( 'The model identifier for your loaded LM Studio model. This is typically shown in the LM Studio interface. Some installations accept \"local-model\" as a generic identifier.', 'wp-mcp-ai' ),
					'placeholder' => 'local-model',
				),
				'lm_studio_network_interface' => array(
					'type'        => 'text',
					'label'       => __( 'LM Studio Network Interface (Optional)', 'wp-mcp-ai' ),
					'description' => __( 'Advanced: Bind HTTP requests to a specific LOCAL network interface on THIS WordPress server. Examples: "eth0", "wlan0", or a LOCAL IP like "192.168.1.50" assigned to THIS server. Leave EMPTY for most setups (default routing works). NOTE: If your LM Studio is on a different machine (e.g., 192.168.2.222), put that IP in the Endpoint URL field above, NOT here. This field is for source binding only.', 'wp-mcp-ai' ),
					'placeholder' => '',
				),
			);
		}

		/**
		 * Get provider sub-tab groups configuration.
		 *
		 * @return array
		 */
		protected function get_subtab_groups() {
			return array(
				'priority'  => array(
					'id'     => 'priority',
					'label'  => __( 'Priority Order', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-sort',
					'fields' => array( 'provider_priority_list' ),
				),
				'openai'    => array(
					'id'     => 'openai',
					'label'  => __( 'OpenAI', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-admin-generic',
					'fields' => array( 'enable_openai', 'openai_api_key', 'default_model', 'openai_embedding_model', 'openai_organization_id' ),
				),
				'anthropic' => array(
					'id'     => 'anthropic',
					'label'  => __( 'Anthropic', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-admin-generic',
					'fields' => array( 'enable_anthropic', 'anthropic_api_key', 'anthropic_model' ),
				),
				'gemini'    => array(
					'id'     => 'gemini',
					'label'  => __( 'Google Gemini', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-admin-generic',
					'fields' => array( 'enable_gemini', 'gemini_api_key', 'default_gemini_model', 'default_gemini_video_model' ),
				),
				'ollama'    => array(
					'id'     => 'ollama',
					'label'  => __( 'Ollama (Local)', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-desktop',
					'fields' => array( 'enable_ollama', 'ollama_endpoint_url', 'ollama_model', 'ollama_network_interface' ),
				),
				'lm_studio' => array(
					'id'     => 'lm_studio',
					'label'  => __( 'LM Studio (Local)', 'wp-mcp-ai' ),
					'icon'   => 'dashicons-desktop',
					'fields' => array( 'enable_lm_studio', 'lm_studio_endpoint_url', 'lm_studio_model', 'lm_studio_network_interface' ),
				),
			);
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
			// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Read-only parameter check.
			if ( isset( $_POST['subtab'] ) ) {
				$subtab = sanitize_key( $_POST['subtab'] );
			} elseif ( isset( $_GET['subtab'] ) ) {
				$subtab = sanitize_key( $_GET['subtab'] );
			}
			// phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended

			// Default to 'priority' if not set or invalid.
			if ( empty( $subtab ) || ! isset( $subtab_groups[ $subtab ] ) ) {
				$subtab = 'priority';
			}

			return $subtab;
		}

		/**
		 * Render the section wrapper with sub-tabs.
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
<nav class="wp-mcp-ai-subtab-nav" aria-label="<?php esc_attr_e( 'Provider sub-tabs', 'wp-mcp-ai' ); ?>">
			<?php foreach ( $subtab_groups as $group ) : ?>
				<?php
				$subtab_url = add_query_arg(
					array(
						'page'   => 'wp-mcp-ai-dashboard',
						'tab'    => 'providers',
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
			if ( 'priority' === $active_subtab && isset( $fields['provider_priority_list'] ) ) {
				$this->render_provider_priority_list( $fields['provider_priority_list'] );
			} else {
				foreach ( $active_group['fields'] as $key ) {
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
				'openai'    => __( 'OpenAI', 'wp-mcp-ai' ),
				'anthropic' => __( 'Anthropic (Claude)', 'wp-mcp-ai' ),
				'gemini'    => __( 'Gemini', 'wp-mcp-ai' ),
				'ollama'    => __( 'Ollama (Local AI)', 'wp-mcp-ai' ),
				'lm_studio' => __( 'LM Studio (Local AI)', 'wp-mcp-ai' ),
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
		 * Sanitize input for this section.
		 *
		 * @param array $input Raw input from form.
		 * @return array Sanitized input.
		 */
		public function sanitize( $input ) {
			$sanitized = array();

			// Handle provider_priority_list separately.
			if ( isset( $input['provider_priority_list'] ) && is_array( $input['provider_priority_list'] ) ) {
				$sanitized['provider_priority_list'] = $this->sanitize_provider_priority_list( $input['provider_priority_list'] );
			}

			// Call parent sanitization for other fields.
			$parent_sanitized = parent::sanitize( $input );
			$sanitized        = array_merge( $parent_sanitized, $sanitized );

			return $sanitized;
		}

		/**
		 * Sanitize provider priority list.
		 *
		 * @param array $priority_list The provider priority list to sanitize.
		 * @return array Sanitized provider priority list.
		 */
		private function sanitize_provider_priority_list( $priority_list ) {
			$valid_providers = array( 'openai', 'anthropic', 'gemini', 'ollama', 'lm_studio' );
			$sanitized       = array();

			if ( ! is_array( $priority_list ) ) {
				return $valid_providers;
			}

			foreach ( $priority_list as $provider ) {
				$provider = sanitize_text_field( $provider );
				if ( in_array( $provider, $valid_providers, true ) && ! in_array( $provider, $sanitized, true ) ) {
					$sanitized[] = $provider;
				}
			}

			// Ensure all providers are included (add any missing ones at the end).
			foreach ( $valid_providers as $provider ) {
				if ( ! in_array( $provider, $sanitized, true ) ) {
					$sanitized[] = $provider;
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
