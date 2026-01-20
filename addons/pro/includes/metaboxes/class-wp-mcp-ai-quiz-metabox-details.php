<?php
/**
 * Quiz Details Metabox.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Quiz Details metabox for quiz posts.
 *
 * Manages quiz description, time limit, and passing score settings.
 */
class WP_MCP_AI_Quiz_Metabox_Details extends WP_MCP_AI_Quiz_Metabox_Base {

	/**
	 * Get the metabox ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_quiz_details';
	}

	/**
	 * Get the metabox title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Quiz Settings', 'mcp-ai-wpoos-pro' );
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
		$time_limit    = get_post_meta( $post->ID, '_mcp_ai_quiz_time_limit', true );
		$passing_score = get_post_meta( $post->ID, '_mcp_ai_quiz_passing_score', true );

		// Set defaults.
		if ( '' === $time_limit ) {
			$time_limit = 0;
		}
		if ( '' === $passing_score ) {
			$passing_score = 70;
		}

		// Nonce for security.
		wp_nonce_field( 'wp_mcp_ai_quiz_details_nonce', 'wp_mcp_ai_quiz_details_nonce' );
		?>
		<div class="wp-mcp-ai-quiz-details">
			<p>
				<label for="wp_mcp_ai_quiz_time_limit">
					<strong><?php esc_html_e( 'Time Limit (minutes):', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label>
				<input
					type="number"
					id="wp_mcp_ai_quiz_time_limit"
					name="wp_mcp_ai_quiz_time_limit"
					value="<?php echo esc_attr( $time_limit ); ?>"
					min="0"
					step="1"
					class="widefat"
				/>
				<span class="description"><?php esc_html_e( 'Set to 0 for no time limit', 'mcp-ai-wpoos-pro' ); ?></span>
			</p>

			<p>
				<label for="wp_mcp_ai_quiz_passing_score">
					<strong><?php esc_html_e( 'Passing Score (%):', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label>
				<input
					type="number"
					id="wp_mcp_ai_quiz_passing_score"
					name="wp_mcp_ai_quiz_passing_score"
					value="<?php echo esc_attr( $passing_score ); ?>"
					min="0"
					max="100"
					step="1"
					class="widefat"
				/>
				<span class="description"><?php esc_html_e( 'Minimum percentage to pass (0-100)', 'mcp-ai-wpoos-pro' ); ?></span>
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
		if ( ! isset( $_POST['wp_mcp_ai_quiz_details_nonce'] ) || ! wp_verify_nonce( $_POST['wp_mcp_ai_quiz_details_nonce'], 'wp_mcp_ai_quiz_details_nonce' ) ) {
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

		// Save time limit.
		if ( isset( $_POST['wp_mcp_ai_quiz_time_limit'] ) ) {
			$time_limit = absint( $_POST['wp_mcp_ai_quiz_time_limit'] );
			update_post_meta( $post_id, '_mcp_ai_quiz_time_limit', $time_limit );
		}

		// Save passing score.
		if ( isset( $_POST['wp_mcp_ai_quiz_passing_score'] ) ) {
			$passing_score = absint( $_POST['wp_mcp_ai_quiz_passing_score'] );
			// Ensure it's between 0 and 100.
			$passing_score = max( 0, min( 100, $passing_score ) );
			update_post_meta( $post_id, '_mcp_ai_quiz_passing_score', $passing_score );
		}
	}
}
