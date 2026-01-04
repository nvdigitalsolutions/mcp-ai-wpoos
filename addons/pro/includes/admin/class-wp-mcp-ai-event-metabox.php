<?php
/**
 * Event Metabox for managing event-specific fields.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the Event Details metabox.
 */
class WP_MCP_AI_Event_Metabox {

	/**
	 * Initialize the metabox.
	 */
	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_metabox' ) );
		add_action( 'save_post_mcp_ai_event', array( __CLASS__, 'save_metabox' ), 10, 2 );
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
			'wp_mcp_ai_event_details',
			__( 'Event Details', 'wp-mcp-ai' ),
			array( __CLASS__, 'render_metabox' ),
			'mcp_ai_event',
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
		$start_date  = get_post_meta( $post->ID, '_event_start_date', true );
		$start_time  = get_post_meta( $post->ID, '_event_start_time', true );
		$end_date    = get_post_meta( $post->ID, '_event_end_date', true );
		$end_time    = get_post_meta( $post->ID, '_event_end_time', true );
		$all_day     = get_post_meta( $post->ID, '_event_all_day', true );
		$location    = get_post_meta( $post->ID, '_event_location', true );
		$type        = get_post_meta( $post->ID, '_event_type', true );
		$project_id  = get_post_meta( $post->ID, '_event_project_id', true );
		$attendees   = get_post_meta( $post->ID, '_event_attendees', true );

		// Set defaults.
		if ( empty( $type ) ) {
			$type = 'meeting';
		}
		if ( ! is_array( $attendees ) ) {
			$attendees = array();
		}

		// Nonce for security.
		wp_nonce_field( 'wp_mcp_ai_event_details', 'wp_mcp_ai_event_details_nonce' );
		?>
		<div class="wp-mcp-ai-event-details">
			<p>
				<label for="event_type">
					<strong><?php esc_html_e( 'Event Type:', 'wp-mcp-ai' ); ?></strong>
				</label><br>
				<select id="event_type" name="event_type" class="widefat">
					<option value="meeting" <?php selected( $type, 'meeting' ); ?>><?php esc_html_e( 'Meeting', 'wp-mcp-ai' ); ?></option>
					<option value="deadline" <?php selected( $type, 'deadline' ); ?>><?php esc_html_e( 'Deadline', 'wp-mcp-ai' ); ?></option>
					<option value="milestone" <?php selected( $type, 'milestone' ); ?>><?php esc_html_e( 'Milestone', 'wp-mcp-ai' ); ?></option>
					<option value="reminder" <?php selected( $type, 'reminder' ); ?>><?php esc_html_e( 'Reminder', 'wp-mcp-ai' ); ?></option>
					<option value="other" <?php selected( $type, 'other' ); ?>><?php esc_html_e( 'Other', 'wp-mcp-ai' ); ?></option>
				</select>
			</p>

			<p>
				<label>
					<input type="checkbox" id="event_all_day" name="event_all_day" value="1" <?php checked( $all_day, '1' ); ?> />
					<strong><?php esc_html_e( 'All-day event', 'wp-mcp-ai' ); ?></strong>
				</label>
			</p>

			<div style="display: flex; gap: 10px;">
				<div style="flex: 1;">
					<p>
						<label for="event_start_date">
							<strong><?php esc_html_e( 'Start Date:', 'wp-mcp-ai' ); ?></strong>
						</label><br>
						<input 
							type="date" 
							id="event_start_date" 
							name="event_start_date" 
							value="<?php echo esc_attr( $start_date ); ?>" 
							class="widefat"
							required
						/>
					</p>
				</div>
				<div style="flex: 1;">
					<p class="event-time-field">
						<label for="event_start_time">
							<strong><?php esc_html_e( 'Start Time:', 'wp-mcp-ai' ); ?></strong>
						</label><br>
						<input 
							type="time" 
							id="event_start_time" 
							name="event_start_time" 
							value="<?php echo esc_attr( $start_time ); ?>" 
							class="widefat"
						/>
					</p>
				</div>
			</div>

			<div style="display: flex; gap: 10px;">
				<div style="flex: 1;">
					<p>
						<label for="event_end_date">
							<strong><?php esc_html_e( 'End Date:', 'wp-mcp-ai' ); ?></strong>
						</label><br>
						<input 
							type="date" 
							id="event_end_date" 
							name="event_end_date" 
							value="<?php echo esc_attr( $end_date ); ?>" 
							class="widefat"
						/>
					</p>
				</div>
				<div style="flex: 1;">
					<p class="event-time-field">
						<label for="event_end_time">
							<strong><?php esc_html_e( 'End Time:', 'wp-mcp-ai' ); ?></strong>
						</label><br>
						<input 
							type="time" 
							id="event_end_time" 
							name="event_end_time" 
							value="<?php echo esc_attr( $end_time ); ?>" 
							class="widefat"
						/>
					</p>
				</div>
			</div>

