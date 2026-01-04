<?php
/**
 * Project Metabox for managing project-specific fields.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Project Details metabox.
 */
class WP_MCP_AI_Project_Metabox {

	/**
	 * Initialize the metabox.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_metabox' ) );
		add_action( 'save_post_mcp_ai_project', array( __CLASS__, 'save_metabox' ), 10, 2 );
	}

	/**
	 * Add the metabox.
	 */
	public static function add_metabox() {
		// Check if project management is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_project_management'] ) ) {
			return;
		}

		add_meta_box(
			'wp_mcp_ai_project_details',
			__( 'Project Details', 'wp-mcp-ai' ),
			array( __CLASS__, 'render_metabox' ),
			'mcp_ai_project',
			'normal',
			'high'
		);
	}

	/**
	 * Render the metabox content.
	 *
	 * @param WP_Post $post The post object.
	 */
	public static function render_metabox( $post ) {
		// Get existing values.
		$status      = get_post_meta( $post->ID, '_project_status', true );
		$start_date  = get_post_meta( $post->ID, '_project_start_date', true );
		$end_date    = get_post_meta( $post->ID, '_project_end_date', true );
		$assigned_to = get_post_meta( $post->ID, '_project_assigned_to', true );

		// Set defaults.
		if ( empty( $status ) ) {
			$status = 'planning';
		}
		if ( ! is_array( $assigned_to ) ) {
			$assigned_to = array();
		}

		// Nonce for security.
		wp_nonce_field( 'wp_mcp_ai_project_details', 'wp_mcp_ai_project_details_nonce' );
		?>
		<div class="wp-mcp-ai-project-details">
			<p>
				<label for="project_status">
					<strong><?php esc_html_e( 'Status:', 'wp-mcp-ai' ); ?></strong>
				</label><br>
				<select id="project_status" name="project_status" class="widefat">
					<option value="planning" <?php selected( $status, 'planning' ); ?>><?php esc_html_e( 'Planning', 'wp-mcp-ai' ); ?></option>
					<option value="active" <?php selected( $status, 'active' ); ?>><?php esc_html_e( 'Active', 'wp-mcp-ai' ); ?></option>
					<option value="on-hold" <?php selected( $status, 'on-hold' ); ?>><?php esc_html_e( 'On Hold', 'wp-mcp-ai' ); ?></option>
					<option value="completed" <?php selected( $status, 'completed' ); ?>><?php esc_html_e( 'Completed', 'wp-mcp-ai' ); ?></option>
					<option value="cancelled" <?php selected( $status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'wp-mcp-ai' ); ?></option>
				</select>
			</p>

			<p>
				<label for="project_start_date">
					<strong><?php esc_html_e( 'Start Date:', 'wp-mcp-ai' ); ?></strong>
				</label><br>
				<input 
					type="date" 
					id="project_start_date" 
					name="project_start_date" 
					value="<?php echo esc_attr( $start_date ); ?>" 
					class="widefat"
				/>
			</p>

			<p>
				<label for="project_end_date">
					<strong><?php esc_html_e( 'End Date:', 'wp-mcp-ai' ); ?></strong>
				</label><br>
				<input 
					type="date" 
					id="project_end_date" 
					name="project_end_date" 
					value="<?php echo esc_attr( $end_date ); ?>" 
					class="widefat"
				/>
			</p>

			<p>
				<label for="project_assigned_to">
					<strong><?php esc_html_e( 'Assigned Team Members:', 'wp-mcp-ai' ); ?></strong>
				</label><br>
				<select id="project_assigned_to" name="project_assigned_to[]" multiple class="widefat" style="height: 150px;">
					<?php
					$users = get_users( array( 'orderby' => 'display_name' ) );
					foreach ( $users as $user ) {
						printf(
							'<option value="%d" %s>%s</option>',
							esc_attr( $user->ID ),
							selected( in_array( $user->ID, $assigned_to, true ), true, false ),
							esc_html( $user->display_name )
						);
					}
					?>
				</select>
				<span class="description"><?php esc_html_e( 'Hold Ctrl/Cmd to select multiple users', 'wp-mcp-ai' ); ?></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Save the metabox data.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_metabox( $post_id, $post ) {
		// Check nonce.
		if ( ! isset( $_POST['wp_mcp_ai_project_details_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_project_details_nonce'] ) ), 'wp_mcp_ai_project_details' ) ) {
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

		// Save status.
		if ( isset( $_POST['project_status'] ) ) {
			$status = sanitize_key( $_POST['project_status'] );
			$valid_statuses = array( 'planning', 'active', 'on-hold', 'completed', 'cancelled' );
			if ( in_array( $status, $valid_statuses, true ) ) {
				update_post_meta( $post_id, '_project_status', $status );
			}
		}

		// Save start date.
		if ( isset( $_POST['project_start_date'] ) ) {
			$start_date = sanitize_text_field( $_POST['project_start_date'] );
			if ( empty( $start_date ) || self::validate_date( $start_date ) ) {
				update_post_meta( $post_id, '_project_start_date', $start_date );
			}
		}

		// Save end date.
		if ( isset( $_POST['project_end_date'] ) ) {
			$end_date = sanitize_text_field( $_POST['project_end_date'] );
			if ( empty( $end_date ) || self::validate_date( $end_date ) ) {
				update_post_meta( $post_id, '_project_end_date', $end_date );
			}
		}

		// Save assigned users.
		if ( isset( $_POST['project_assigned_to'] ) && is_array( $_POST['project_assigned_to'] ) ) {
			$assigned_to = array_map( 'absint', $_POST['project_assigned_to'] );
			update_post_meta( $post_id, '_project_assigned_to', $assigned_to );
		} else {
			update_post_meta( $post_id, '_project_assigned_to', array() );
		}
	}

	/**
	 * Validate date format (YYYY-MM-DD).
	 *
	 * @param string $date Date string.
	 * @return bool
	 */
	private static function validate_date( $date ) {
		$d = DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}
}
