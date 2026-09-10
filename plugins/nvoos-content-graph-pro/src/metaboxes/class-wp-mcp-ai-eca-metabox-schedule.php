<?php
/**
 * metaboxes/class-wp-mcp-ai-eca-metabox-schedule.php (ecosystem port — Wave F5, eca-management data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/metaboxes/class-wp-mcp-ai-eca-metabox-schedule.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps (the byte-identical `defined( 'WP_MCP_AI_PRO_VERSION' )`
 * pro-active checks stay as-is — defined-const checks, standalone-safe).
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
		return __( 'Schedule & Teachers', 'nvoos-content-graph-pro' );
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
		$day         = get_post_meta( $post->ID, '_eca_day', true );
		$start_time  = get_post_meta( $post->ID, '_eca_start_time', true );
		$end_time    = get_post_meta( $post->ID, '_eca_end_time', true );
		$teachers    = get_post_meta( $post->ID, '_eca_teachers', true );
		$year_groups = get_post_meta( $post->ID, '_eca_year_groups', true );

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
							<?php esc_html_e( 'Day of Week:', 'nvoos-content-graph-pro' ); ?>
						</label>
					</th>
					<td>
						<select id="wp_mcp_ai_eca_day" name="wp_mcp_ai_eca_day" class="regular-text">
							<option value=""><?php esc_html_e( 'Select a day', 'nvoos-content-graph-pro' ); ?></option>
							<option value="Monday" <?php selected( $day, 'Monday' ); ?>><?php esc_html_e( 'Monday', 'nvoos-content-graph-pro' ); ?></option>
							<option value="Tuesday" <?php selected( $day, 'Tuesday' ); ?>><?php esc_html_e( 'Tuesday', 'nvoos-content-graph-pro' ); ?></option>
							<option value="Wednesday" <?php selected( $day, 'Wednesday' ); ?>><?php esc_html_e( 'Wednesday', 'nvoos-content-graph-pro' ); ?></option>
							<option value="Thursday" <?php selected( $day, 'Thursday' ); ?>><?php esc_html_e( 'Thursday', 'nvoos-content-graph-pro' ); ?></option>
							<option value="Friday" <?php selected( $day, 'Friday' ); ?>><?php esc_html_e( 'Friday', 'nvoos-content-graph-pro' ); ?></option>
							<option value="Saturday" <?php selected( $day, 'Saturday' ); ?>><?php esc_html_e( 'Saturday', 'nvoos-content-graph-pro' ); ?></option>
							<option value="Sunday" <?php selected( $day, 'Sunday' ); ?>><?php esc_html_e( 'Sunday', 'nvoos-content-graph-pro' ); ?></option>
						</select>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="wp_mcp_ai_eca_start_time">
							<?php esc_html_e( 'Start Time:', 'nvoos-content-graph-pro' ); ?>
						</label>
					</th>
					<td>
						<input
							type="text"
							id="wp_mcp_ai_eca_start_time"
							name="wp_mcp_ai_eca_start_time"
							value="<?php echo esc_attr( $start_time ); ?>"
							class="regular-text"
							placeholder="<?php esc_attr_e( 'e.g., 3:30 PM', 'nvoos-content-graph-pro' ); ?>"
						/>
						<p class="description"><?php esc_html_e( 'Format: HH:MM AM/PM', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="wp_mcp_ai_eca_end_time">
							<?php esc_html_e( 'End Time:', 'nvoos-content-graph-pro' ); ?>
						</label>
					</th>
					<td>
						<input
							type="text"
							id="wp_mcp_ai_eca_end_time"
							name="wp_mcp_ai_eca_end_time"
							value="<?php echo esc_attr( $end_time ); ?>"
							class="regular-text"
							placeholder="<?php esc_attr_e( 'e.g., 4:30 PM', 'nvoos-content-graph-pro' ); ?>"
						/>
						<p class="description"><?php esc_html_e( 'Format: HH:MM AM/PM', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="wp_mcp_ai_eca_teachers">
							<?php esc_html_e( 'Teachers:', 'nvoos-content-graph-pro' ); ?>
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
						<p class="description"><?php esc_html_e( 'Comma-separated list of teacher names', 'nvoos-content-graph-pro' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row">
						<label for="wp_mcp_ai_eca_year_groups">
							<?php esc_html_e( 'Year Groups:', 'nvoos-content-graph-pro' ); ?>
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
						<p class="description"><?php esc_html_e( 'Comma-separated list of eligible year groups (e.g., Year 7, Year 8, Year 9)', 'nvoos-content-graph-pro' ); ?></p>
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
		if ( ! isset( $_POST['wp_mcp_ai_eca_schedule_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_eca_schedule_nonce'] ) ), 'wp_mcp_ai_eca_schedule_nonce' ) ) {
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
			$day        = sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_eca_day'] ) );
			$valid_days = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );
			if ( in_array( $day, $valid_days, true ) || '' === $day ) {
				update_post_meta( $post_id, '_eca_day', $day );
			}
		}

		// Save start time.
		if ( isset( $_POST['wp_mcp_ai_eca_start_time'] ) ) {
			update_post_meta( $post_id, '_eca_start_time', sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_eca_start_time'] ) ) );
		}

		// Save end time.
		if ( isset( $_POST['wp_mcp_ai_eca_end_time'] ) ) {
			update_post_meta( $post_id, '_eca_end_time', sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_eca_end_time'] ) ) );
		}

		// Save teachers (convert comma-separated string to array).
		if ( isset( $_POST['wp_mcp_ai_eca_teachers'] ) ) {
			$teachers_str = sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_eca_teachers'] ) );
			$teachers     = array_map( 'trim', explode( ',', $teachers_str ) );
			$teachers     = array_filter( $teachers ); // Remove empty values.
			update_post_meta( $post_id, '_eca_teachers', $teachers );
		}

		// Save year groups (convert comma-separated string to array).
		if ( isset( $_POST['wp_mcp_ai_eca_year_groups'] ) ) {
			$year_groups_str = sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_eca_year_groups'] ) );
			$year_groups     = array_map( 'trim', explode( ',', $year_groups_str ) );
			$year_groups     = array_filter( $year_groups ); // Remove empty values.
			update_post_meta( $post_id, '_eca_year_groups', $year_groups );
		}
	}
}
