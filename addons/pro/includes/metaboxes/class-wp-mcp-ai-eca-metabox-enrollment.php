<?php
/**
 * ECA Enrollment Metabox.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the ECA Enrollment metabox for ECA posts.
 *
 * Manages capacity, enrollment settings, and audition requirements.
 */
class WP_MCP_AI_ECA_Metabox_Enrollment extends WP_MCP_AI_ECA_Metabox_Base {

	/**
	 * Get the metabox ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_eca_enrollment';
	}

	/**
	 * Get the metabox title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Enrollment Settings', 'mcp-ai-wpoos-pro' );
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
		$max_students       = get_post_meta( $post->ID, '_eca_max_students', true );
		$current_enrollment = get_post_meta( $post->ID, '_eca_current_enrollment', true );
		$requires_audition  = get_post_meta( $post->ID, '_eca_requires_audition', true );
		$booking_type       = get_post_meta( $post->ID, '_eca_booking_type', true );

		// Set defaults.
		if ( '' === $max_students ) {
			$max_students = 0;
		}
		if ( '' === $current_enrollment ) {
			$current_enrollment = 0;
		}
		if ( '' === $requires_audition ) {
			$requires_audition = 'no';
		}
		if ( '' === $booking_type ) {
			$booking_type = 'first_come_first_served';
		}

		// Calculate available spots.
		$available_spots = $max_students > 0 ? max( 0, $max_students - $current_enrollment ) : __( 'Unlimited', 'mcp-ai-wpoos-pro' );

		// Nonce for security.
		wp_nonce_field( 'wp_mcp_ai_eca_enrollment_nonce', 'wp_mcp_ai_eca_enrollment_nonce' );
		?>
		<div class="wp-mcp-ai-eca-enrollment">
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="wp_mcp_ai_eca_max_students">
							<?php esc_html_e( 'Maximum Students:', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</th>
					<td>
						<input 
							type="number" 
							id="wp_mcp_ai_eca_max_students" 
							name="wp_mcp_ai_eca_max_students" 
							value="<?php echo esc_attr( $max_students ); ?>" 
							min="0"
							step="1"
							class="regular-text"
						/>
						<p class="description"><?php esc_html_e( 'Set to 0 for unlimited capacity', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<?php esc_html_e( 'Current Enrollment:', 'mcp-ai-wpoos-pro' ); ?>
					</th>
					<td>
						<strong><?php echo esc_html( $current_enrollment ); ?></strong>
						<?php if ( $max_students > 0 ) : ?>
							/ <?php echo esc_html( $max_students ); ?>
						<?php endif; ?>
						<p class="description">
							<?php
							if ( is_numeric( $available_spots ) ) {
								/* translators: %d: Number of available spots */
								printf( esc_html__( '%d spots available', 'mcp-ai-wpoos-pro' ), (int) $available_spots );
							} else {
								echo esc_html( $available_spots );
							}
							?>
						</p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="wp_mcp_ai_eca_booking_type">
							<?php esc_html_e( 'Booking Type:', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</th>
					<td>
						<select id="wp_mcp_ai_eca_booking_type" name="wp_mcp_ai_eca_booking_type" class="regular-text">
							<option value="first_come_first_served" <?php selected( $booking_type, 'first_come_first_served' ); ?>>
								<?php esc_html_e( 'First Come First Served', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="preference_based" <?php selected( $booking_type, 'preference_based' ); ?>>
								<?php esc_html_e( 'Preference Based', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="preselected" <?php selected( $booking_type, 'preselected' ); ?>>
								<?php esc_html_e( 'Preselected', 'mcp-ai-wpoos-pro' ); ?>
							</option>
							<option value="signup" <?php selected( $booking_type, 'signup' ); ?>>
								<?php esc_html_e( 'Sign Up', 'mcp-ai-wpoos-pro' ); ?>
							</option>
						</select>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<?php esc_html_e( 'Audition Required:', 'mcp-ai-wpoos-pro' ); ?>
					</th>
					<td>
						<label>
							<input 
								type="checkbox" 
								id="wp_mcp_ai_eca_requires_audition" 
								name="wp_mcp_ai_eca_requires_audition" 
								value="yes"
								<?php checked( $requires_audition, 'yes' ); ?>
							/>
							<?php esc_html_e( 'This ECA requires an audition or tryout', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
			</table>

			<?php if ( $current_enrollment > 0 ) : ?>
				<div style="margin-top: 20px; padding: 10px; background: #f0f0f1; border-left: 4px solid #2271b1;">
					<p>
						<strong><?php esc_html_e( 'Note:', 'mcp-ai-wpoos-pro' ); ?></strong>
						<?php esc_html_e( 'Current enrollment count is managed automatically when students are enrolled via the enrollment tools. Manual adjustments should be made carefully.', 'mcp-ai-wpoos-pro' ); ?>
					</p>
				</div>
			<?php endif; ?>
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
		if ( ! isset( $_POST['wp_mcp_ai_eca_enrollment_nonce'] ) || ! wp_verify_nonce( $_POST['wp_mcp_ai_eca_enrollment_nonce'], 'wp_mcp_ai_eca_enrollment_nonce' ) ) {
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

		// Save max students.
		if ( isset( $_POST['wp_mcp_ai_eca_max_students'] ) ) {
			$max_students = absint( $_POST['wp_mcp_ai_eca_max_students'] );
			update_post_meta( $post_id, '_eca_max_students', $max_students );
		}

		// Save booking type.
		if ( isset( $_POST['wp_mcp_ai_eca_booking_type'] ) ) {
			$booking_type = sanitize_key( $_POST['wp_mcp_ai_eca_booking_type'] );
			$valid_types = array( 'first_come_first_served', 'preference_based', 'preselected', 'signup' );
			if ( in_array( $booking_type, $valid_types, true ) ) {
				update_post_meta( $post_id, '_eca_booking_type', $booking_type );
			}
		}

		// Save audition requirement.
		$requires_audition = isset( $_POST['wp_mcp_ai_eca_requires_audition'] ) ? 'yes' : 'no';
		update_post_meta( $post_id, '_eca_requires_audition', $requires_audition );

		// Note: current_enrollment is NOT saved here - it's managed by enrollment tools
		// to prevent manual tampering.
	}
}
