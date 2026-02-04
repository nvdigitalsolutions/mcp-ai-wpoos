<?php
/**
 * WebChat Assistant Assignment Metabox.
 *
 * Allows assigning an AI assistant to a WebChat room to handle
 * automated responses and moderation.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the WebChat Assistant Assignment metabox for webchat posts.
 *
 * Manages AI assistant assignment for automated chat responses in WebChat rooms.
 */
class WP_MCP_AI_WebChat_Metabox_Assistant extends WP_MCP_AI_WebChat_Metabox_Base {

	/**
	 * Get the metabox ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_webchat_assistant';
	}

	/**
	 * Get the metabox title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'AI Assistant', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get metabox context.
	 *
	 * @return string
	 */
	public function get_context() {
		return 'side';
	}

	/**
	 * Get metabox priority.
	 *
	 * @return string
	 */
	public function get_priority() {
		return 'default';
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

		// Get existing value.
		$assigned_assistant_id = get_post_meta( $post->ID, '_mcp_ai_webchat_assigned_assistant', true );

		// Get available assistants.
		$assistants = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		// Nonce for security.
		wp_nonce_field( 'wp_mcp_ai_webchat_assistant_nonce', 'wp_mcp_ai_webchat_assistant_nonce' );
		?>
		<div class="wp-mcp-ai-webchat-assistant">
			<p>
				<label for="wp_mcp_ai_webchat_assigned_assistant">
					<strong><?php esc_html_e( 'Assigned Assistant:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label>
				<select
					id="wp_mcp_ai_webchat_assigned_assistant"
					name="wp_mcp_ai_webchat_assigned_assistant"
					class="widefat"
					style="margin-top: 5px;"
				>
					<option value="0"><?php esc_html_e( '-- None --', 'mcp-ai-wpoos-pro' ); ?></option>
					<?php foreach ( $assistants as $assistant ) : ?>
						<option value="<?php echo esc_attr( $assistant->ID ); ?>" <?php selected( $assigned_assistant_id, $assistant->ID ); ?>>
							<?php echo esc_html( $assistant->post_title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</p>

			<p class="description">
				<?php esc_html_e( 'Select an AI assistant to automatically respond to messages in this room.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<?php if ( ! empty( $assigned_assistant_id ) ) : ?>
				<div style="margin-top: 15px; padding: 10px; background: #e7f3ff; border-left: 4px solid #007cba;">
					<p style="margin: 0;">
						<strong><?php esc_html_e( '✓ Assistant Active', 'mcp-ai-wpoos-pro' ); ?></strong><br>
						<span class="description">
							<?php esc_html_e( 'This assistant will monitor and respond to messages in this room.', 'mcp-ai-wpoos-pro' ); ?>
						</span>
					</p>
				</div>
			<?php endif; ?>

			<div style="margin-top: 15px; padding: 10px; background: #f0f0f1; border-left: 4px solid #646970;">
				<p style="margin: 0;">
					<strong><?php esc_html_e( 'How It Works:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<ul style="margin: 10px 0 0 20px; font-size: 13px;">
					<li><?php esc_html_e( 'Assistant receives all room messages', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Can respond using the send_webchat_message tool', 'mcp-ai-wpoos-pro' ); ?></li>
					<li><?php esc_html_e( 'Useful for moderation and automated support', 'mcp-ai-wpoos-pro' ); ?></li>
				</ul>
			</div>
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
		if ( ! isset( $_POST['wp_mcp_ai_webchat_assistant_nonce'] ) || ! wp_verify_nonce( $_POST['wp_mcp_ai_webchat_assistant_nonce'], 'wp_mcp_ai_webchat_assistant_nonce' ) ) {
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

		// Save assigned assistant.
		if ( isset( $_POST['wp_mcp_ai_webchat_assigned_assistant'] ) ) {
			$assistant_id = absint( $_POST['wp_mcp_ai_webchat_assigned_assistant'] );

			// Validate that the assistant exists if not 0.
			if ( $assistant_id > 0 ) {
				$assistant = get_post( $assistant_id );
				if ( ! $assistant || 'mcp_ai_assistant' !== $assistant->post_type || 'publish' !== $assistant->post_status ) {
					// Invalid assistant, reset to none.
					$assistant_id = 0;
				}
			}

			update_post_meta( $post_id, '_mcp_ai_webchat_assigned_assistant', $assistant_id );
		}
	}

	/**
	 * Get documentation URL for this metabox.
	 *
	 * @return string Documentation URL.
	 */
	public function get_documentation_url() {
		return 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/WEBCHAT-SELF-HOSTED-SUMMARY.md';
	}
}
