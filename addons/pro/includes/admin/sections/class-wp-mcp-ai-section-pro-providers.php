<?php
/**
 * Pro Providers Settings Section
 *
 * Settings for pro-only providers (Embedded LLM).
 * These providers are only available in the pro addon.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Pro_Providers' ) ) {
	/**
	 * Pro providers settings section.
	 */
	class WP_MCP_AI_Section_Pro_Providers extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'pro_providers';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'Pro Providers', 'mcp-ai-wpoos' );
		}

		/**
		 * Get tab ID.
		 *
		 * This section is integrated within the Providers tab.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'providers';
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Configure pro-only AI providers (Embedded LLM). These providers are available in the Pro addon.', 'mcp-ai-wpoos' );
		}

		/**
		 * Get section priority.
		 *
		 * @return int
		 */
		public function get_priority() {
			return 15; // After base providers (10)
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			// Get available embedded models (WebLLM client-side models).
			// All available models are listed. Models marked with * support function calling.
			$embedded_models = array(
				'Hermes-2-Pro-Llama-3-8B-q4f16_1-MLC'      => __( 'Hermes 2 Pro Llama 3 8B (~4.5GB) - Recommended*', 'mcp-ai-wpoos' ),
				'Hermes-3-Llama-3.1-8B-q4f16_1-MLC'        => __( 'Hermes 3 Llama 3.1 8B (~4.9GB)*', 'mcp-ai-wpoos' ),
				'DeepSeek-R1-Distill-Llama-8B-q4f16_1-MLC' => __( 'DeepSeek R1 Distill Llama 8B (~5GB)', 'mcp-ai-wpoos' ),
				'DeepSeek-R1-Distill-Qwen-7B-q4f16_1-MLC'  => __( 'DeepSeek R1 Distill Qwen 7B (~5.1GB)', 'mcp-ai-wpoos' ),
				'Qwen2.5-7B-Instruct-q4f16_1-MLC'          => __( 'Qwen2.5 7B Instruct (~4.5GB)*', 'mcp-ai-wpoos' ),
				'Phi-3.5-mini-instruct-q4f16_1-MLC'        => __( 'Phi-3.5 Mini Instruct (~2.5GB)*', 'mcp-ai-wpoos' ),
				'gemma-2-2b-it-q4f16_1-MLC'                => __( 'Gemma 2 2B Instruct (~1.9GB)', 'mcp-ai-wpoos' ),
				'Llama-3.2-3B-Instruct-q4f16_1-MLC'        => __( 'Llama 3.2 3B Instruct (~2GB)', 'mcp-ai-wpoos' ),
				'SmolLM2-1.7B-Instruct-q4f16_1-MLC'        => __( 'SmolLM2 1.7B Instruct (~1.8GB)', 'mcp-ai-wpoos' ),
				'Qwen2.5-1.5B-Instruct-q4f16_1-MLC'        => __( 'Qwen2.5 1.5B Instruct (~1GB)*', 'mcp-ai-wpoos' ),
				'Llama-3.2-1B-Instruct-q4f16_1-MLC'        => __( 'Llama 3.2 1B Instruct (~800MB)', 'mcp-ai-wpoos' ),
				'Qwen2.5-0.5B-Instruct-q4f16_1-MLC'        => __( 'Qwen2.5 0.5B Instruct (~400MB)', 'mcp-ai-wpoos' ),
			);

			// Build server-side model select options from the pre-configured GGUF model list.
			$server_model_options = array( '' => __( '— Auto (first downloaded model) —', 'mcp-ai-wpoos' ) );
			if ( class_exists( 'WP_MCP_AI_Embedded_Client' ) ) {
				foreach ( WP_MCP_AI_Embedded_Client::AVAILABLE_MODELS as $slug => $info ) {
					/* translators: 1: model name, 2: file size in MB */
					$server_model_options[ $slug ] = sprintf( __( '%1$s (%2$s MB)', 'mcp-ai-wpoos' ), $info['name'], $info['size_mb'] );
				}
			}

			return array(
				// Embedded LLM Settings (Pro version only).
				'enable_embedded'           => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Embedded LLM Provider', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Enable client-side embedded language models', 'mcp-ai-wpoos' ),
					'description'    => __( 'Run language models directly in the user\'s browser using WebGPU/WebAssembly. Fully private, no server resources required, no API keys needed. Models are downloaded on-demand to browser cache.', 'mcp-ai-wpoos' ),
					'default'        => true,
				),
				'embedded_model'            => array(
					'type'        => 'select',
					'label'       => __( 'Default Embedded Model', 'mcp-ai-wpoos' ),
					'description' => __( 'Select a model for client-side inference. Models are downloaded on-demand to the user\'s browser cache when first used. Models marked with * support tool/function calling. Recommended: Hermes 2 Pro for best function calling accuracy.', 'mcp-ai-wpoos' ),
					'options'     => $embedded_models,
					'default'     => WP_MCP_AI_Admin_Settings::DEFAULT_EMBEDDED_MODEL,
				),
				'embedded_model_management' => array(
					'type'        => 'custom',
					'label'       => __( 'Available Models', 'mcp-ai-wpoos' ),
					'description' => __( 'Models available for client-side inference. Models are automatically downloaded to the user\'s browser cache when first used. No server-side storage required.', 'mcp-ai-wpoos' ),
					'callback'    => array( $this, 'render_embedded_model_management' ),
				),
				// Server-side GGUF inference settings.
				'embedded_server_model'       => array(
					'type'        => 'select',
					'label'       => __( 'Active Server-Side Model', 'mcp-ai-wpoos' ),
					'description' => __( 'The GGUF model used for server-side inference. Only models that have been downloaded are available for use; selecting a model that has not been downloaded falls back to the first downloaded model automatically.', 'mcp-ai-wpoos' ),
					'options'     => $server_model_options,
					'default'     => '',
				),
				'server_model_management'     => array(
					'type'     => 'custom',
					'label'    => __( 'Server-Side Model Management', 'mcp-ai-wpoos' ),
					'callback' => array( $this, 'render_server_model_management' ),
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
				'embedded' => array(
					'id'     => 'embedded',
					'label'  => __( 'Embedded LLM', 'mcp-ai-wpoos' ),
					'icon'   => 'dashicons-smartphone',
					'fields' => array(
						'enable_embedded',
						'embedded_model',
						'embedded_model_management',
						'embedded_server_model',
						'server_model_management',
					),
				),
			);
		}

		/**
		 * Get the active sub-tab.
		 *
		 * @return string
		 */
		protected function get_active_subtab() {
			$subtab_groups = $this->get_subtab_groups();
			$subtab        = '';

			// Check POST data first (when form is being submitted), then fall back to GET.
			// Use section-specific field name to avoid conflicts with other sections.
			// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended -- Read-only parameter check.
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

			// Default to 'embedded' if not set or invalid.
			if ( empty( $subtab ) || ! isset( $subtab_groups[ $subtab ] ) ) {
				$subtab = 'embedded';
			}

			return $subtab;
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
		 * Render embedded model management custom field.
		 *
		 * @param array $field_data Field configuration data.
		 * @return void
		 */
		public function render_embedded_model_management( $field_data ) {
			?>
<div class="notice notice-info inline">
<p>
<strong><?php esc_html_e( 'Client-Side Models (Pro Feature)', 'mcp-ai-wpoos' ); ?></strong><br>
			<?php esc_html_e( 'Models run in the user browser using WebGPU/WebAssembly. See Pro Settings page for model list and NPM dependencies.', 'mcp-ai-wpoos' ); ?>
</p>
</div>
			<?php
		}

		/**
		 * Render server-side GGUF model management UI.
		 *
		 * Displays a status summary (proc_open availability, binary path, models
		 * directory) and a table of pre-configured downloadable models with
		 * per-model Download / Delete buttons that wire up to the existing
		 * wp_mcp_ai_download_embedded_model / wp_mcp_ai_delete_embedded_model
		 * AJAX actions handled by WP_MCP_AI_Embedded_Model_Ajax.
		 *
		 * @param array $field_data Field configuration data.
		 * @return void
		 */
		public function render_server_model_management( $field_data ) {
			if ( ! class_exists( 'WP_MCP_AI_Embedded_Client' ) ) {
				?>
				<div class="notice notice-warning inline">
					<p><?php esc_html_e( 'The server-side embedded client is not available. Please ensure the plugin is fully activated.', 'mcp-ai-wpoos' ); ?></p>
				</div>
				<?php
				return;
			}

			$client        = new WP_MCP_AI_Embedded_Client();
			$models        = $client->get_available_models();
			$proc_open_ok  = function_exists( 'proc_open' );
			$binary_result = $client->get_binary_path();
			$binary_ok     = ! is_wp_error( $binary_result );
			$binary_path   = $binary_ok ? $binary_result : '';
			$dir_result    = $client->get_models_directory();
			$dir_ok        = ! is_wp_error( $dir_result );
			$models_dir    = $dir_ok ? $dir_result : '';
			$server_ok     = $client->is_available();
			$nonce         = wp_create_nonce( 'wp_mcp_ai_embedded_models' );
			?>

			<div
				class="wp-mcp-ai-embedded-model-management wp-mcp-ai-server-model-management"
				data-nonce="<?php echo esc_attr( $nonce ); ?>"
				style="max-width:900px;"
			>

				<?php /* ── Server status summary ── */ ?>
				<table class="form-table" style="margin-bottom:12px;">
					<tbody>
						<tr>
							<th style="width:200px;padding:4px 10px 4px 0;">
								<?php esc_html_e( 'proc_open()', 'mcp-ai-wpoos' ); ?>
							</th>
							<td style="padding:4px 0;">
								<?php if ( $proc_open_ok ) : ?>
									<span style="color:#00a32a;">
										<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
										<?php esc_html_e( 'Available', 'mcp-ai-wpoos' ); ?>
									</span>
								<?php else : ?>
									<span style="color:#d63638;">
										<span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
										<?php esc_html_e( 'Disabled (shared hosting)', 'mcp-ai-wpoos' ); ?>
									</span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th style="padding:4px 10px 4px 0;">
								<?php esc_html_e( 'llama-cli binary', 'mcp-ai-wpoos' ); ?>
							</th>
							<td style="padding:4px 0;">
								<?php if ( $binary_ok ) : ?>
									<span style="color:#00a32a;">
										<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
										<code><?php echo esc_html( $binary_path ); ?></code>
									</span>
								<?php else : ?>
									<span style="color:#d63638;">
										<span class="dashicons dashicons-dismiss" aria-hidden="true"></span>
										<?php esc_html_e( 'Not found — place llama-cli in the models directory or install llama.cpp on the server PATH.', 'mcp-ai-wpoos' ); ?>
									</span>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th style="padding:4px 10px 4px 0;">
								<?php esc_html_e( 'Models directory', 'mcp-ai-wpoos' ); ?>
							</th>
							<td style="padding:4px 0;">
								<?php if ( $dir_ok ) : ?>
									<code><?php echo esc_html( $models_dir ); ?></code>
								<?php else : ?>
									<span style="color:#d63638;">
										<?php echo esc_html( $dir_result->get_error_message() ); ?>
									</span>
								<?php endif; ?>
							</td>
						</tr>
					</tbody>
				</table>

				<?php /* ── Model cards table ── */ ?>
				<table class="wp-list-table widefat fixed striped" style="margin-bottom:12px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Model', 'mcp-ai-wpoos' ); ?></th>
							<th style="width:80px;"><?php esc_html_e( 'Size', 'mcp-ai-wpoos' ); ?></th>
							<th style="width:90px;"><?php esc_html_e( 'Min RAM', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Best For', 'mcp-ai-wpoos' ); ?></th>
							<th style="width:130px;"><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
							<th style="width:100px;"><?php esc_html_e( 'Action', 'mcp-ai-wpoos' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $models as $slug => $model ) : ?>
						<tr
							class="wp-mcp-ai-model-row"
							data-model-name="<?php echo esc_attr( $model['name'] ); ?>"
						>
							<td>
								<strong><?php echo esc_html( $model['name'] ); ?></strong><br>
								<code style="font-size:11px;"><?php echo esc_html( $model['filename'] ); ?></code>
							</td>
							<td>
								<?php
								/* translators: %d: file size in megabytes */
								echo esc_html( sprintf( __( '%d MB', 'mcp-ai-wpoos' ), $model['size_mb'] ) );
								?>
							</td>
							<td>
								<?php
								/* translators: %d: minimum required RAM in megabytes */
								echo esc_html( sprintf( __( '%d MB', 'mcp-ai-wpoos' ), $model['min_ram_mb'] ) );
								?>
							</td>
							<td><?php echo esc_html( $model['best_for'] ); ?></td>
							<td class="wp-mcp-ai-model-status">
								<?php if ( ! empty( $model['downloaded'] ) ) : ?>
									<span style="color:#00a32a;">
										<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
										<?php esc_html_e( 'Downloaded', 'mcp-ai-wpoos' ); ?>
									</span>
								<?php else : ?>
									<span style="color:#646970;">
										<span class="dashicons dashicons-download" aria-hidden="true"></span>
										<?php esc_html_e( 'Not downloaded', 'mcp-ai-wpoos' ); ?>
									</span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( ! empty( $model['downloaded'] ) ) : ?>
									<button
										type="button"
										class="button wp-mcp-ai-delete-model"
										data-model-slug="<?php echo esc_attr( $slug ); ?>"
									><?php esc_html_e( 'Delete', 'mcp-ai-wpoos' ); ?></button>
								<?php else : ?>
									<button
										type="button"
										class="button button-primary wp-mcp-ai-download-model"
										data-model-slug="<?php echo esc_attr( $slug ); ?>"
									><?php esc_html_e( 'Download', 'mcp-ai-wpoos' ); ?></button>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>

				<?php /* ── Test inference button (only useful when the server path is fully ready) ── */ ?>
				<?php if ( $server_ok ) : ?>
					<p>
						<button
							type="button"
							class="button wp-mcp-ai-test-embedded-server"
						><?php esc_html_e( 'Test Server Inference', 'mcp-ai-wpoos' ); ?></button>
						<span class="wp-mcp-ai-server-test-result" style="margin-left:10px;"></span>
					</p>
				<?php endif; ?>

				<p class="description">
					<?php
					esc_html_e(
						'Server-side inference runs the selected GGUF model on your web server using llama-cli. It requires proc_open() (available on VPS/dedicated hosting) and a llama-cli binary. Downloads are fetched from Hugging Face and stored in the models directory above.',
						'mcp-ai-wpoos'
					);
					?>
				</p>

			</div><!-- .wp-mcp-ai-embedded-model-management -->
			<?php
		}
}
}
