<?php
/**
 * Pro Providers Settings Section
 *
 * Settings for pro-only providers (Embedded LLM).
 * These providers are only available in the pro addon.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
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
			return 15; // After base providers (10).
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
				'Qwen3-8B-q4f16_1-MLC'                     => __( 'Qwen3 8B (~5GB)*', 'mcp-ai-wpoos' ),
				'Qwen2.5-7B-Instruct-q4f16_1-MLC'          => __( 'Qwen2.5 7B Instruct (~4.5GB)*', 'mcp-ai-wpoos' ),
				'Qwen3-4B-q4f16_1-MLC'                     => __( 'Qwen3 4B (~2.5GB)*', 'mcp-ai-wpoos' ),
				'Phi-3.5-mini-instruct-q4f16_1-MLC'        => __( 'Phi-3.5 Mini Instruct (~2.5GB)*', 'mcp-ai-wpoos' ),
				'gemma-2-2b-it-q4f16_1-MLC'                => __( 'Gemma 2 2B Instruct (~1.9GB)', 'mcp-ai-wpoos' ),
				'Llama-3.2-3B-Instruct-q4f16_1-MLC'        => __( 'Llama 3.2 3B Instruct (~2GB)', 'mcp-ai-wpoos' ),
				'SmolLM2-1.7B-Instruct-q4f16_1-MLC'        => __( 'SmolLM2 1.7B Instruct (~1.8GB)', 'mcp-ai-wpoos' ),
				'Qwen3-1.7B-q4f16_1-MLC'                   => __( 'Qwen3 1.7B (~1.1GB)*', 'mcp-ai-wpoos' ),
				'Qwen2.5-1.5B-Instruct-q4f16_1-MLC'        => __( 'Qwen2.5 1.5B Instruct (~1GB)*', 'mcp-ai-wpoos' ),
				'Llama-3.2-1B-Instruct-q4f16_1-MLC'        => __( 'Llama 3.2 1B Instruct (~800MB)', 'mcp-ai-wpoos' ),
				'Qwen3-0.6B-q4f16_1-MLC'                   => __( 'Qwen3 0.6B (~400MB)', 'mcp-ai-wpoos' ),
				'Qwen2.5-0.5B-Instruct-q4f16_1-MLC'        => __( 'Qwen2.5 0.5B Instruct (~400MB)', 'mcp-ai-wpoos' ),
			);

			// Build server-side GGUF model options for the select field.
			$server_model_options = array( '' => __( '— Select a downloaded model —', 'mcp-ai-wpoos' ) );
			if ( class_exists( 'WP_MCP_AI_Embedded_Client' ) ) {
				$client     = new WP_MCP_AI_Embedded_Client();
				$downloaded = $client->get_downloaded_models();
				foreach ( $downloaded as $slug => $model ) {
					$size_label                    = round( $model['file_size'] / 1048576 ) . ' MB';
					$server_model_options[ $slug ] = $model['name'] . ' (' . $size_label . ')';
				}
			}

			return array(
				// Embedded LLM Settings (Pro version only).
				'enable_embedded'           => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable Embedded LLM Provider', 'mcp-ai-wpoos' ),
					'checkbox_label' => __( 'Auto-enabled with Pro - client-side embedded language models', 'mcp-ai-wpoos' ),
					'description'    => __( 'Run language models directly in the user\'s browser using WebGPU/WebAssembly. Fully private, no server resources required, no API keys needed. Models are downloaded on-demand to browser cache.', 'mcp-ai-wpoos' ),
					'default'        => true,
					'disabled'       => true,
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
				'embedded_server_model'     => array(
					'type'        => 'select',
					'label'       => __( 'Active Server Model', 'mcp-ai-wpoos' ),
					'description' => __( 'Select which downloaded GGUF model to use for server-side inference. Download models using the management panel below.', 'mcp-ai-wpoos' ),
					'options'     => $server_model_options,
					'default'     => '',
				),
				'server_model_management'   => array(
					'type'        => 'custom',
					'label'       => __( 'Server Model Management', 'mcp-ai-wpoos' ),
					'description' => __( 'Download GGUF models to the server for server-side inference. Models are stored in wp-content/uploads/mcp-ai-wpoos/models/.', 'mcp-ai-wpoos' ),
					'callback'    => array( $this, 'render_server_model_management' ),
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
					'fields' => array( 'enable_embedded', 'embedded_model', 'embedded_model_management', 'embedded_server_model', 'server_model_management' ),
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
		 * Render embedded model management custom field (client-side notice).
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
		 * Render the server-side GGUF model management panel.
		 *
		 * Outputs a table of pre-configured GGUF models with Download / Delete
		 * action buttons.  The JavaScript in assets/js/admin-settings.js
		 * (initEmbeddedModelManagement) handles the AJAX calls via the
		 * .wp-mcp-ai-embedded-model-management container and its data-nonce attribute.
		 *
		 * @param array $field_data Field configuration data.
		 * @return void
		 */
		public function render_server_model_management( $field_data ) {
			if ( ! class_exists( 'WP_MCP_AI_Embedded_Client' ) ) {
				echo '<p>' . esc_html__( 'Embedded client not available.', 'mcp-ai-wpoos' ) . '</p>';
				return;
			}

			$client            = new WP_MCP_AI_Embedded_Client();
			$available_models  = $client->get_available_models();
			$downloaded_models = $client->get_downloaded_models();
			$binary_status     = $client->get_binary_status();
			$nonce             = wp_create_nonce( 'wp_mcp_ai_embedded_models' );
			$models_dir        = $client->get_models_directory();
			$binary_found      = $binary_status['found'];
			$platform          = $binary_status['platform'];
			$bin_dir_path      = WP_MCP_AI_PATH . 'bin/llama.cpp';
			// Derive the arch suffix used in the GitHub release asset name (e.g. "x64", "arm64").
			$arch_suffix         = ( false !== strpos( $platform, 'arm64' ) ) ? 'arm64' : 'x64';
			$status_border_color = $binary_found ? '#46b450' : '#d63638';
			$status_bg_color     = $binary_found ? '#f0fff0' : '#fff8f8';
			?>
<div class="wp-mcp-ai-embedded-model-management" data-nonce="<?php echo esc_attr( $nonce ); ?>">

			<?php /* ---- llama.cpp binary status ---- */ ?>
	<div class="wp-mcp-ai-binary-status" style="margin-bottom:16px; padding:12px 16px; border:1px solid <?php echo esc_attr( $status_border_color ); ?>; border-radius:4px; background:<?php echo esc_attr( $status_bg_color ); ?>;">
		<h4 style="margin:0 0 8px;">
			<?php if ( $binary_found ) : ?>
				<span class="dashicons dashicons-yes-alt" style="color:#46b450; vertical-align:middle;"></span>
				<?php esc_html_e( 'llama.cpp Runtime: Installed', 'mcp-ai-wpoos' ); ?>
			<?php else : ?>
				<span class="dashicons dashicons-warning" style="color:#d63638; vertical-align:middle;"></span>
				<?php esc_html_e( 'llama.cpp Runtime: Not Installed', 'mcp-ai-wpoos' ); ?>
			<?php endif; ?>
		</h4>

			<?php if ( $binary_found ) : ?>
			<p style="margin:0 0 4px;">
				<?php
				printf(
					/* translators: %s: file path */
					esc_html__( 'Binary path: %s', 'mcp-ai-wpoos' ),
					'<code>' . esc_html( $binary_status['path'] ) . '</code>'
				);
				?>
			</p>
			<p style="margin:0 0 8px; color:#666;">
				<?php
				printf(
					/* translators: %s: platform string e.g. "linux x64" */
					esc_html__( 'Platform: %s', 'mcp-ai-wpoos' ),
					'<strong>' . esc_html( $platform ) . '</strong>'
				);
				?>
			</p>
				<?php if ( 0 === strpos( $platform, 'linux' ) ) : ?>
				<button type="button" id="wp-mcp-ai-reinstall-binary" class="button button-secondary" style="margin-right:8px;">
					<span class="dashicons dashicons-update" style="vertical-align:middle; margin-top:3px;"></span>
					<?php esc_html_e( 'Re-install llama.cpp Binary', 'mcp-ai-wpoos' ); ?>
				</button>
				<span id="wp-mcp-ai-binary-reinstall-status" style="display:none; vertical-align:middle; margin-left:8px;"></span>
				<p class="description" style="margin-top:6px;">
					<?php esc_html_e( 'Use this if llama-cli is missing its shared libraries (e.g. libmtmd.so.0) or if you want to upgrade to the latest version.', 'mcp-ai-wpoos' ); ?>
				</p>
			<?php endif; ?>
		<?php else : ?>
			<p style="margin:0 0 8px;">
				<?php esc_html_e( 'The llama.cpp runtime (llama-cli) is required to run server-side inference. Download it automatically or follow the manual instructions below.', 'mcp-ai-wpoos' ); ?>
			</p>
			<p style="margin:0 0 8px; color:#666;">
				<?php
				printf(
					/* translators: %s: platform string e.g. "linux x64" */
					esc_html__( 'Detected platform: %s', 'mcp-ai-wpoos' ),
					'<strong>' . esc_html( $platform ) . '</strong>'
				);
				?>
			</p>

			<?php if ( 0 === strpos( $platform, 'linux' ) ) : ?>
				<button type="button" id="wp-mcp-ai-download-binary" class="button button-primary" style="margin-right:8px;">
					<span class="dashicons dashicons-download" style="vertical-align:middle; margin-top:3px;"></span>
					<?php esc_html_e( 'Download llama.cpp Binary', 'mcp-ai-wpoos' ); ?>
				</button>
				<span id="wp-mcp-ai-binary-download-status" style="display:none; vertical-align:middle; margin-left:8px;"></span>
			<?php endif; ?>

			<details style="margin-top:10px;">
				<summary style="cursor:pointer; color:#2271b1;"><?php esc_html_e( 'Manual installation instructions', 'mcp-ai-wpoos' ); ?></summary>
				<pre style="margin-top:8px; padding:10px; background:#f6f7f7; border:1px solid #ddd; overflow-x:auto; white-space:pre-wrap; font-size:12px;"># <?php esc_html_e( 'Download the latest release archive from:', 'mcp-ai-wpoos' ); ?>

# https://github.com/ggml-org/llama.cpp/releases/latest
# (
			<?php
			/* translators: %s: architecture string, e.g. "x64" or "arm64" */
			printf( esc_html__( 'download the file named like: llama-bXXXX-bin-ubuntu-%s.tar.gz', 'mcp-ai-wpoos' ), esc_html( $arch_suffix ) );
			?>
			)

