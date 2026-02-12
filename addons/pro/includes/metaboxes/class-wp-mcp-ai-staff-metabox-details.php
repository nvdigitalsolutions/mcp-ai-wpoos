<?php
/**
 * Staff Details Metabox
 *
 * Handles role, availability, and service associations for staff members.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Calendar_Booking_Toolkit
 * @since 2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Staff Details Metabox Class
 *
 * @since 2.6.0
 */
class WP_MCP_AI_Staff_Metabox_Details extends WP_MCP_AI_Staff_Metabox_Base {

	/**
	 * Get the metabox ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_staff_details';
	}

	/**
	 * Get the metabox title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Staff Details', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the metabox priority.
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

		// Add nonce for security.
		wp_nonce_field( 'wp_mcp_ai_staff_details_nonce', 'wp_mcp_ai_staff_details_nonce' );

		// Get existing values.
		$role      = get_post_meta( $post->ID, '_staff_role', true );
		$email     = get_post_meta( $post->ID, '_staff_email', true );
		$phone     = get_post_meta( $post->ID, '_staff_phone', true );
		$available = get_post_meta( $post->ID, '_staff_available', true );
		$services  = get_post_meta( $post->ID, '_staff_services', true );
		$color     = get_post_meta( $post->ID, '_staff_color', true );

		// Default values.
		if ( empty( $available ) ) {
			$available = '1';
		}
		if ( empty( $color ) ) {
			$color = '#3498db';
		}
		if ( ! is_array( $services ) ) {
			$services = array();
		}

		// Get all services for dropdown.
		$all_services = get_posts(
			array(
				'post_type'      => 'mcp_service',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		?>
		<div class="wp-mcp-ai-staff-details-metabox">
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label for="staff_role">
								<?php esc_html_e( 'Role/Title', 'mcp-ai-wpoos-pro' ); ?>
							</label>
						</th>
						<td>
							<input 
								type="text" 
								id="staff_role" 
								name="staff_role" 
								value="<?php echo esc_attr( $role ); ?>" 
								class="regular-text"
							/>
							<p class="description">
								<?php esc_html_e( 'Staff member\'s role or job title (e.g., Therapist, Stylist, Consultant)', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="staff_email">
								<?php esc_html_e( 'Email', 'mcp-ai-wpoos-pro' ); ?>
							</label>
						</th>
						<td>
							<input 
								type="email" 
								id="staff_email" 
								name="staff_email" 
								value="<?php echo esc_attr( $email ); ?>" 
								class="regular-text"
							/>
							<p class="description">
								<?php esc_html_e( 'Staff member\'s email address for notifications', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="staff_phone">
								<?php esc_html_e( 'Phone', 'mcp-ai-wpoos-pro' ); ?>
							</label>
						</th>
						<td>
							<input 
								type="tel" 
								id="staff_phone" 
								name="staff_phone" 
								value="<?php echo esc_attr( $phone ); ?>" 
								class="regular-text"
							/>
							<p class="description">
								<?php esc_html_e( 'Staff member\'s phone number', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="staff_available">
								<?php esc_html_e( 'Availability Status', 'mcp-ai-wpoos-pro' ); ?>
							</label>
						</th>
						<td>
							<label>
								<input 
									type="checkbox" 
									id="staff_available" 
									name="staff_available" 
									value="1"
									<?php checked( $available, '1' ); ?>
								/>
								<?php esc_html_e( 'Staff member is currently available for bookings', 'mcp-ai-wpoos-pro' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Uncheck to temporarily disable bookings for this staff member (e.g., on leave, vacation)', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="staff_color">
								<?php esc_html_e( 'Calendar Color', 'mcp-ai-wpoos-pro' ); ?>
							</label>
						</th>
						<td>
							<input 
								type="color" 
								id="staff_color" 
								name="staff_color" 
								value="<?php echo esc_attr( $color ); ?>" 
							/>
							<p class="description">
								<?php esc_html_e( 'Color to identify this staff member on the calendar view', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="staff_services">
								<?php esc_html_e( 'Services Offered', 'mcp-ai-wpoos-pro' ); ?>
							</label>
						</th>
						<td>
							<?php if ( ! empty( $all_services ) ) : ?>
								<div class="staff-services-checkboxes" style="max-height: 200px; overflow-y: auto; border: 1px solid #ddd; padding: 10px;">
									<?php foreach ( $all_services as $service ) : ?>
										<label style="display: block; margin-bottom: 5px;">
											<input 
												type="checkbox" 
												name="staff_services[]" 
												value="<?php echo esc_attr( $service->ID ); ?>"
												<?php checked( in_array( (string) $service->ID, $services, true ) || in_array( $service->ID, $services, true ) ); ?>
											/>
											<?php echo esc_html( $service->post_title ); ?>
										</label>
									<?php endforeach; ?>
								</div>
								<p class="description">
									<?php esc_html_e( 'Select which services this staff member can provide', 'mcp-ai-wpoos-pro' ); ?>
								</p>
							<?php else : ?>
								<p>
									<?php
									echo wp_kses_post(
										sprintf(
											/* translators: %s: Link to add service */
											__( 'No services available. <a href="%s">Add services</a> first, then assign them to staff.', 'mcp-ai-wpoos-pro' ),
											admin_url( 'post-new.php?post_type=mcp_service' )
										)
									);
									?>
								</p>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<style>
			.wp-mcp-ai-staff-details-metabox .form-table th {
				width: 200px;
			}
		</style>
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
		// Verify nonce.
		if ( ! isset( $_POST['wp_mcp_ai_staff_details_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_staff_details_nonce'] ) ), 'wp_mcp_ai_staff_details_nonce' ) ) {
			return;
		}

		// Check permissions.
		if ( ! $this->can_edit( $post_id ) ) {
			return;
		}

		// Save role.
		if ( isset( $_POST['staff_role'] ) ) {
			$role = sanitize_text_field( wp_unslash( $_POST['staff_role'] ) );
			update_post_meta( $post_id, '_staff_role', $role );
		}

		// Save email.
		if ( isset( $_POST['staff_email'] ) ) {
			$email = sanitize_email( wp_unslash( $_POST['staff_email'] ) );
			update_post_meta( $post_id, '_staff_email', $email );
		}

		// Save phone.
		if ( isset( $_POST['staff_phone'] ) ) {
			$phone = sanitize_text_field( wp_unslash( $_POST['staff_phone'] ) );
			update_post_meta( $post_id, '_staff_phone', $phone );
		}

		// Save availability status.
		$available = isset( $_POST['staff_available'] ) ? '1' : '0';
		update_post_meta( $post_id, '_staff_available', $available );

		// Save calendar color.
		if ( isset( $_POST['staff_color'] ) ) {
			$color = sanitize_hex_color( wp_unslash( $_POST['staff_color'] ) );
			if ( $color ) {
				update_post_meta( $post_id, '_staff_color', $color );
			}
		}

		// Save services (many-to-many relationship).
		$services = isset( $_POST['staff_services'] ) && is_array( $_POST['staff_services'] )
					? array_map( 'absint', $_POST['staff_services'] )
					: array();
		update_post_meta( $post_id, '_staff_services', $services );
	}
}
