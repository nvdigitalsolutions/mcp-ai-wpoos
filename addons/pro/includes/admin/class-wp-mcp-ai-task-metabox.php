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
			__( 'Task Details', 'mcp-ai-wpoos-pro' ),
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
		$status           = get_post_meta( $post->ID, '_task_status', true );
		$priority         = get_post_meta( $post->ID, '_task_priority', true );
		$project_id       = get_post_meta( $post->ID, '_task_project_id', true );
		$due_date         = get_post_meta( $post->ID, '_task_due_date', true );
		$assigned_to      = get_post_meta( $post->ID, '_task_assigned_to', true );
		$category         = get_post_meta( $post->ID, '_task_category', true );
		$tags             = get_post_meta( $post->ID, '_task_tags', true );
		$estimated_effort = get_post_meta( $post->ID, '_task_estimated_effort', true );
		$actual_effort    = get_post_meta( $post->ID, '_task_actual_effort', true );

		// Set defaults.
		if ( empty( $status ) ) {
			$status = 'todo';
		}
		if ( empty( $priority ) ) {
			$priority = 'medium';
		}
		if ( empty( $category ) ) {
			$category = 'general';
		}

		// Nonce for security.
		wp_nonce_field( 'wp_mcp_ai_task_details', 'wp_mcp_ai_task_details_nonce' );
		?>
		<div class="wp-mcp-ai-task-details">
			<p>
				<label for="task_status">
					<strong><?php esc_html_e( 'Status:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label><br>
				<select id="task_status" name="task_status" class="widefat">
					<option value="todo" <?php selected( $status, 'todo' ); ?>><?php esc_html_e( 'To Do', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="in-progress" <?php selected( $status, 'in-progress' ); ?>><?php esc_html_e( 'In Progress', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="review" <?php selected( $status, 'review' ); ?>><?php esc_html_e( 'Review', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="completed" <?php selected( $status, 'completed' ); ?>><?php esc_html_e( 'Completed', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="cancelled" <?php selected( $status, 'cancelled' ); ?>><?php esc_html_e( 'Cancelled', 'mcp-ai-wpoos-pro' ); ?></option>
				</select>
			</p>

			<p>
				<label for="task_priority">
					<strong><?php esc_html_e( 'Priority:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label><br>
				<select id="task_priority" name="task_priority" class="widefat">
					<option value="low" <?php selected( $priority, 'low' ); ?>><?php esc_html_e( 'Low', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="medium" <?php selected( $priority, 'medium' ); ?>><?php esc_html_e( 'Medium', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="high" <?php selected( $priority, 'high' ); ?>><?php esc_html_e( 'High', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="urgent" <?php selected( $priority, 'urgent' ); ?>><?php esc_html_e( 'Urgent', 'mcp-ai-wpoos-pro' ); ?></option>
				</select>
			</p>

			<p>
				<label for="task_project_id">
					<strong><?php esc_html_e( 'Project:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label><br>
				<select id="task_project_id" name="task_project_id" class="widefat">
					<option value=""><?php esc_html_e( '-- No Project --', 'mcp-ai-wpoos-pro' ); ?></option>
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
					<strong><?php esc_html_e( 'Due Date:', 'mcp-ai-wpoos-pro' ); ?></strong>
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
					<strong><?php esc_html_e( 'Assigned To:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label><br>
				<select id="task_assigned_to" name="task_assigned_to" class="widefat">
					<option value=""><?php esc_html_e( '-- Unassigned --', 'mcp-ai-wpoos-pro' ); ?></option>
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

			<hr style="margin: 20px 0;">

			<h3 style="margin-top: 0;"><?php esc_html_e( 'Enhanced Metadata', 'mcp-ai-wpoos-pro' ); ?></h3>

			<p>
				<label for="task_category">
					<strong><?php esc_html_e( 'Category:', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label><br>
				<select id="task_category" name="task_category" class="widefat">
					<option value="general" <?php selected( $category, 'general' ); ?>><?php esc_html_e( 'General', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="bug" <?php selected( $category, 'bug' ); ?>><?php esc_html_e( 'Bug Fix', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="feature" <?php selected( $category, 'feature' ); ?>><?php esc_html_e( 'Feature', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="maintenance" <?php selected( $category, 'maintenance' ); ?>><?php esc_html_e( 'Maintenance', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="research" <?php selected( $category, 'research' ); ?>><?php esc_html_e( 'Research', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="documentation" <?php selected( $category, 'documentation' ); ?>><?php esc_html_e( 'Documentation', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="design" <?php selected( $category, 'design' ); ?>><?php esc_html_e( 'Design', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="testing" <?php selected( $category, 'testing' ); ?>><?php esc_html_e( 'Testing', 'mcp-ai-wpoos-pro' ); ?></option>
				</select>
			</p>

			<p>
				<label for="task_tags">
					<strong><?php esc_html_e( 'Tags (comma-separated):', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label><br>
				<input
					type="text"
					id="task_tags"
					name="task_tags"
					value="<?php echo esc_attr( $tags ); ?>"
					class="widefat"
					placeholder="<?php esc_attr_e( 'e.g., urgent, frontend, api', 'mcp-ai-wpoos-pro' ); ?>"
				/>
				<span class="description"><?php esc_html_e( 'Enter tags separated by commas for flexible categorization and filtering.', 'mcp-ai-wpoos-pro' ); ?></span>
			</p>

			<p>
				<label for="task_estimated_effort">
					<strong><?php esc_html_e( 'Estimated Effort (hours):', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label><br>
				<input
					type="number"
					id="task_estimated_effort"
					name="task_estimated_effort"
					value="<?php echo esc_attr( $estimated_effort ); ?>"
					class="widefat"
					min="0"
					step="0.25"
					placeholder="<?php esc_attr_e( 'e.g., 4.5', 'mcp-ai-wpoos-pro' ); ?>"
				/>
				<span class="description"><?php esc_html_e( 'Estimated time to complete this task in hours.', 'mcp-ai-wpoos-pro' ); ?></span>
			</p>

			<p>
				<label for="task_actual_effort">
					<strong><?php esc_html_e( 'Actual Effort (hours):', 'mcp-ai-wpoos-pro' ); ?></strong>
				</label><br>
				<input
					type="number"
					id="task_actual_effort"
					name="task_actual_effort"
					value="<?php echo esc_attr( $actual_effort ); ?>"
					class="widefat"
					min="0"
					step="0.25"
					placeholder="<?php esc_attr_e( 'e.g., 5.5', 'mcp-ai-wpoos-pro' ); ?>"
				/>
				<span class="description"><?php esc_html_e( 'Actual time spent on this task in hours.', 'mcp-ai-wpoos-pro' ); ?></span>
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
			$status         = sanitize_key( $_POST['task_status'] );
			$valid_statuses = array( 'todo', 'in-progress', 'review', 'completed', 'cancelled' );
			if ( in_array( $status, $valid_statuses, true ) ) {
				update_post_meta( $post_id, '_task_status', $status );
			}
		}

		// Save priority.
		if ( isset( $_POST['task_priority'] ) ) {
			$priority         = sanitize_key( $_POST['task_priority'] );
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
			$due_date = sanitize_text_field( wp_unslash( $_POST['task_due_date'] ) );
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

		// Save category.
		if ( isset( $_POST['task_category'] ) ) {
			$category         = sanitize_key( $_POST['task_category'] );
			$valid_categories = array( 'general', 'bug', 'feature', 'maintenance', 'research', 'documentation', 'design', 'testing' );
			if ( in_array( $category, $valid_categories, true ) ) {
				update_post_meta( $post_id, '_task_category', $category );
			}
		}

		// Save tags.
		if ( isset( $_POST['task_tags'] ) ) {
			$tags = sanitize_text_field( wp_unslash( $_POST['task_tags'] ) );
			update_post_meta( $post_id, '_task_tags', $tags );
		}

		// Save estimated effort.
		if ( isset( $_POST['task_estimated_effort'] ) ) {
			$estimated_effort = sanitize_text_field( wp_unslash( $_POST['task_estimated_effort'] ) );
			if ( is_numeric( $estimated_effort ) && $estimated_effort >= 0 ) {
				update_post_meta( $post_id, '_task_estimated_effort', floatval( $estimated_effort ) );
			} elseif ( empty( $estimated_effort ) ) {
				delete_post_meta( $post_id, '_task_estimated_effort' );
			}
		}

		// Save actual effort.
		if ( isset( $_POST['task_actual_effort'] ) ) {
			$actual_effort = sanitize_text_field( wp_unslash( $_POST['task_actual_effort'] ) );
			if ( is_numeric( $actual_effort ) && $actual_effort >= 0 ) {
				update_post_meta( $post_id, '_task_actual_effort', floatval( $actual_effort ) );
			} elseif ( empty( $actual_effort ) ) {
				delete_post_meta( $post_id, '_task_actual_effort' );
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