# <?php esc_html_e( 'Extract and install the binary and shared libraries:', 'mcp-ai-wpoos' ); ?>

mkdir -p /tmp/llama-bin-extract <?php echo esc_html( $bin_dir_path ); ?>

tar -xzf /tmp/llama-bXXXX-bin-ubuntu-<?php echo esc_html( $arch_suffix ); ?>.tar.gz -C /tmp/llama-bin-extract/
mv /tmp/llama-bin-extract/*/llama-cli <?php echo esc_html( $bin_dir_path ); ?>/llama-cli
cp /tmp/llama-bin-extract/*/lib*.so* <?php echo esc_html( $bin_dir_path ); ?>/ 2>/dev/null; true
chmod +x <?php echo esc_html( $bin_dir_path ); ?>/llama-cli

# <?php esc_html_e( 'Verify:', 'mcp-ai-wpoos' ); ?>

			<?php echo esc_html( $bin_dir_path ); ?>/llama-cli --version</pre>
			</details>
		<?php endif; ?>
	</div>

			<?php /* ---- GGUF model table ---- */ ?>
	<p class="description">
			<?php
			printf(
			/* translators: %s: directory path */
				esc_html__( 'Models are stored in: %s', 'mcp-ai-wpoos' ),
				'<code>' . esc_html( $models_dir ) . '</code>'
			);
			?>
	</p>

	<table class="widefat striped" style="margin-top:12px;">
		<thead>
			<tr>
				<th><?php esc_html_e( 'Model', 'mcp-ai-wpoos' ); ?></th>
				<th><?php esc_html_e( 'Size', 'mcp-ai-wpoos' ); ?></th>
				<th><?php esc_html_e( 'RAM', 'mcp-ai-wpoos' ); ?></th>
				<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
				<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $available_models as $slug => $model ) : ?>
				<?php
				$is_downloaded = isset( $downloaded_models[ $slug ] );
				$file_size_mb  = $is_downloaded ? round( $downloaded_models[ $slug ]['file_size'] / 1048576 ) . ' MB' : '—';
				?>
			<tr class="wp-mcp-ai-model-row" data-model-name="<?php echo esc_attr( $model['name'] ); ?>">
				<td>
					<strong><?php echo esc_html( $model['name'] ); ?></strong><br>
					<span class="description"><?php echo esc_html( $model['description'] ); ?></span>
				</td>
				<td>~<?php echo esc_html( $model['size_mb'] ); ?> MB</td>
				<td><?php echo esc_html( $model['ram_gb'] ); ?> GB+</td>
				<td class="wp-mcp-ai-model-status">
					<?php if ( $is_downloaded ) : ?>
					<span class="dashicons dashicons-yes-alt" style="color:#46b450;"></span>
						<?php esc_html_e( 'Downloaded', 'mcp-ai-wpoos' ); ?>
					(<?php echo esc_html( $file_size_mb ); ?>)
					<?php else : ?>
					<span class="dashicons dashicons-download"></span>
						<?php esc_html_e( 'Not Downloaded', 'mcp-ai-wpoos' ); ?>
					<?php endif; ?>
				</td>
				<td>
					<?php if ( $is_downloaded ) : ?>
					<button type="button"
						class="button button-small wp-mcp-ai-delete-model"
						data-model-slug="<?php echo esc_attr( $slug ); ?>"
						title="<?php esc_attr_e( 'Delete', 'mcp-ai-wpoos' ); ?>">
						<span class="dashicons dashicons-trash" aria-hidden="true"></span>
						<span class="screen-reader-text"><?php esc_html_e( 'Delete', 'mcp-ai-wpoos' ); ?></span>
					</button>
					<?php else : ?>
					<button type="button"
						class="button button-primary button-small wp-mcp-ai-download-model"
						data-model-slug="<?php echo esc_attr( $slug ); ?>">
						<?php esc_html_e( 'Download', 'mcp-ai-wpoos' ); ?>
					</button>
					<?php endif; ?>
				</td>
			</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<p class="description" style="margin-top:8px;">
			<?php esc_html_e( 'Downloads may take several minutes depending on model size and server connection speed. The page will reload after a successful download.', 'mcp-ai-wpoos' ); ?>
	</p>

</div>
			<?php
		}
	}
}
