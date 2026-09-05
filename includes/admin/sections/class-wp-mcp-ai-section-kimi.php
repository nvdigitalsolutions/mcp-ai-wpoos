<?php
/**
 * Kimi provider settings section.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Kimi' ) ) {
	/**
	 * Kimi (Moonshot AI) provider settings section.
	 *
	 * @since 1.0.0
	 */
	class WP_MCP_AI_Section_Kimi {

		/**
		 * Get the section ID.
		 *
		 * @since 1.0.0
		 * @return string Section identifier.
		 */
		public function get_id() {
			return 'kimi';
		}

		/**
		 * Get the section title.
		 *
		 * @since 1.0.0
		 * @return string Section title.
		 */
		public function get_title() {
			return __( 'Kimi (Moonshot AI)', 'mcp-ai-wpoos' );
		}

		/**
		 * Get the section description.
		 *
		 * @since 1.0.0
		 * @return string Section description.
		 */
		public function get_description() {
			return __( 'Configure Kimi AI provider settings. Kimi offers powerful models like K2.7 Code and K2.6 with 256K context windows and multimodal capabilities.', 'mcp-ai-wpoos' );
		}

		/**
		 * Get the fields for this section.
		 *
		 * @since 1.0.0
		 * @return array Field definitions.
		 */
		public function get_fields() {
			return array(
				'enable_kimi'      => array(
					'type'        => 'checkbox',
					'label'       => __( 'Enable Kimi', 'mcp-ai-wpoos' ),
					'description' => __( 'Enable Kimi as an AI provider.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'kimi_api_key'     => array(
					'type'        => 'password',
					'label'       => __( 'API Key', 'mcp-ai-wpoos' ),
					'description' => __( 'Your Moonshot AI API key from platform.moonshot.ai', 'mcp-ai-wpoos' ),
					'placeholder' => __( 'Enter your Kimi API key', 'mcp-ai-wpoos' ),
				),
				'kimi_model'       => array(
					'type'        => 'select',
					'label'       => __( 'Default Model', 'mcp-ai-wpoos' ),
					'description' => __( 'Select the default Kimi model to use.', 'mcp-ai-wpoos' ),
					'options'     => array(
						'kimi-k3'          => __( 'Kimi K3 (2.8T MoE, 1M Context, Open Weights)', 'mcp-ai-wpoos' ),
						'kimi-k2.7-code'   => __( 'Kimi K2.7 Code (Multimodal, 256K)', 'mcp-ai-wpoos' ),
						'kimi-k2.6'        => __( 'Kimi K2.6 (Multimodal, 256K)', 'mcp-ai-wpoos' ),
						'kimi-k2.5'        => __( 'Kimi K2.5 (Multimodal, 256K)', 'mcp-ai-wpoos' ),
						'kimi-k2'          => __( 'Kimi K2 (Base, 256K)', 'mcp-ai-wpoos' ),
						'kimi-k2-thinking' => __( 'Kimi K2 Thinking (Chain-of-thought, 256K)', 'mcp-ai-wpoos' ),
					),
					'default'     => 'kimi-k3',
				),
				'kimi_base_url'    => array(
					'type'        => 'text',
					'label'       => __( 'Custom Base URL', 'mcp-ai-wpoos' ),
					'description' => __( 'Optional. Use a custom base URL for Kimi API (e.g., for proxies). Leave empty to use the default.', 'mcp-ai-wpoos' ),
					'placeholder' => 'https://api.moonshot.ai/v1',
					'default'     => '',
				),
				'kimi_timeout'     => array(
					'type'        => 'number',
					'label'       => __( 'Request Timeout', 'mcp-ai-wpoos' ),
					'description' => __( 'Timeout in seconds for Kimi API requests. Default: 60', 'mcp-ai-wpoos' ),
					'default'     => 60,
					'min'         => 5,
					'max'         => 300,
				),
				'kimi_temperature' => array(
					'type'        => 'number',
					'label'       => __( 'Default Temperature', 'mcp-ai-wpoos' ),
					'description' => __( 'Sampling temperature (0-2). Higher values make output more random. Default: 0.7', 'mcp-ai-wpoos' ),
					'default'     => 0.7,
					'min'         => 0,
					'max'         => 2,
					'step'        => 0.1,
				),
				'kimi_max_tokens'  => array(
					'type'        => 'number',
					'label'       => __( 'Default Max Completion Tokens', 'mcp-ai-wpoos' ),
					'description' => __( 'Maximum tokens to generate. Default: 4096', 'mcp-ai-wpoos' ),
					'default'     => 4096,
					'min'         => 1,
					'max'         => 8192,
				),
			);
		}

		/**
		 * Render the test connection button.
		 *
		 * @since 1.0.0
		 * @param array $settings Current settings.
		 */
		public function render_test_connection( $settings ) {
			$api_key  = isset( $settings['kimi_api_key'] ) ? $settings['kimi_api_key'] : '';
			$disabled = empty( $api_key ) ? 'disabled' : '';
			?>
			<tr>
				<th scope="row"><?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos' ); ?></th>
				<td>
					<button type="button" id="test-kimi-connection" class="button" <?php echo esc_attr( $disabled ); ?>>
						<?php esc_html_e( 'Test Connection', 'mcp-ai-wpoos' ); ?>
					</button>
					<span id="kimi-connection-status" class="connection-status"></span>
					<p class="description">
						<?php esc_html_e( 'Test your Kimi API connection before saving.', 'mcp-ai-wpoos' ); ?>
					</p>
				</td>
			</tr>
			<?php
		}

		/**
		 * Get the available models for Kimi.
		 *
		 * @since 1.0.0
		 * @return array Array of model IDs and names.
		 */
		public static function get_available_models() {
			return array(
				'kimi-k3'          => __( 'Kimi K3 (2.8T MoE, 1M Context, Open Weights)', 'mcp-ai-wpoos' ),
				'kimi-k2.7-code'   => __( 'Kimi K2.7 Code (Multimodal, 256K)', 'mcp-ai-wpoos' ),
				'kimi-k2.6'        => __( 'Kimi K2.6 (Multimodal, 256K)', 'mcp-ai-wpoos' ),
				'kimi-k2.5'        => __( 'Kimi K2.5 (Multimodal, 256K)', 'mcp-ai-wpoos' ),
				'kimi-k2'          => __( 'Kimi K2 (Base, 256K)', 'mcp-ai-wpoos' ),
				'kimi-k2-thinking' => __( 'Kimi K2 Thinking (Chain-of-thought, 256K)', 'mcp-ai-wpoos' ),
			);
		}

		/**
		 * Sanitize settings for this section.
		 *
		 * @since 1.0.0
		 * @param array $input Raw input.
		 * @return array Sanitized settings.
		 */
		public function sanitize_settings( $input ) {
			$sanitized = array();

			// Enable checkbox.
			$sanitized['enable_kimi'] = ! empty( $input['enable_kimi'] );

			// API key.
			if ( isset( $input['kimi_api_key'] ) ) {
				$sanitized['kimi_api_key'] = sanitize_text_field( $input['kimi_api_key'] );
			}

			// Model.
			if ( isset( $input['kimi_model'] ) ) {
				$allowed_models          = array_keys( self::get_available_models() );
				$sanitized['kimi_model'] = in_array( $input['kimi_model'], $allowed_models, true ) ? $input['kimi_model'] : 'kimi-k3';
			}

			// Base URL.
			if ( isset( $input['kimi_base_url'] ) ) {
				$sanitized['kimi_base_url'] = esc_url_raw( $input['kimi_base_url'] );
			}

			// Timeout.
			if ( isset( $input['kimi_timeout'] ) ) {
				$sanitized['kimi_timeout'] = max( 5, min( 300, absint( $input['kimi_timeout'] ) ) );
			}

			// Temperature.
			if ( isset( $input['kimi_temperature'] ) ) {
				$sanitized['kimi_temperature'] = max( 0, min( 2, floatval( $input['kimi_temperature'] ) ) );
			}

			// Max tokens.
			if ( isset( $input['kimi_max_tokens'] ) ) {
				$sanitized['kimi_max_tokens'] = max( 1, min( 8192, absint( $input['kimi_max_tokens'] ) ) );
			}

			return $sanitized;
		}
	}
}
