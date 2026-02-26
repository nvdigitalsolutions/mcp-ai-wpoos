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
					'fields' => array( 'enable_embedded', 'embedded_model', 'embedded_model_management' ),
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
	}
}
