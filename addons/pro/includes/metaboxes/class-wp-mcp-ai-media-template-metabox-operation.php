<?php
/**
 * Media Template Operation Metabox.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Operation Configuration metabox for media templates.
 *
 * Manages operation type selection and parameter configuration.
 */
class WP_MCP_AI_Media_Template_Metabox_Operation extends WP_MCP_AI_Media_Template_Metabox_Base {

	/**
	 * Get the metabox ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_media_template_operation';
	}

	/**
	 * Get the metabox title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Operation Configuration', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get metabox context.
	 *
	 * @return string
	 */
	public function get_context() {
		return 'normal';
	}

	/**
	 * Get metabox priority.
	 *
	 * @return string
	 */
	public function get_priority() {
		return 'high';
	}

	/**
	 * Render the metabox content.
	 *
	 * @param WP_Post $post The post object.
	 * @return void
	 */
	public function render( $post ) {
		if ( ! $this->can_view() ) {
			$this->render_permission_denied();
			return;
		}

		// Get existing values.
		$operation  = get_post_meta( $post->ID, '_mcp_ai_template_operation', true );
		$parameters = get_post_meta( $post->ID, '_mcp_ai_template_parameters', true );

		// Decode parameters.
		$params = array();
		if ( ! empty( $parameters ) ) {
			$params = json_decode( $parameters, true );
			if ( json_last_error() !== JSON_ERROR_NONE ) {
				$params = array();
			}
		}

		$operations = array(
			'add_logo'       => __( 'Add Logo', 'mcp-ai-wpoos-pro' ),
			'resize_graphic' => __( 'Resize Graphic', 'mcp-ai-wpoos-pro' ),
			'expand_scene'   => __( 'Expand Scene', 'mcp-ai-wpoos-pro' ),
			'ai_enhance'     => __( 'AI Enhance', 'mcp-ai-wpoos-pro' ),
			'ai_style'       => __( 'AI Style Transfer', 'mcp-ai-wpoos-pro' ),
			'ai_background'  => __( 'AI Background Modification', 'mcp-ai-wpoos-pro' ),
			'ai_retouch'     => __( 'AI Retouch', 'mcp-ai-wpoos-pro' ),
		);

		// Nonce for security.
		wp_nonce_field( 'wp_mcp_ai_media_template_operation_nonce', 'wp_mcp_ai_media_template_operation_nonce' );
		?>
		<div class="wp-mcp-ai-media-template-operation">
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="wp_mcp_ai_template_operation">
							<?php esc_html_e( 'Operation Type:', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</th>
					<td>
						<select name="wp_mcp_ai_template_operation" id="wp_mcp_ai_template_operation" class="regular-text" required>
							<option value=""><?php esc_html_e( '-- Select Operation --', 'mcp-ai-wpoos-pro' ); ?></option>
							<?php foreach ( $operations as $op_value => $op_label ) : ?>
								<option value="<?php echo esc_attr( $op_value ); ?>" <?php selected( $operation, $op_value ); ?>>
									<?php echo esc_html( $op_label ); ?>
								</option>
							<?php endforeach; ?>
						</select>
						<p class="description">
							<?php esc_html_e( 'Select the type of operation this template will perform.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="wp_mcp_ai_template_parameters">
							<?php esc_html_e( 'Operation Parameters:', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</th>
					<td>
						<textarea 
							name="wp_mcp_ai_template_parameters" 
							id="wp_mcp_ai_template_parameters" 
							rows="15" 
							class="large-text code"
							placeholder='{"logo_position": "bottom-left", "logo_scale": 0.15}'
						><?php echo esc_textarea( ! empty( $params ) ? wp_json_encode( $params, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : '' ); ?></textarea>
						<p class="description">
							<?php esc_html_e( 'Enter operation parameters as JSON. See parameter guide below for available options.', 'mcp-ai-wpoos-pro' ); ?>
						</p>
					</td>
				</tr>
			</table>

			<?php $this->render_parameter_guide(); ?>
			<?php $this->render_documentation_link(); ?>
		</div>
		<?php
	}

	/**
	 * Render parameter guide for different operations.
	 *
	 * @return void
	 */
	protected function render_parameter_guide() {
		?>
		<div class="wp-mcp-ai-parameter-guide" style="margin-top: 20px; padding: 15px; background: #f9f9f9; border-left: 4px solid #2271b1;">
			<h3><?php esc_html_e( 'Parameter Guide by Operation Type', 'mcp-ai-wpoos-pro' ); ?></h3>
			
			<div class="parameter-section">
				<h4><?php esc_html_e( 'Add Logo', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul style="list-style: disc; margin-left: 20px;">
					<li><code>logo_attachment_id</code> (int): Attachment ID of the logo image</li>
					<li><code>logo_position</code> (string): Position - top-left, top-right, bottom-left, bottom-right, center (default: bottom-left)</li>
					<li><code>logo_scale</code> (float): Scale relative to image width, 0.05-0.5 (default: 0.15)</li>
					<li><code>logo_margin</code> (int): Margin in pixels from edge (default: 20)</li>
				</ul>
				<p><strong><?php esc_html_e( 'Example:', 'mcp-ai-wpoos-pro' ); ?></strong></p>
				<pre style="background: #fff; padding: 10px; border: 1px solid #ddd; overflow-x: auto;"><code>{
  "logo_attachment_id": 123,
  "logo_position": "bottom-right",
  "logo_scale": 0.2,
  "logo_margin": 30
}</code></pre>
			</div>

			<div class="parameter-section" style="margin-top: 15px;">
				<h4><?php esc_html_e( 'Resize Graphic', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul style="list-style: disc; margin-left: 20px;">
					<li><code>target_width</code> (int): Target width in pixels (optional if height provided)</li>
					<li><code>target_height</code> (int): Target height in pixels (optional if width provided)</li>
					<li><code>output_format</code> (string): png, jpg, webp (default: png)</li>
					<li><code>maintain_ratio</code> (bool): Preserve aspect ratio (default: true)</li>
					<li><code>quality</code> (int): Quality for JPG/WebP, 1-100 (default: 90)</li>
				</ul>
				<p><strong><?php esc_html_e( 'Example:', 'mcp-ai-wpoos-pro' ); ?></strong></p>
				<pre style="background: #fff; padding: 10px; border: 1px solid #ddd; overflow-x: auto;"><code>{
  "target_width": 1920,
  "target_height": 1080,
  "output_format": "webp",
  "quality": 85
}</code></pre>
			</div>

			<div class="parameter-section" style="margin-top: 15px;">
				<h4><?php esc_html_e( 'AI Operations (Enhance, Style, Background, Retouch)', 'mcp-ai-wpoos-pro' ); ?></h4>
				<ul style="list-style: disc; margin-left: 20px;">
					<li><code>prompt</code> (string): Instructions for AI transformation (required)</li>
					<li><code>model</code> (string): Gemini model (default: gemini-2.0-flash-exp)</li>
					<li><code>aspect_ratio</code> (string): 1:1, 16:9, 4:3, 3:2, 9:16 (default: 1:1)</li>
				</ul>
				<p><strong><?php esc_html_e( 'Example:', 'mcp-ai-wpoos-pro' ); ?></strong></p>
				<pre style="background: #fff; padding: 10px; border: 1px solid #ddd; overflow-x: auto;"><code>{
  "prompt": "enhance brightness and contrast, improve colors",
  "model": "gemini-2.0-flash-exp",
  "aspect_ratio": "16:9"
}</code></pre>
			</div>

			<p style="margin-top: 15px; padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107;">
				<strong><?php esc_html_e( 'Note:', 'mcp-ai-wpoos-pro' ); ?></strong>
				<?php esc_html_e( 'When applying a template, you can override these parameters or merge them with additional parameters.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Save metabox data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public function save( $post_id, $post ) {
		// Check nonce.
		if ( ! isset( $_POST['wp_mcp_ai_media_template_operation_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_media_template_operation_nonce'] ) ), 'wp_mcp_ai_media_template_operation_nonce' ) ) {
			return;
		}

		// Check autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Save operation type.
		if ( isset( $_POST['wp_mcp_ai_template_operation'] ) ) {
			$operation = sanitize_key( wp_unslash( $_POST['wp_mcp_ai_template_operation'] ) );
			update_post_meta( $post_id, '_mcp_ai_template_operation', $operation );
		}

		// Save and validate parameters.
		if ( isset( $_POST['wp_mcp_ai_template_parameters'] ) ) {
			$parameters = sanitize_textarea_field( wp_unslash( $_POST['wp_mcp_ai_template_parameters'] ) );
			
			// Validate JSON.
			if ( ! empty( $parameters ) ) {
				$decoded = json_decode( $parameters, true );
				if ( json_last_error() === JSON_ERROR_NONE && is_array( $decoded ) ) {
					// Re-encode to ensure clean JSON.
					$parameters = wp_json_encode( $decoded );
				} else {
					// Invalid JSON - don't save.
					$parameters = '';
				}
			}
			
			update_post_meta( $post_id, '_mcp_ai_template_parameters', $parameters );
		}
	}

	/**
	 * Get documentation URL for this metabox.
	 *
	 * @return string
	 */
	public function get_documentation_url() {
		// Link to graphic editor plus tool documentation.
		return 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/graphic-editor-plus-tool.md';
	}
}
