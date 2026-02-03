<?php
/**
 * WebChat Details Metabox.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the WebChat Details metabox for webchat posts.
 *
 * Manages room ID, status, signaling server, max participants, and anonymous access settings.
 */
class WP_MCP_AI_WebChat_Metabox_Details extends WP_MCP_AI_WebChat_Metabox_Base {

	/**
	 * Get the metabox ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_webchat_details';
	}

	/**
	 * Get the metabox title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Room Settings', 'mcp-ai-wpoos-pro' );
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

		// Get existing values.
		$room_id              = get_post_meta( $post->ID, '_mcp_ai_webchat_room_id', true );
		$room_status          = get_post_meta( $post->ID, '_mcp_ai_webchat_status', true );
		$signaling_server     = get_post_meta( $post->ID, '_mcp_ai_webchat_signaling_server', true );
		$max_participants     = get_post_meta( $post->ID, '_mcp_ai_webchat_max_participants', true );
		$allow_anonymous      = get_post_meta( $post->ID, '_mcp_ai_webchat_allow_anonymous', true );

		// Set defaults.
		if ( empty( $room_id ) ) {
			$room_id = $this->generate_room_id( $post->ID );
		}
		if ( empty( $room_status ) ) {
			$room_status = 'active';
		}
		if ( '' === $max_participants ) {
			$max_participants = get_option( 'wp_mcp_ai_webchat_default_max_participants', 10 );
		}
		if ( '' === $allow_anonymous ) {
			$allow_anonymous = '0';
		}

		// Nonce for security.
		wp_nonce_field( 'wp_mcp_ai_webchat_details_nonce', 'wp_mcp_ai_webchat_details_nonce' );
		?>
		<div class="wp-mcp-ai-webchat-details">
			<p>
				<label for="wp_mcp_ai_webchat_room_id">
					<strong><?php esc_html_e( 'Room ID:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label>
				<input
					type="text"
					id="wp_mcp_ai_webchat_room_id"
					name="wp_mcp_ai_webchat_room_id"
					value="<?php echo esc_attr( $room_id ); ?>"
					class="widefat"
					readonly
				/>
				<span class="description"><?php esc_html_e( 'Auto-generated unique room identifier', 'mcp-ai-wpoos-pro' ); ?></span>
			</p>

			<p>
				<label for="wp_mcp_ai_webchat_status">
					<strong><?php esc_html_e( 'Room Status:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label>
				<select
					id="wp_mcp_ai_webchat_status"
					name="wp_mcp_ai_webchat_status"
					class="widefat"
				>
					<option value="active" <?php selected( $room_status, 'active' ); ?>><?php esc_html_e( 'Active', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="inactive" <?php selected( $room_status, 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="archived" <?php selected( $room_status, 'archived' ); ?>><?php esc_html_e( 'Archived', 'mcp-ai-wpoos-pro' ); ?></option>
				</select>
				<span class="description"><?php esc_html_e( 'Only active rooms accept new participants', 'mcp-ai-wpoos-pro' ); ?></span>
			</p>

			<p>
				<label for="wp_mcp_ai_webchat_signaling_server">
					<strong><?php esc_html_e( 'Signaling Server URL:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label>
				<input
					type="url"
					id="wp_mcp_ai_webchat_signaling_server"
					name="wp_mcp_ai_webchat_signaling_server"
					value="<?php echo esc_attr( $signaling_server ); ?>"
					class="widefat"
					placeholder="<?php echo esc_attr( get_option( 'wp_mcp_ai_webchat_default_signaling_server', '' ) ); ?>"
				/>
				<span class="description"><?php esc_html_e( 'Optional override from global settings', 'mcp-ai-wpoos-pro' ); ?></span>
			</p>

			<p>
				<label for="wp_mcp_ai_webchat_max_participants">
					<strong><?php esc_html_e( 'Max Participants:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label>
				<input
					type="number"
					id="wp_mcp_ai_webchat_max_participants"
					name="wp_mcp_ai_webchat_max_participants"
					value="<?php echo esc_attr( $max_participants ); ?>"
					min="2"
					max="100"
					step="1"
					class="widefat"
				/>
				<span class="description"><?php esc_html_e( 'Maximum number of participants (2-100)', 'mcp-ai-wpoos-pro' ); ?></span>
			</p>

			<p>
				<label for="wp_mcp_ai_webchat_allow_anonymous">
					<input
						type="checkbox"
						id="wp_mcp_ai_webchat_allow_anonymous"
						name="wp_mcp_ai_webchat_allow_anonymous"
						value="1"
						<?php checked( $allow_anonymous, '1' ); ?>
					/>
					<strong><?php esc_html_e( 'Allow Anonymous Users', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label>
				<span class="description" style="display: block; margin-left: 0;"><?php esc_html_e( 'Allow non-logged-in users to join this room', 'mcp-ai-wpoos-pro' ); ?></span>
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
		if ( ! isset( $_POST['wp_mcp_ai_webchat_details_nonce'] ) || ! wp_verify_nonce( $_POST['wp_mcp_ai_webchat_details_nonce'], 'wp_mcp_ai_webchat_details_nonce' ) ) {
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

		// Save room ID.
		if ( isset( $_POST['wp_mcp_ai_webchat_room_id'] ) ) {
			$room_id = sanitize_text_field( $_POST['wp_mcp_ai_webchat_room_id'] );
			if ( empty( $room_id ) ) {
				$room_id = $this->generate_room_id( $post_id );
			}
			update_post_meta( $post_id, '_mcp_ai_webchat_room_id', $room_id );
		}

		// Save room status.
		if ( isset( $_POST['wp_mcp_ai_webchat_status'] ) ) {
			$status = sanitize_text_field( $_POST['wp_mcp_ai_webchat_status'] );
			if ( ! in_array( $status, array( 'active', 'inactive', 'archived' ), true ) ) {
				$status = 'active';
			}
			update_post_meta( $post_id, '_mcp_ai_webchat_status', $status );
		}

		// Save signaling server.
		if ( isset( $_POST['wp_mcp_ai_webchat_signaling_server'] ) ) {
			$signaling_server = sanitize_url( $_POST['wp_mcp_ai_webchat_signaling_server'] );
			update_post_meta( $post_id, '_mcp_ai_webchat_signaling_server', $signaling_server );
		}

		// Save max participants.
		if ( isset( $_POST['wp_mcp_ai_webchat_max_participants'] ) ) {
			$max_participants = absint( $_POST['wp_mcp_ai_webchat_max_participants'] );
			$max_participants = max( 2, min( 100, $max_participants ) );
			update_post_meta( $post_id, '_mcp_ai_webchat_max_participants', $max_participants );
		}

		// Save allow anonymous.
		$allow_anonymous = isset( $_POST['wp_mcp_ai_webchat_allow_anonymous'] ) ? '1' : '0';
		update_post_meta( $post_id, '_mcp_ai_webchat_allow_anonymous', $allow_anonymous );
	}

	/**
	 * Generate a unique room ID based on site URL and timestamp.
	 *
	 * @param int $post_id Post ID.
	 * @return string Generated room ID.
	 */
	private function generate_room_id( $post_id ) {
		$site_url = get_site_url();
		$site_hash = substr( md5( $site_url ), 0, 8 );
		$timestamp = time();
		return sprintf( 'room_%s_%d_%d', $site_hash, $post_id, $timestamp );
	}
}
