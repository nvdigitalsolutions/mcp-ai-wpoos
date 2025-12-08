<?php
/**
 * AI Media Library Settings Section
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Section_Media' ) ) {
	/**
	 * Media library AI features settings section.
	 */
	class WP_MCP_AI_Section_Media extends WP_MCP_AI_Settings_Section {
		/**
		 * Get section ID.
		 *
		 * @return string
		 */
		public function get_id() {
			return 'media';
		}

		/**
		 * Get section title.
		 *
		 * @return string
		 */
		public function get_title() {
			return __( 'AI Media Library', 'wp-mcp-ai' );
		}

		/**
		 * Get tab ID.
		 *
		 * @return string
		 */
		public function get_tab() {
			return 'tools';
		}

		/**
		 * Get section priority.
		 *
		 * @return int
		 */
		public function get_priority() {
			return 30;
		}

		/**
		 * Get section description.
		 *
		 * @return string
		 */
		public function get_description() {
			return __( 'Configure AI-powered automatic generation of alt text and captions for uploaded images to improve accessibility and SEO.', 'wp-mcp-ai' );
		}

		/**
		 * Get field definitions.
		 *
		 * @return array
		 */
		public function get_fields() {
			// Get WordPress allowed mime types for reference.
			$wp_mimes = get_allowed_mime_types();
			$default_image_mimes = implode( ', ', array_keys( array_filter( $wp_mimes, function( $mime ) {
				return strpos( $mime, 'image/' ) === 0;
			} ) ) );
			$default_file_mimes = implode( ', ', array_keys( $wp_mimes ) );
			
			return array(
				'enable_ai_media_library'     => array(
					'type'           => 'checkbox',
					'label'          => __( 'Enable AI Media Library', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Automatically analyze images on upload', 'wp-mcp-ai' ),
					'description'    => __( 'When enabled, newly uploaded images will be automatically analyzed by AI to generate alt text and captions. This feature uses vision-capable AI models (requires OpenAI or Gemini API key).', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'ai_media_generate_alt_text'  => array(
					'type'           => 'checkbox',
					'label'          => __( 'Generate Alt Text', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Automatically generate alt text for accessibility', 'wp-mcp-ai' ),
					'description'    => __( 'Generate descriptive alt text for images to improve accessibility for screen readers and SEO.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'ai_media_generate_caption'   => array(
					'type'           => 'checkbox',
					'label'          => __( 'Generate Captions', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Automatically generate image captions', 'wp-mcp-ai' ),
					'description'    => __( 'Generate detailed captions for images to provide context and enhance content.', 'wp-mcp-ai' ),
					'default'        => true,
				),
				'ai_media_overwrite_existing' => array(
					'type'           => 'checkbox',
					'label'          => __( 'Overwrite Existing', 'wp-mcp-ai' ),
					'checkbox_label' => __( 'Replace existing alt text and captions', 'wp-mcp-ai' ),
					'description'    => __( 'When enabled, AI will overwrite any existing alt text or captions. When disabled, AI will only fill in missing metadata.', 'wp-mcp-ai' ),
					'default'        => false,
				),
				'allowed_image_mimes'         => array(
					'type'        => 'textarea',
					'label'       => __( 'Allowed Image MIME Types', 'wp-mcp-ai' ),
					'description' => sprintf(
						/* translators: %s: Default image MIME types */
						__( 'Comma-separated list of allowed image file extensions for AI analysis and uploads. Leave empty to use WordPress defaults. Common formats: jpg, jpeg, png, gif, webp, svg. Current WordPress defaults: %s', 'wp-mcp-ai' ),
						'<code>' . esc_html( $default_image_mimes ) . '</code>'
					),
					'default'     => '',
					'placeholder' => 'jpg, jpeg, png, gif, webp',
					'rows'        => 3,
				),
				'allowed_file_mimes'          => array(
					'type'        => 'textarea',
					'label'       => __( 'Allowed File MIME Types', 'wp-mcp-ai' ),
					'description' => __( 'Comma-separated list of allowed file extensions for chat uploads and AI processing. Leave empty to use WordPress defaults. This controls what file types users can upload through chat and assistant interfaces. For security, only include file types you trust.', 'wp-mcp-ai' ),
					'default'     => '',
					'placeholder' => 'jpg, jpeg, png, pdf, doc, docx, txt',
					'rows'        => 4,
				),
			);
		}

		/**
		 * Render the section.
		 */
		public function render() {
			$fields = $this->get_fields();

			foreach ( $fields as $key => $field ) {
				$this->render_field( $key, $field );
			}

			// Add informational note about API requirements.
			?>
			<tr>
				<th scope="row"></th>
				<td>
					<p class="description">
						<strong><?php esc_html_e( 'Note:', 'wp-mcp-ai' ); ?></strong>
						<?php
						echo wp_kses_post(
							__(
								'This feature requires a vision-capable AI provider (OpenAI GPT-4o or Gemini) to be configured in the Providers tab. Image analysis will use the default provider specified in General Settings.',
								'wp-mcp-ai'
							)
						);
						?>
					</p>
					<p class="description">
						<?php
						echo wp_kses_post(
							__(
								'Each image upload will consume AI tokens. Consider the API costs when enabling this feature for high-volume sites.',
								'wp-mcp-ai'
							)
						);
						?>
					</p>
				</td>
			</tr>
			<?php
		}
	}
}
