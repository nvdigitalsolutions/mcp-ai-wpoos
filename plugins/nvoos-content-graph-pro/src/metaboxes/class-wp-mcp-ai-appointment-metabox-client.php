<?php
/**
 * Calendar Booking data layer (ecosystem port — Wave F2, calendar-booking data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/metaboxes/class-wp-mcp-ai-appointment-metabox-client.php` for the standalone `nvoos-content-graph-pro`
 * addon. Kept byte-identical. The base Pro addon owns the class in
 * monolith installs — the addon's autoloader skips its copy when
 * `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; the metabox requires resolve from the
 * addon's `src/metaboxes/` copies (same batch).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Client Info metabox for appointment posts.
 *
 * Manages client details, appointment time, and duration.
 */
class WP_MCP_AI_Appointment_Metabox_Client extends WP_MCP_AI_Appointment_Metabox_Base {

	/**
	 * Get the metabox ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_appointment_client';
	}

	/**
	 * Get the metabox title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Client Information', 'nvoos-content-graph-pro' );
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
		$client_name  = get_post_meta( $post->ID, '_client_name', true );
		$client_email = get_post_meta( $post->ID, '_client_email', true );
		$client_phone = get_post_meta( $post->ID, '_client_phone', true );
		$start_time   = get_post_meta( $post->ID, '_start_time', true );
		$end_time     = get_post_meta( $post->ID, '_end_time', true );

		// Calculate duration.
		$duration = '';
		if ( ! empty( $start_time ) && ! empty( $end_time ) ) {
			$start_timestamp = strtotime( $start_time );
			$end_timestamp   = strtotime( $end_time );
			if ( $start_timestamp && $end_timestamp && $end_timestamp > $start_timestamp ) {
				$duration_minutes = ( $end_timestamp - $start_timestamp ) / 60;
				if ( $duration_minutes >= 60 ) {
					$hours   = floor( $duration_minutes / 60 );
					$minutes = $duration_minutes % 60;
					/* translators: %d: hours */
					$duration = sprintf( _n( '%d hour', '%d hours', $hours, 'nvoos-content-graph-pro' ), $hours );
					if ( $minutes > 0 ) {
						/* translators: %d: minutes */
						$duration .= ' ' . sprintf( _n( '%d minute', '%d minutes', $minutes, 'nvoos-content-graph-pro' ), $minutes );
					}
				} else {
					/* translators: %d: minutes */
					$duration = sprintf( _n( '%d minute', '%d minutes', $duration_minutes, 'nvoos-content-graph-pro' ), $duration_minutes );
				}
			}
		}

		// Format datetime-local values.
		$start_time_formatted = ! empty( $start_time ) ? gmdate( 'Y-m-d\TH:i', strtotime( $start_time ) ) : '';
		$end_time_formatted   = ! empty( $end_time ) ? gmdate( 'Y-m-d\TH:i', strtotime( $end_time ) ) : '';

		// Nonce for security.
		wp_nonce_field( 'wp_mcp_ai_appointment_client_nonce', 'wp_mcp_ai_appointment_client_nonce' );
		?>
		<div class="wp-mcp-ai-appointment-client">
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="wp_mcp_ai_client_name">
							<?php esc_html_e( 'Client Name:', 'nvoos-content-graph-pro' ); ?>
							<span class="required" style="color: #d63638;">*</span>
						</label>
					</th>
					<td>
						<input
							type="text"
							id="wp_mcp_ai_client_name"
							name="wp_mcp_ai_client_name"
							value="<?php echo esc_attr( $client_name ); ?>"
							class="regular-text"
							required
						/>
						<p class="description"><?php esc_html_e( 'Full name of the client', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="wp_mcp_ai_client_email">
							<?php esc_html_e( 'Client Email:', 'nvoos-content-graph-pro' ); ?>
							<span class="required" style="color: #d63638;">*</span>
						</label>
					</th>
					<td>
						<input
							type="email"
							id="wp_mcp_ai_client_email"
							name="wp_mcp_ai_client_email"
							value="<?php echo esc_attr( $client_email ); ?>"
							class="regular-text"
							required
						/>
						<p class="description"><?php esc_html_e( 'Valid email address for appointment confirmations', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="wp_mcp_ai_client_phone">
							<?php esc_html_e( 'Client Phone:', 'nvoos-content-graph-pro' ); ?>
						</label>
					</th>
					<td>
						<input
							type="text"
							id="wp_mcp_ai_client_phone"
							name="wp_mcp_ai_client_phone"
							value="<?php echo esc_attr( $client_phone ); ?>"
							class="regular-text"
							placeholder="<?php esc_attr_e( '+1 (555) 123-4567', 'nvoos-content-graph-pro' ); ?>"
						/>
						<p class="description"><?php esc_html_e( 'Optional contact phone number', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="wp_mcp_ai_start_time">
							<?php esc_html_e( 'Start Date/Time:', 'nvoos-content-graph-pro' ); ?>
							<span class="required" style="color: #d63638;">*</span>
						</label>
					</th>
					<td>
						<input
							type="datetime-local"
							id="wp_mcp_ai_start_time"
							name="wp_mcp_ai_start_time"
							value="<?php echo esc_attr( $start_time_formatted ); ?>"
							class="regular-text"
							required
						/>
						<p class="description"><?php esc_html_e( 'When the appointment begins', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="wp_mcp_ai_end_time">
							<?php esc_html_e( 'End Date/Time:', 'nvoos-content-graph-pro' ); ?>
							<span class="required" style="color: #d63638;">*</span>
						</label>
					</th>
					<td>
						<input
							type="datetime-local"
							id="wp_mcp_ai_end_time"
							name="wp_mcp_ai_end_time"
							value="<?php echo esc_attr( $end_time_formatted ); ?>"
							class="regular-text"
							required
						/>
						<p class="description"><?php esc_html_e( 'When the appointment ends', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>

				<?php if ( ! empty( $duration ) ) : ?>
				<tr>
					<th scope="row">
						<?php esc_html_e( 'Duration:', 'nvoos-content-graph-pro' ); ?>
					</th>
					<td>
						<p style="margin: 0; padding: 10px; background: #f0f0f1; border-radius: 4px;">
							<strong><?php echo esc_html( $duration ); ?></strong>
						</p>
						<p class="description"><?php esc_html_e( 'Calculated from start and end times', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>
				<?php endif; ?>
			</table>
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
		if ( ! isset( $_POST['wp_mcp_ai_appointment_client_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_appointment_client_nonce'] ) ), 'wp_mcp_ai_appointment_client_nonce' ) ) {
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

		// Validation errors array.
		$errors = array();

		// Save and validate client name.
		if ( isset( $_POST['wp_mcp_ai_client_name'] ) ) {
			$client_name = sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_client_name'] ) );
			if ( empty( $client_name ) ) {
				$errors[] = __( 'Client name is required.', 'nvoos-content-graph-pro' );
			} else {
				update_post_meta( $post_id, '_client_name', $client_name );
			}
		}

		// Save and validate client email.
		if ( isset( $_POST['wp_mcp_ai_client_email'] ) ) {
			$client_email = sanitize_email( wp_unslash( $_POST['wp_mcp_ai_client_email'] ) );
			if ( empty( $client_email ) ) {
				$errors[] = __( 'Client email is required.', 'nvoos-content-graph-pro' );
			} elseif ( ! is_email( $client_email ) ) {
				$errors[] = __( 'Client email is not valid.', 'nvoos-content-graph-pro' );
			} else {
				update_post_meta( $post_id, '_client_email', $client_email );
			}
		}

		// Save client phone.
		if ( isset( $_POST['wp_mcp_ai_client_phone'] ) ) {
			$client_phone = sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_client_phone'] ) );
			update_post_meta( $post_id, '_client_phone', $client_phone );
		}

		// Save and validate start time.
		$start_time_value = null;
		if ( isset( $_POST['wp_mcp_ai_start_time'] ) ) {
			$start_time = sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_start_time'] ) );
			if ( empty( $start_time ) ) {
				$errors[] = __( 'Start date/time is required.', 'nvoos-content-graph-pro' );
			} else {
				$start_time_value = gmdate( 'Y-m-d H:i:s', strtotime( $start_time ) );
				update_post_meta( $post_id, '_start_time', $start_time_value );
			}
		}

		// Save and validate end time.
		$end_time_value = null;
		if ( isset( $_POST['wp_mcp_ai_end_time'] ) ) {
			$end_time = sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_end_time'] ) );
			if ( empty( $end_time ) ) {
				$errors[] = __( 'End date/time is required.', 'nvoos-content-graph-pro' );
			} else {
				$end_time_value = gmdate( 'Y-m-d H:i:s', strtotime( $end_time ) );
				update_post_meta( $post_id, '_end_time', $end_time_value );
			}
		}

		// Validate that end time is after start time.
		if ( $start_time_value && $end_time_value ) {
			$start_timestamp = strtotime( $start_time_value );
			$end_timestamp   = strtotime( $end_time_value );
			if ( $end_timestamp <= $start_timestamp ) {
				$errors[] = __( 'End date/time must be after start date/time.', 'nvoos-content-graph-pro' );
			}
		}

		// Store errors for display.
		if ( ! empty( $errors ) ) {
			update_post_meta( $post_id, '_appointment_validation_errors', $errors );
		} else {
			delete_post_meta( $post_id, '_appointment_validation_errors' );
		}
	}
}
