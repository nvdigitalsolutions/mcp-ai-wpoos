<?php
/**
 * ECA Details Metabox.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the ECA Details metabox for ECA posts.
 *
 * Manages ECA code, type, venue, and basic settings.
 */
class WP_MCP_AI_ECA_Metabox_Details extends WP_MCP_AI_ECA_Metabox_Base {

	/**
	 * Get the metabox ID.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'wp_mcp_ai_eca_details';
	}

	/**
	 * Get the metabox title.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'ECA Details', 'mcp-ai-wpoos-pro' );
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
		$eca_code    = get_post_meta( $post->ID, '_eca_code', true );
		$eca_type    = get_post_meta( $post->ID, '_eca_type', true );
		$venue       = get_post_meta( $post->ID, '_eca_venue', true );
		$status      = get_post_meta( $post->ID, '_eca_status', true );
		$is_paid     = get_post_meta( $post->ID, '_eca_is_paid', true );
		$cost        = get_post_meta( $post->ID, '_eca_cost', true );
		$cost_period = get_post_meta( $post->ID, '_eca_cost_period', true );

		// Set defaults.
		if ( '' === $eca_type ) {
			$eca_type = 'club';
		}
		if ( '' === $status ) {
			$status = 'active';
		}
		if ( '' === $is_paid ) {
			$is_paid = 'no';
		}
		if ( '' === $cost_period ) {
			$cost_period = 'term';
		}

		// Nonce for security.
		wp_nonce_field( 'wp_mcp_ai_eca_details_nonce', 'wp_mcp_ai_eca_details_nonce' );
		?>
		<div class="wp-mcp-ai-eca-details">
			<p>
				<label for="wp_mcp_ai_eca_code">
					<strong><?php esc_html_e( 'ECA Code:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label>
				<input 
					type="text" 
					id="wp_mcp_ai_eca_code" 
					name="wp_mcp_ai_eca_code" 
					value="<?php echo esc_attr( $eca_code ); ?>" 
					class="widefat"
					placeholder="<?php esc_attr_e( 'e.g., ECA-001', 'mcp-ai-wpoos-pro' ); ?>"
				/>
			</p>

			<p>
				<label for="wp_mcp_ai_eca_type">
					<strong><?php esc_html_e( 'ECA Type:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label>
				<select id="wp_mcp_ai_eca_type" name="wp_mcp_ai_eca_type" class="widefat">
					<option value="club" <?php selected( $eca_type, 'club' ); ?>><?php esc_html_e( 'Club', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="society" <?php selected( $eca_type, 'society' ); ?>><?php esc_html_e( 'Society', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="sport_squad" <?php selected( $eca_type, 'sport_squad' ); ?>><?php esc_html_e( 'Sport Squad', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="sport_academy" <?php selected( $eca_type, 'sport_academy' ); ?>><?php esc_html_e( 'Sport Academy', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="activity" <?php selected( $eca_type, 'activity' ); ?>><?php esc_html_e( 'Activity', 'mcp-ai-wpoos-pro' ); ?></option>
				</select>
			</p>

			<p>
				<label for="wp_mcp_ai_eca_venue">
					<strong><?php esc_html_e( 'Venue:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label>
				<input 
					type="text" 
					id="wp_mcp_ai_eca_venue" 
					name="wp_mcp_ai_eca_venue" 
					value="<?php echo esc_attr( $venue ); ?>" 
					class="widefat"
					placeholder="<?php esc_attr_e( 'e.g., Sports Hall', 'mcp-ai-wpoos-pro' ); ?>"
				/>
			</p>

			<p>
				<label for="wp_mcp_ai_eca_status">
					<strong><?php esc_html_e( 'Status:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label>
				<select id="wp_mcp_ai_eca_status" name="wp_mcp_ai_eca_status" class="widefat">
					<option value="active" <?php selected( $status, 'active' ); ?>><?php esc_html_e( 'Active', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="inactive" <?php selected( $status, 'inactive' ); ?>><?php esc_html_e( 'Inactive', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="full" <?php selected( $status, 'full' ); ?>><?php esc_html_e( 'Full', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="cancelled" <?php selected( $status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'mcp-ai-wpoos-pro' ); ?></option>
				</select>
			</p>

			<p>
				<label>
					<input 
						type="checkbox" 
						id="wp_mcp_ai_eca_is_paid" 
						name="wp_mcp_ai_eca_is_paid" 
						value="yes"
						<?php checked( $is_paid, 'yes' ); ?>
					/>
					<strong><?php esc_html_e( 'Paid Activity', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label>
			</p>

			<div id="wp_mcp_ai_eca_cost_fields" style="<?php echo ( 'yes' === $is_paid ) ? '' : 'display:none;'; ?>">
				<p>
					<label for="wp_mcp_ai_eca_cost">
						<strong><?php esc_html_e( 'Cost:', 'mcp-ai-wpoos-pro' ); ?></strong>
					</label>
					<input 
						type="number" 
						id="wp_mcp_ai_eca_cost" 
						name="wp_mcp_ai_eca_cost" 
						value="<?php echo esc_attr( $cost ); ?>" 
						min="0"
						step="0.01"
						class="widefat"
					/>
				</p>

				<p>
					<label for="wp_mcp_ai_eca_cost_period">
						<strong><?php esc_html_e( 'Cost Period:', 'mcp-ai-wpoos-pro' ); ?></strong>
					</label>
					<select id="wp_mcp_ai_eca_cost_period" name="wp_mcp_ai_eca_cost_period" class="widefat">
						<option value="term" <?php selected( $cost_period, 'term' ); ?>><?php esc_html_e( 'Per Term', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="month" <?php selected( $cost_period, 'month' ); ?>><?php esc_html_e( 'Per Month', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="session" <?php selected( $cost_period, 'session' ); ?>><?php esc_html_e( 'Per Session', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="year" <?php selected( $cost_period, 'year' ); ?>><?php esc_html_e( 'Per Year', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
				</p>
			</div>
		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('#wp_mcp_ai_eca_is_paid').on('change', function() {
				if ($(this).is(':checked')) {
					$('#wp_mcp_ai_eca_cost_fields').show();
				} else {
					$('#wp_mcp_ai_eca_cost_fields').hide();
				}
			});
		});
		</script>
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
		if ( ! isset( $_POST['wp_mcp_ai_eca_details_nonce'] ) || ! wp_verify_nonce( $_POST['wp_mcp_ai_eca_details_nonce'], 'wp_mcp_ai_eca_details_nonce' ) ) {
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

		// Save ECA code.
		if ( isset( $_POST['wp_mcp_ai_eca_code'] ) ) {
			update_post_meta( $post_id, '_eca_code', sanitize_text_field( $_POST['wp_mcp_ai_eca_code'] ) );
		}

		// Save ECA type.
		if ( isset( $_POST['wp_mcp_ai_eca_type'] ) ) {
			$eca_type = sanitize_key( $_POST['wp_mcp_ai_eca_type'] );
			$valid_types = array( 'club', 'society', 'sport_squad', 'sport_academy', 'activity' );
			if ( in_array( $eca_type, $valid_types, true ) ) {
				update_post_meta( $post_id, '_eca_type', $eca_type );
			}
		}

		// Save venue.
		if ( isset( $_POST['wp_mcp_ai_eca_venue'] ) ) {
			update_post_meta( $post_id, '_eca_venue', sanitize_text_field( $_POST['wp_mcp_ai_eca_venue'] ) );
		}

		// Save status.
		if ( isset( $_POST['wp_mcp_ai_eca_status'] ) ) {
			$status = sanitize_key( $_POST['wp_mcp_ai_eca_status'] );
			$valid_statuses = array( 'active', 'inactive', 'full', 'cancelled' );
			if ( in_array( $status, $valid_statuses, true ) ) {
				update_post_meta( $post_id, '_eca_status', $status );
			}
		}

		// Save paid status.
		$is_paid = isset( $_POST['wp_mcp_ai_eca_is_paid'] ) ? 'yes' : 'no';
		update_post_meta( $post_id, '_eca_is_paid', $is_paid );

		// Save cost and cost period if paid.
		if ( 'yes' === $is_paid ) {
			if ( isset( $_POST['wp_mcp_ai_eca_cost'] ) ) {
				$cost = floatval( $_POST['wp_mcp_ai_eca_cost'] );
				update_post_meta( $post_id, '_eca_cost', $cost );
			}

			if ( isset( $_POST['wp_mcp_ai_eca_cost_period'] ) ) {
				$cost_period = sanitize_key( $_POST['wp_mcp_ai_eca_cost_period'] );
				$valid_periods = array( 'term', 'month', 'session', 'year' );
				if ( in_array( $cost_period, $valid_periods, true ) ) {
					update_post_meta( $post_id, '_eca_cost_period', $cost_period );
				}
			}
		}
	}
}
