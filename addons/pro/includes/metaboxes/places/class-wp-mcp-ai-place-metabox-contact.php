<?php
/**
 * Place Contact Information Metabox.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Place Contact Information metabox.
 */
class WP_MCP_AI_Place_Metabox_Contact extends WP_MCP_AI_Place_Metabox_Base {

	/**
	 * Get the metabox ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_place_contact';
	}

	/**
	 * Get the metabox title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Contact Information', 'wp-mcp-ai' );
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

		$phone   = get_post_meta( $post->ID, '_place_phone', true );
		$email   = get_post_meta( $post->ID, '_place_email', true );
		$website = get_post_meta( $post->ID, '_place_website', true );

		wp_nonce_field( 'wp_mcp_ai_place_contact_nonce', 'wp_mcp_ai_place_contact_nonce' );
		?>
		<div class="wp-mcp-ai-place-contact">
			<p>
				<label for="place_phone"><strong><?php esc_html_e( 'Phone', 'wp-mcp-ai' ); ?></strong></label>
				<input type="tel" id="place_phone" name="place_phone" value="<?php echo esc_attr( $phone ); ?>" class="widefat" placeholder="+1 (555) 123-4567" />
			</p>
			<p>
				<label for="place_email"><strong><?php esc_html_e( 'Email', 'wp-mcp-ai' ); ?></strong></label>
				<input type="email" id="place_email" name="place_email" value="<?php echo esc_attr( $email ); ?>" class="widefat" placeholder="contact@example.com" />
			</p>
			<p>
				<label for="place_website"><strong><?php esc_html_e( 'Website', 'wp-mcp-ai' ); ?></strong></label>
				<input type="url" id="place_website" name="place_website" value="<?php echo esc_attr( $website ); ?>" class="widefat" placeholder="https://example.com" />
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
		if ( ! isset( $_POST['wp_mcp_ai_place_contact_nonce'] ) || ! wp_verify_nonce( $_POST['wp_mcp_ai_place_contact_nonce'], 'wp_mcp_ai_place_contact_nonce' ) ) {
			return;
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		if ( isset( $_POST['place_phone'] ) ) {
			update_post_meta( $post_id, '_place_phone', sanitize_text_field( $_POST['place_phone'] ) );
		}

		if ( isset( $_POST['place_email'] ) ) {
			update_post_meta( $post_id, '_place_email', sanitize_email( $_POST['place_email'] ) );
		}

		if ( isset( $_POST['place_website'] ) ) {
			update_post_meta( $post_id, '_place_website', esc_url_raw( $_POST['place_website'] ) );
		}
	}
}