			<p>
				<label for="event_location">
					<strong><?php esc_html_e( 'Location:', 'wp-mcp-ai' ); ?></strong>
				</label><br>
				<input 
					type="text" 
					id="event_location" 
					name="event_location" 
					value="<?php echo esc_attr( $location ); ?>" 
					class="widefat"
					placeholder="<?php esc_attr_e( 'e.g., Conference Room A, Zoom Link, etc.', 'wp-mcp-ai' ); ?>"
				/>
			</p>

			<p>
				<label for="event_project_id">
					<strong><?php esc_html_e( 'Related Project:', 'wp-mcp-ai' ); ?></strong>
				</label><br>
				<select id="event_project_id" name="event_project_id" class="widefat">
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
				<label for="event_attendees">
					<strong><?php esc_html_e( 'Attendees:', 'wp-mcp-ai' ); ?></strong>
				</label><br>
				<select id="event_attendees" name="event_attendees[]" multiple class="widefat" style="height: 150px;">
					<?php
					$users = get_users( array( 'orderby' => 'display_name' ) );
					foreach ( $users as $user ) {
						printf(
							'<option value="%d" %s>%s</option>',
							esc_attr( $user->ID ),
							selected( in_array( $user->ID, $attendees, true ), true, false ),
							esc_html( $user->display_name )
						);
					}
					?>
				</select>
				<span class="description"><?php esc_html_e( 'Hold Ctrl/Cmd to select multiple attendees', 'wp-mcp-ai' ); ?></span>
			</p>
		</div>

		<script type="text/javascript">
		jQuery(document).ready(function($) {
			function toggleTimeFields() {
				var allDay = $('#event_all_day').is(':checked');
				$('.event-time-field').toggle(!allDay);
				if (allDay) {
					$('#event_start_time').val('');
					$('#event_end_time').val('');
				}
			}
			$('#event_all_day').on('change', toggleTimeFields);
			toggleTimeFields();
		});
		</script>
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
		if ( ! isset( $_POST['wp_mcp_ai_event_details_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wp_mcp_ai_event_details_nonce'] ) ), 'wp_mcp_ai_event_details' ) ) {
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

		// Save event type.
		if ( isset( $_POST['event_type'] ) ) {
			$type = sanitize_key( $_POST['event_type'] );
			$valid_types = array( 'meeting', 'deadline', 'milestone', 'reminder', 'other' );
			if ( in_array( $type, $valid_types, true ) ) {
				update_post_meta( $post_id, '_event_type', $type );
			}
		}

		// Save all-day flag.
		$all_day = isset( $_POST['event_all_day'] ) ? '1' : '0';
		update_post_meta( $post_id, '_event_all_day', $all_day );

		// Save start date.
		if ( isset( $_POST['event_start_date'] ) ) {
			$start_date = sanitize_text_field( $_POST['event_start_date'] );
			if ( self::validate_date( $start_date ) ) {
				update_post_meta( $post_id, '_event_start_date', $start_date );
			}
		}

		// Save start time (only if not all-day).
		if ( isset( $_POST['event_start_time'] ) && '0' === $all_day ) {
			$start_time = sanitize_text_field( $_POST['event_start_time'] );
			if ( empty( $start_time ) || self::validate_time( $start_time ) ) {
				update_post_meta( $post_id, '_event_start_time', $start_time );
			}
		} else {
			delete_post_meta( $post_id, '_event_start_time' );
		}

		// Save end date.
		if ( isset( $_POST['event_end_date'] ) ) {
			$end_date = sanitize_text_field( $_POST['event_end_date'] );
			if ( empty( $end_date ) || self::validate_date( $end_date ) ) {
				update_post_meta( $post_id, '_event_end_date', $end_date );
			}
		}

		// Save end time (only if not all-day).
		if ( isset( $_POST['event_end_time'] ) && '0' === $all_day ) {
			$end_time = sanitize_text_field( $_POST['event_end_time'] );
			if ( empty( $end_time ) || self::validate_time( $end_time ) ) {
				update_post_meta( $post_id, '_event_end_time', $end_time );
			}
		} else {
			delete_post_meta( $post_id, '_event_end_time' );
		}

		// Save location.
		if ( isset( $_POST['event_location'] ) ) {
			$location = sanitize_text_field( $_POST['event_location'] );
			update_post_meta( $post_id, '_event_location', $location );
		}

		// Save project ID.
		if ( isset( $_POST['event_project_id'] ) ) {
			$project_id = absint( $_POST['event_project_id'] );
			if ( $project_id > 0 ) {
				$project = get_post( $project_id );
				if ( $project && 'mcp_ai_project' === $project->post_type ) {
					update_post_meta( $post_id, '_event_project_id', $project_id );
				}
			} else {
				delete_post_meta( $post_id, '_event_project_id' );
			}
		}

		// Save attendees.
		if ( isset( $_POST['event_attendees'] ) && is_array( $_POST['event_attendees'] ) ) {
			$attendees = array_map( 'absint', $_POST['event_attendees'] );
			update_post_meta( $post_id, '_event_attendees', $attendees );
		} else {
			update_post_meta( $post_id, '_event_attendees', array() );
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

	/**
	 * Validate time format (HH:MM).
	 *
	 * @param string $time Time string.
	 * @return bool
	 */
	private static function validate_time( $time ) {
		return (bool) preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $time );
	}
}
