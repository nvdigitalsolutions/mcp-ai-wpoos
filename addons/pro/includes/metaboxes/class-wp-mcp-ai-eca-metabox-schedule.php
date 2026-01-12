<?php
/**
 * ECA Schedule Metabox.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the ECA Schedule metabox for ECA posts.
 *
 * Manages day, time, teachers, and year groups.
 */
class WP_MCP_AI_ECA_Metabox_Schedule extends WP_MCP_AI_ECA_Metabox_Base {

	/**
	 * Get the metabox ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_eca_schedule';
	}

	/**
	 * Get the metabox title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'Schedule & Teachers', 'mcp-ai-wpoos-pro' );
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
		$day           = get_post_meta( $post->ID, '_eca_day', true );
		$start_time    = get_post_meta( $post->ID, '_eca_start_time', true );
		$end_time      = get_post_meta( $post->ID, '_eca_end_time', true );
		$teachers      = get_post_meta( $post->ID, '_eca_teachers', true );
		$year_groups   = get_post_meta( $post->ID, '_eca_year_groups', true );

		// Convert arrays to strings for display.
		$teachers_str    = is_array( $teachers ) ? implode( ', ', $teachers ) : '';
		$year_groups_str = is_array( $year_groups ) ? implode( ', ', $year_groups ) : '';

		// Nonce for security.
		wp_nonce_field( 'wp_mcp_ai_eca_schedule_nonce', 'wp_mcp_ai_eca_schedule_nonce' );
		?>
		<div class="wp-mcp-ai-eca-schedule">
			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="wp_mcp_ai_eca_day">
							<?php esc_html_e( 'Day of Week:', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</th>
					<td>
						<select id="wp_mcp_ai_eca_day" name="wp_mcp_ai_eca_day" class="regular-text">
							<option value=""><?php esc_html_e( 'Select a day', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="Monday" <?php selected( $day, 'Monday' ); ?>><?php esc_html_e( 'Monday', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="Tuesday" <?php selected( $day, 'Tuesday' ); ?>><?php esc_html_e( 'Tuesday', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="Wednesday" <?php selected( $day, 'Wednesday' ); ?>><?php esc_html_e( 'Wednesday', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="Thursday" <?php selected( $day, 'Thursday' ); ?>><?php esc_html_e( 'Thursday', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="Friday" <?php selected( $day, 'Friday' ); ?>><?php esc_html_e( 'Friday', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="Saturday" <?php selected( $day, 'Saturday' ); ?>><?php esc_html_e( 'Saturday', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="Sunday" <?php selected( $day, 'Sunday' ); ?>><?php esc_html_e( 'Sunday', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="wp_mcp_ai_eca_start_time">
							<?php esc_html_e( 'Start Time:', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</th>
					<td>
						<input 
							type="text" 
							id="wp_mcp_ai_eca_start_time" 
							name="wp_mcp_ai_eca_start_time" 
							value="<?php echo esc_attr( $start_time ); ?>" 
							class="regular-text"
							placeholder="<?php esc_attr_e( 'e.g., 3:30 PM', 'mcp-ai-wpoos-pro' ); ?>"
						/>
						<p class="description"><?php esc_html_e( 'Format: HH:MM AM/PM', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="wp_mcp_ai_eca_end_time">
							<?php esc_html_e( 'End Time:', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</th>
					<td>
						<input 
							type="text" 
							id="wp_mcp_ai_eca_end_time" 
							name="wp_mcp_ai_eca_end_time" 
							value="<?php echo esc_attr( $end_time ); ?>" 
							class="regular-text"
							placeholder="<?php esc_attr_e( 'e.g., 4:30 PM', 'mcp-ai-wpoos-pro' ); ?>"
						/>
						<p class="description"><?php esc_html_e( 'Format: HH:MM AM/PM', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="wp_mcp_ai_eca_teachers">
							<?php esc_html_e( 'Teachers:', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</th>
					<td>
						<input 
							type="text" 
							id="wp_mcp_ai_eca_teachers" 
							name="wp_mcp_ai_eca_teachers" 
							value="<?php echo esc_attr( $teachers_str ); ?>" 
							class="regular-text"
						/>
						<p class="description"><?php esc_html_e( 'Comma-separated list of teacher names', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="wp_mcp_ai_eca_year_groups">
							<?php esc_html_e( 'Year Groups:', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</th>
					<td>
						<input 
							type="text" 
							id="wp_mcp_ai_eca_year_groups" 
							name="wp_mcp_ai_eca_year_groups" 
							value="<?php echo esc_attr( $year_groups_str ); ?>" 
							class="regular-text"
						/>
						<p class="description"><?php esc_html_e( 'Comma-separated list of eligible year groups (e.g., Year 7, Year 8, Year 9)', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
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
		if ( ! isset( $_POST['wp_mcp_ai_eca_schedule_nonce'] ) || ! wp_verify_nonce( $_POST['wp_mcp_ai_eca_schedule_nonce'], 'wp_mcp_ai_eca_schedule_nonce' ) ) {
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

		// Save day.
		if ( isset( $_POST['wp_mcp_ai_eca_day'] ) ) {
			$day = sanitize_text_field( $_POST['wp_mcp_ai_eca_day'] );
			$valid_days = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );
			if ( in_array( $day, $valid_days, true ) || '' === $day ) {
				update_post_meta( $post_id, '_eca_day', $day );
			}
		}

		// Save start time.
		if ( isset( $_POST['wp_mcp_ai_eca_start_time'] ) ) {
			update_post_meta( $post_id, '_eca_start_time', sanitize_text_field( $_POST['wp_mcp_ai_eca_start_time'] ) );
		}

		// Save end time.
		if ( isset( $_POST['wp_mcp_ai_eca_end_time'] ) ) {
			update_post_meta( $post_id, '_eca_end_time', sanitize_text_field( $_POST['wp_mcp_ai_eca_end_time'] ) );
		}

		// Save teachers (convert comma-separated string to array).
		if ( isset( $_POST['wp_mcp_ai_eca_teachers'] ) ) {
			$teachers_str = sanitize_text_field( $_POST['wp_mcp_ai_eca_teachers'] );
			$teachers = array_map( 'trim', explode( ',', $teachers_str ) );
			$teachers = array_filter( $teachers ); // Remove empty values.
			update_post_meta( $post_id, '_eca_teachers', $teachers );
		}

		// Save year groups (convert comma-separated string to array).
		if ( isset( $_POST['wp_mcp_ai_eca_year_groups'] ) ) {
			$year_groups_str = sanitize_text_field( $_POST['wp_mcp_ai_eca_year_groups'] );
			$year_groups = array_map( 'trim', explode( ',', $year_groups_str ) );
			$year_groups = array_filter( $year_groups ); // Remove empty values.
			update_post_meta( $post_id, '_eca_year_groups', $year_groups );
		}
	}
}
