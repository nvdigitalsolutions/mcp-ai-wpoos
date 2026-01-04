<?php
/**
 * Task Metabox for managing task-specific fields.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Task Details metabox.
 */
class WP_MCP_AI_Task_Metabox {

	/**
	 * Initialize the metabox.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_metabox' ) );
		add_action( 'save_post_mcp_ai_task', array( __CLASS__, 'save_metabox' ), 10, 2 );
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
			'wp_mcp_ai_task_details',
			__( 'Task Details', 'wp-mcp-ai' ),
			array( __CLASS__, 'render_metabox' ),
			'mcp_ai_task',
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
		$status      = get_post_meta( $post->ID, '_task_status', true );
		$priority    = get_post_meta( $post->ID, '_task_priority', true );
		$project_id  = get_post_meta( $post->ID, '_task_project_id', true );
		$due_date    = get_post_meta( $post->ID, '_task_due_date', true );
		$assigned_to = get_post_meta( $post->ID, '_task_assigned_to', true );

		// Set defaults.
		if ( empty( $status ) ) {
			$status = 'todo';
		}
		if ( empty( $priority ) ) {
			$priority = 'medium';
		}

		// Nonce for security.
		wp_nonce_field( 'wp_mcp_ai_task_details', 'wp_mcp_ai_task_details_nonce' );
		?>
		<div class="wp-mcp-ai-task-details">
			<p>
				<label for="task_status">
					<strong><?php esc_html_e( 'Status:', 'wp-mcp-ai' ); ?></strong>
				</label><br>
				<select id="task_status" name="task_status" class="widefat">
					<option value="todo" <?php selected( $status, 'todo' ); ?>><?php esc_html_e( 'To Do', 'wp-mcp-ai' ); ?></option>
					<option value="in-progress" <?php selected( $status, 'in-progress' ); ?>><?php esc_html_e( 'In Progress', 'wp-mcp-ai' ); ?></option>
					<option value="review" <?php selected( $status, 'review' ); ?>><?php esc_html_e( 'Review', 'wp-mcp-ai' ); ?></option>
					<option value="completed" <?php selected( $status, 'completed' ); ?>><?php esc_html_e( 'Completed', 'wp-mcp-ai' ); ?></option>
					<option value="cancelled" <?php selected( $status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'wp-mcp-ai' ); ?></option>
				</select>
			</p>

			<p>
				<label for="task_priority">
					<strong><?php esc_html_e( 'Priority:', 'wp-mcp-ai' ); ?></strong>
				</label><br>
				<select id="task_priority" name="task_priority" class="widefat">
					<option value="low" <?php selected( $priority, 'low' ); ?>><?php esc_html_e( 'Low', 'wp-mcp-ai' ); ?></option>
					<option value="medium" <?php selected( $priority, 'medium' ); ?>><?php esc_html_e( 'Medium', 'wp-mcp-ai' ); ?></option>
					<option value="high" <?php selected( $priority, 'high' ); ?>><?php esc_html_e( 'High', 'wp-mcp-ai' ); ?></option>
					<option value="urgent" <?php selected( $priority, 'urgent' ); ?>><?php esc_html_e( 'Urgent', 'wp-mcp-ai' ); ?></option>
				</select>
			</p>

			<p>
				<label for="task_project_id">
					<strong><?php esc_html_e( 'Project:', 'wp-mcp-ai' ); ?></strong>
				</label><br>
				<select id="task_project_id" name="task_project_id" class="widefat">
					<option value=""><?php esc_html_e( '-- No Project --', 'wp-mcp-ai' ); ?></option>
					<?php
					$projects = get_posts(
						array(
							'post_type'      => 'mcp_ai_project',
							'posts_per_page' => -1,
							'orderby'        => 'title',
							'order'          => 'ASC',
							'post_status'    => 'publish',
						)
					);
					foreach ( $projects as $project ) {
						printf(
							'<option value="%d" %s>%s</option>',
							esc_attr( $project->ID ),
							selected( $project_id, $project->ID, false ),
							esc_html( $project->post_title )
						);
					}
					?>
				</select>
			</p>

			<p>
				<label for="task_due_date">
					<strong><?php esc_html_e( 'Due Date:', 'wp-mcp-ai' ); ?></strong>
				</label><br>
				<input 
					type="date" 
					id="task_due_date" 
					name="task_due_date" 
					value="<?php echo esc_attr( $due_date ); ?>" 
					class="widefat"
				/>
			</p>

			<p>
				<label for="task_assigned_to">
					<strong><?php esc_html_e( 'Assigned To:', 'wp-mcp-ai' ); ?></strong>
				</label><br>
				<select id="task_assigned_to" name="task_assigned_to" class="widefat">
					<option value=""><?php esc_html_e( '-- Unassigned --', 'wp-mcp-ai' ); ?></option>
					<?php
					$users = get_users( array( 'orderby' => 'display_name' ) );
					foreach ( $users as $user ) {
						printf(
							'<option value="%d" %s>%s</option>',
							esc_attr( $user->ID ),
							selected( $assigned_to, $user->ID, false ),
							esc_html( $user->display_name )
						);
					}
					?>
				</select>
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
		if ( ! isset( $_POST['wp_mcp_ai_task_details_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_task_details_nonce'] ) ), 'wp_mcp_ai_task_details' ) ) {
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
		if ( isset( $_POST['task_status'] ) ) {
			$status = sanitize_key( $_POST['task_status'] );
			$valid_statuses = array( 'todo', 'in-progress', 'review', 'completed', 'cancelled' );
			if ( in_array( $status, $valid_statuses, true ) ) {
				update_post_meta( $post_id, '_task_status', $status );
			}
		}

		// Save priority.
		if ( isset( $_POST['task_priority'] ) ) {
			$priority = sanitize_key( $_POST['task_priority'] );
			$valid_priorities = array( 'low', 'medium', 'high', 'urgent' );
			if ( in_array( $priority, $valid_priorities, true ) ) {
				update_post_meta( $post_id, '_task_priority', $priority );
			}
		}

		// Save project ID.
		if ( isset( $_POST['task_project_id'] ) ) {
			$project_id = absint( $_POST['task_project_id'] );
			if ( $project_id > 0 ) {
				$project = get_post( $project_id );
				if ( $project && 'mcp_ai_project' === $project->post_type ) {
					update_post_meta( $post_id, '_task_project_id', $project_id );
				}
			} else {
				delete_post_meta( $post_id, '_task_project_id' );
			}
		}

		// Save due date.
		if ( isset( $_POST['task_due_date'] ) ) {
			$due_date = sanitize_text_field( $_POST['task_due_date'] );
			if ( empty( $due_date ) || self::validate_date( $due_date ) ) {
				update_post_meta( $post_id, '_task_due_date', $due_date );
			}
		}

		// Save assigned user.
		if ( isset( $_POST['task_assigned_to'] ) ) {
			$assigned_to = absint( $_POST['task_assigned_to'] );
			if ( $assigned_to > 0 ) {
				$user = get_user_by( 'id', $assigned_to );
				if ( $user ) {
					update_post_meta( $post_id, '_task_assigned_to', $assigned_to );
				}
			} else {
				delete_post_meta( $post_id, '_task_assigned_to' );
			}
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
