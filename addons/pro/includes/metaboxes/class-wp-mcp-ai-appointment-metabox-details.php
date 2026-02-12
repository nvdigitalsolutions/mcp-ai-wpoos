<?php
/**
 * Appointment Details Metabox.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Appointment Details metabox for appointment posts.
 *
 * Manages appointment type, status, location, and metadata.
 */
class WP_MCP_AI_Appointment_Metabox_Details extends WP_MCP_AI_Appointment_Metabox_Base {

	/**
	 * Get the metabox ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_appointment_details';
	}

	/**
	 * Get the metabox title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Appointment Details', 'mcp-ai-wpoos-pro' );
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
		$appointment_type = get_post_meta( $post->ID, '_appointment_type', true );
		$status           = get_post_meta( $post->ID, '_status', true );
		$location         = get_post_meta( $post->ID, '_location', true );
		$service_id       = get_post_meta( $post->ID, '_appointment_service_id', true );
		$staff_id         = get_post_meta( $post->ID, '_appointment_staff_id', true );

		// Set defaults.
		if ( empty( $appointment_type ) ) {
			$appointment_type = 'consultation';
		}
		if ( empty( $status ) ) {
			$status = 'scheduled';
		}

		// Get all services and staff for dropdowns.
		$services = get_posts(
			array(
				'post_type'      => 'mcp_service',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$staff_members = get_posts(
			array(
				'post_type'      => 'mcp_staff',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		// Get author and creation date.
		$created_by = get_userdata( $post->post_author );
		$created_at = get_the_date( 'Y-m-d H:i:s', $post );

		// Nonce for security.
		wp_nonce_field( 'wp_mcp_ai_appointment_details_nonce', 'wp_mcp_ai_appointment_details_nonce' );
		?>
		<div class="wp-mcp-ai-appointment-details">
			<p>
				<label for="wp_mcp_ai_appointment_type">
					<strong><?php esc_html_e( 'Appointment Type:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label>
				<select
					id="wp_mcp_ai_appointment_type"
					name="wp_mcp_ai_appointment_type"
					class="widefat"
				>
					<option value="consultation" <?php selected( $appointment_type, 'consultation' ); ?>><?php esc_html_e( 'Consultation', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="meeting" <?php selected( $appointment_type, 'meeting' ); ?>><?php esc_html_e( 'Meeting', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="service" <?php selected( $appointment_type, 'service' ); ?>><?php esc_html_e( 'Service', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="interview" <?php selected( $appointment_type, 'interview' ); ?>><?php esc_html_e( 'Interview', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="follow-up" <?php selected( $appointment_type, 'follow-up' ); ?>><?php esc_html_e( 'Follow-up', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="other" <?php selected( $appointment_type, 'other' ); ?>><?php esc_html_e( 'Other', 'mcp-ai-wpoos-pro' ); ?></option>
				</select>
				<span class="description"><?php esc_html_e( 'Select the type of appointment', 'mcp-ai-wpoos-pro' ); ?></span>
			</p>

			<p>
				<label for="wp_mcp_ai_appointment_status">
					<strong><?php esc_html_e( 'Status:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label>
				<select
					id="wp_mcp_ai_appointment_status"
					name="wp_mcp_ai_appointment_status"
					class="widefat"
				>
					<option value="scheduled" <?php selected( $status, 'scheduled' ); ?>><?php esc_html_e( 'Scheduled', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="confirmed" <?php selected( $status, 'confirmed' ); ?>><?php esc_html_e( 'Confirmed', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="completed" <?php selected( $status, 'completed' ); ?>><?php esc_html_e( 'Completed', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="cancelled" <?php selected( $status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="no-show" <?php selected( $status, 'no-show' ); ?>><?php esc_html_e( 'No-show', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="rescheduled" <?php selected( $status, 'rescheduled' ); ?>><?php esc_html_e( 'Rescheduled', 'mcp-ai-wpoos-pro' ); ?></option>
				</select>
				<span class="description"><?php esc_html_e( 'Current appointment status', 'mcp-ai-wpoos-pro' ); ?></span>
			</p>

			<p>
				<label for="wp_mcp_ai_appointment_location">
					<strong><?php esc_html_e( 'Location/Link:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label>
				<input
					type="text"
					id="wp_mcp_ai_appointment_location"
					name="wp_mcp_ai_appointment_location"
					value="<?php echo esc_attr( $location ); ?>"
					class="widefat"
					placeholder="<?php esc_attr_e( 'Physical address or video call link', 'mcp-ai-wpoos-pro' ); ?>"
				/>
				<span class="description"><?php esc_html_e( 'Meeting location or video conference URL', 'mcp-ai-wpoos-pro' ); ?></span>
			</p>

			<p>
				<label for="wp_mcp_ai_appointment_service">
					<strong><?php esc_html_e( 'Service:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label>
				<select
					id="wp_mcp_ai_appointment_service"
					name="wp_mcp_ai_appointment_service_id"
					class="widefat"
				>
					<option value=""><?php esc_html_e( '— Select Service —', 'mcp-ai-wpoos-pro' ); ?></option>
					<?php foreach ( $services as $service ) : ?>
						<option value="<?php echo esc_attr( $service->ID ); ?>" <?php selected( $service_id, $service->ID ); ?>>
							<?php echo esc_html( $service->post_title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<span class="description"><?php esc_html_e( 'Select the service for this appointment', 'mcp-ai-wpoos-pro' ); ?></span>
			</p>

			<p>
				<label for="wp_mcp_ai_appointment_staff">
					<strong><?php esc_html_e( 'Staff Member:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label>
				<select
					id="wp_mcp_ai_appointment_staff"
					name="wp_mcp_ai_appointment_staff_id"
					class="widefat"
				>
					<option value=""><?php esc_html_e( '— Select Staff —', 'mcp-ai-wpoos-pro' ); ?></option>
					<?php foreach ( $staff_members as $staff ) : ?>
						<option value="<?php echo esc_attr( $staff->ID ); ?>" <?php selected( $staff_id, $staff->ID ); ?>>
							<?php echo esc_html( $staff->post_title ); ?>
						</option>
					<?php endforeach; ?>
				</select>
				<span class="description"><?php esc_html_e( 'Assign staff member to this appointment', 'mcp-ai-wpoos-pro' ); ?></span>
			</p>

			<hr style="margin: 15px 0; border: none; border-top: 1px solid #dcdcde;">

			<p>
				<strong><?php esc_html_e( 'Created By:', 'mcp-ai-wpoos-pro' ); ?></strong><br>
				<span class="description">
					<?php
					if ( $created_by ) {
						echo esc_html( $created_by->display_name );
					} else {
						esc_html_e( 'Unknown', 'mcp-ai-wpoos-pro' );
					}
					?>
				</span>
			</p>

			<p>
				<strong><?php esc_html_e( 'Created At:', 'mcp-ai-wpoos-pro' ); ?></strong><br>
				<span class="description">
					<?php
					if ( $post->ID && $created_at ) {
						echo esc_html( $created_at );
					} else {
						esc_html_e( 'Not saved yet', 'mcp-ai-wpoos-pro' );
					}
					?>
				</span>
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
		if ( ! isset( $_POST['wp_mcp_ai_appointment_details_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_appointment_details_nonce'] ) ), 'wp_mcp_ai_appointment_details_nonce' ) ) {
			return;
		}

		// Check autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check permissions.
		if ( ! $this->can_edit( $post_id ) ) {
			return;
		}

		// Save appointment type.
		if ( isset( $_POST['wp_mcp_ai_appointment_type'] ) ) {
			$appointment_type = sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_appointment_type'] ) );
			$allowed_types    = array( 'consultation', 'meeting', 'service', 'interview', 'follow-up', 'other' );
			if ( in_array( $appointment_type, $allowed_types, true ) ) {
				update_post_meta( $post_id, '_appointment_type', $appointment_type );
			}
		}

		// Save status.
		if ( isset( $_POST['wp_mcp_ai_appointment_status'] ) ) {
			$status         = sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_appointment_status'] ) );
			$allowed_status = array( 'scheduled', 'confirmed', 'completed', 'cancelled', 'no-show', 'rescheduled' );
			if ( in_array( $status, $allowed_status, true ) ) {
				update_post_meta( $post_id, '_status', $status );
			}
		}

		// Save location.
		if ( isset( $_POST['wp_mcp_ai_appointment_location'] ) ) {
			$location = sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_appointment_location'] ) );
			update_post_meta( $post_id, '_location', $location );
		}

		// Save service ID.
		if ( isset( $_POST['wp_mcp_ai_appointment_service_id'] ) ) {
			$service_id = absint( $_POST['wp_mcp_ai_appointment_service_id'] );
			if ( $service_id > 0 ) {
				update_post_meta( $post_id, '_appointment_service_id', $service_id );
			} else {
				delete_post_meta( $post_id, '_appointment_service_id' );
			}
		}

		// Save staff ID.
		if ( isset( $_POST['wp_mcp_ai_appointment_staff_id'] ) ) {
			$staff_id = absint( $_POST['wp_mcp_ai_appointment_staff_id'] );
			if ( $staff_id > 0 ) {
				update_post_meta( $post_id, '_appointment_staff_id', $staff_id );
			} else {
				delete_post_meta( $post_id, '_appointment_staff_id' );
			}
		}
	}
}
