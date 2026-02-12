<?php
/**
 * Service Details Metabox
 *
 * Handles duration, pricing, and buffer time configuration for services.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Calendar_Booking_Toolkit
 * @since 2.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Service Details Metabox Class
 *
 * @since 2.6.0
 */
class WP_MCP_AI_Service_Metabox_Details extends WP_MCP_AI_Service_Metabox_Base {

	/**
	 * Get the metabox ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_service_details';
	}

	/**
	 * Get the metabox title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Service Details', 'mcp-ai-wpoos-pro' );
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
		wp_nonce_field( 'wp_mcp_ai_service_details_nonce', 'wp_mcp_ai_service_details_nonce' );

		// Get existing values.
		$duration    = get_post_meta( $post->ID, '_service_duration', true );
		$price       = get_post_meta( $post->ID, '_service_price', true );
		$buffer_time = get_post_meta( $post->ID, '_service_buffer_time', true );
		$max_bookings = get_post_meta( $post->ID, '_service_max_bookings_per_day', true );
		$deposit_required = get_post_meta( $post->ID, '_service_deposit_required', true );
		$deposit_amount = get_post_meta( $post->ID, '_service_deposit_amount', true );

		?>
		<div class="wp-mcp-ai-service-details-metabox">
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label for="service_duration">
								<?php esc_html_e( 'Duration (minutes)', 'mcp-ai-wpoos-pro' ); ?>
								<span class="required" style="color: red;">*</span>
							</label>
						</th>
						<td>
							<input 
								type="number" 
								id="service_duration" 
								name="service_duration" 
								value="<?php echo esc_attr( $duration ); ?>" 
								min="1" 
								step="1" 
								class="regular-text"
								required
							/>
							<p class="description">
								<?php esc_html_e( 'How long does this service take? (e.g., 30, 60, 90)', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="service_price">
								<?php esc_html_e( 'Price', 'mcp-ai-wpoos-pro' ); ?>
							</label>
						</th>
						<td>
							<input 
								type="number" 
								id="service_price" 
								name="service_price" 
								value="<?php echo esc_attr( $price ); ?>" 
								min="0" 
								step="0.01" 
								class="regular-text"
							/>
			<p class="description">
								<?php esc_html_e( 'Service price in dollars (e.g., 50.00). Leave empty for free services.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="service_buffer_time">
								<?php esc_html_e( 'Buffer Time (minutes)', 'mcp-ai-wpoos-pro' ); ?>
							</label>
						</th>
						<td>
							<input 
								type="number" 
								id="service_buffer_time" 
								name="service_buffer_time" 
								value="<?php echo esc_attr( $buffer_time ); ?>" 
								min="0" 
								step="1" 
								class="regular-text"
							/>
							<p class="description">
								<?php esc_html_e( 'Buffer time after appointment (for cleanup, prep, travel). Default: 0', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="service_max_bookings">
								<?php esc_html_e( 'Max Bookings Per Day', 'mcp-ai-wpoos-pro' ); ?>
							</label>
						</th>
						<td>
							<input 
								type="number" 
								id="service_max_bookings" 
								name="service_max_bookings_per_day" 
								value="<?php echo esc_attr( $max_bookings ); ?>" 
								min="0" 
								step="1" 
								class="regular-text"
							/>
							<p class="description">
								<?php esc_html_e( 'Limit how many times this service can be booked per day. Leave empty for unlimited.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="service_deposit_required">
								<?php esc_html_e( 'Deposit Required', 'mcp-ai-wpoos-pro' ); ?>
							</label>
						</th>
						<td>
							<label>
								<input 
									type="checkbox" 
									id="service_deposit_required" 
									name="service_deposit_required" 
									value="1"
									<?php checked( $deposit_required, '1' ); ?>
								/>
								<?php esc_html_e( 'Require deposit payment at booking', 'mcp-ai-wpoos-pro' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'When enabled, clients must pay a deposit to confirm booking.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>

					<tr>
						<th scope="row">
							<label for="service_deposit_amount">
								<?php esc_html_e( 'Deposit Amount', 'mcp-ai-wpoos-pro' ); ?>
							</label>
						</th>
						<td>
							<input 
								type="number" 
								id="service_deposit_amount" 
								name="service_deposit_amount" 
								value="<?php echo esc_attr( $deposit_amount ); ?>" 
								min="0" 
								step="0.01" 
								class="regular-text"
							/>
							<p class="description">
								<?php esc_html_e( 'Deposit amount in dollars. If empty but deposit is required, full price will be charged.', 'mcp-ai-wpoos-pro' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<style>
			.wp-mcp-ai-service-details-metabox .form-table th {
				width: 200px;
			}
			.wp-mcp-ai-service-details-metabox .required {
				margin-left: 3px;
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
		if ( ! isset( $_POST['wp_mcp_ai_service_details_nonce'] ) || 
		     ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_service_details_nonce'] ) ), 'wp_mcp_ai_service_details_nonce' ) ) {
			return;
		}

		// Check permissions.
		if ( ! $this->can_edit( $post_id ) ) {
			return;
		}

		// Save duration (required).
		if ( isset( $_POST['service_duration'] ) ) {
			$duration = absint( $_POST['service_duration'] );
			update_post_meta( $post_id, '_service_duration', $duration );
		}

		// Save price.
		if ( isset( $_POST['service_price'] ) ) {
			$price = sanitize_text_field( wp_unslash( $_POST['service_price'] ) );
			$price = floatval( $price );
			update_post_meta( $post_id, '_service_price', $price );
		}

		// Save buffer time.
		if ( isset( $_POST['service_buffer_time'] ) ) {
			$buffer = absint( $_POST['service_buffer_time'] );
			update_post_meta( $post_id, '_service_buffer_time', $buffer );
		}

		// Save max bookings per day.
		if ( isset( $_POST['service_max_bookings_per_day'] ) ) {
			$max_bookings = absint( $_POST['service_max_bookings_per_day'] );
			update_post_meta( $post_id, '_service_max_bookings_per_day', $max_bookings );
		}

		// Save deposit required.
		$deposit_required = isset( $_POST['service_deposit_required'] ) ? '1' : '0';
		update_post_meta( $post_id, '_service_deposit_required', $deposit_required );

		// Save deposit amount.
		if ( isset( $_POST['service_deposit_amount'] ) ) {
			$deposit_amount = sanitize_text_field( wp_unslash( $_POST['service_deposit_amount'] ) );
			$deposit_amount = floatval( $deposit_amount );
			update_post_meta( $post_id, '_service_deposit_amount', $deposit_amount );
		}
	}
}
