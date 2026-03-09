<?php
/**
 * PM Notification Manager for NV oOS Project Management Toolkit.
 *
 * Sends email notifications for task events:
 * - Task assigned to a user (immediate email).
 * - Task due within 24 hours (daily cron digest).
 * - Task status changed (immediate email to assignee).
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PM Notification Manager class.
 *
 * Wires WordPress hooks to send email notifications for task lifecycle events.
 */
class WP_MCP_AI_PM_Notification_Manager {

	/**
	 * Cron event hook name for due-date digests.
	 */
	const CRON_HOOK = 'wp_mcp_ai_pm_due_date_digest';

	/**
	 * Option key that stores notification settings.
	 */
	const SETTINGS_KEY = 'wp_mcp_ai_settings';

	/**
	 * Number of days before due date at which a reminder is sent.
	 *
	 * @var int
	 */
	private $reminder_days;

	/**
	 * Constructor. Registers hooks.
	 */
	public function __construct() {
		$settings            = get_option( self::SETTINGS_KEY, array() );
		$this->reminder_days = isset( $settings['pm_reminder_days'] ) ? absint( $settings['pm_reminder_days'] ) : 1;

		// Hook into task meta changes to detect assignment / status changes.
		add_action( 'updated_post_meta', array( $this, 'on_task_meta_updated' ), 10, 4 );
		add_action( 'added_post_meta', array( $this, 'on_task_meta_added' ), 10, 4 );

		// Register cron schedule and handler.
		add_filter( 'cron_schedules', array( $this, 'add_daily_schedule' ) );
		add_action( self::CRON_HOOK, array( $this, 'send_due_date_digest' ) );
	}

	/**
	 * Add a "once daily" schedule if it does not already exist.
	 *
	 * @param array $schedules Existing cron schedules.
	 * @return array Modified cron schedules.
	 */
	public function add_daily_schedule( $schedules ) {
		if ( ! isset( $schedules['wp_mcp_ai_daily'] ) ) {
			$schedules['wp_mcp_ai_daily'] = array(
				'interval' => DAY_IN_SECONDS,
				'display'  => __( 'Once Daily (NV oOS PM)', 'mcp-ai-wpoos-pro' ),
			);
		}
		return $schedules;
	}

	/**
	 * Schedule the daily due-date digest cron (called on plugin init).
	 */
	public static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			// Schedule for next midnight UTC.
			$next_midnight = strtotime( 'tomorrow midnight' );
			wp_schedule_event( $next_midnight, 'wp_mcp_ai_daily', self::CRON_HOOK );
		}
	}

	/**
	 * Unschedule the due-date digest cron (called on plugin deactivation).
	 */
	public static function unschedule_cron() {
		$timestamp = wp_next_scheduled( self::CRON_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_HOOK );
		}
	}

	/**
	 * Handle updated_post_meta for task posts.
	 *
	 * @param int    $meta_id    ID of the meta row being updated.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key being updated.
	 * @param mixed  $meta_value New meta value.
	 */
	public function on_task_meta_updated( $meta_id, $post_id, $meta_key, $meta_value ) {
		$this->handle_task_meta_change( $post_id, $meta_key, $meta_value );
	}

	/**
	 * Handle added_post_meta for task posts.
	 *
	 * @param int    $meta_id    ID of the new meta row.
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key being added.
	 * @param mixed  $meta_value New meta value.
	 */
	public function on_task_meta_added( $meta_id, $post_id, $meta_key, $meta_value ) {
		$this->handle_task_meta_change( $post_id, $meta_key, $meta_value );
	}

	/**
	 * Dispatch notification emails when task meta changes.
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value New value.
	 */
	private function handle_task_meta_change( $post_id, $meta_key, $meta_value ) {
		$post = get_post( $post_id );
		if ( ! $post || 'mcp_ai_task' !== $post->post_type ) {
			return;
		}

		// Notifications require project management to be enabled.
		$settings = get_option( self::SETTINGS_KEY, array() );
		if ( empty( $settings['enable_project_management'] ) ) {
			return;
		}

		// Notify on assignment change.
		if ( '_task_assigned_to' === $meta_key ) {
			$assigned_user_id = absint( $meta_value );
			if ( $assigned_user_id > 0 ) {
				$this->send_assignment_notification( $post, $assigned_user_id );
			}
		}

		// Notify on status change.
		if ( '_task_status' === $meta_key ) {
			$assignee_id = (int) get_post_meta( $post_id, '_task_assigned_to', true );
			if ( $assignee_id > 0 ) {
				$this->send_status_change_notification( $post, $assignee_id, sanitize_text_field( $meta_value ) );
			}
		}
	}

	/**
	 * Send a task-assignment email to the newly assigned user.
	 *
	 * @param WP_Post $task    The task post.
	 * @param int     $user_id WordPress user ID of the assignee.
	 */
	public function send_assignment_notification( $task, $user_id ) {
		$settings = get_option( self::SETTINGS_KEY, array() );
		if ( isset( $settings['pm_disable_assignment_emails'] ) && $settings['pm_disable_assignment_emails'] ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}

		$due_date    = get_post_meta( $task->ID, '_task_due_date', true );
		$priority    = get_post_meta( $task->ID, '_task_priority', true ) ?: 'medium';
		$project_id  = (int) get_post_meta( $task->ID, '_task_project_id', true );
		$project     = $project_id ? get_post( $project_id ) : null;

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$subject = sprintf(
			/* translators: 1: site name, 2: task title */
			__( '[%1$s] You have been assigned a task: %2$s', 'mcp-ai-wpoos-pro' ),
			$site_name,
			$task->post_title
		);

		$message_parts = array(
			sprintf(
				/* translators: %s: user display name */
				__( 'Hi %s,', 'mcp-ai-wpoos-pro' ),
				$user->display_name
			),
			'',
			sprintf(
				/* translators: %s: task title */
				__( 'You have been assigned the following task: %s', 'mcp-ai-wpoos-pro' ),
				$task->post_title
			),
		);

		if ( $task->post_content ) {
			$message_parts[] = '';
			$message_parts[] = __( 'Description:', 'mcp-ai-wpoos-pro' );
			$message_parts[] = wp_strip_all_tags( $task->post_content );
		}

		if ( $due_date ) {
			$message_parts[] = '';
			$message_parts[] = sprintf(
				/* translators: %s: due date */
				__( 'Due Date: %s', 'mcp-ai-wpoos-pro' ),
				$due_date
			);
		}

		$message_parts[] = sprintf(
			/* translators: %s: priority level */
			__( 'Priority: %s', 'mcp-ai-wpoos-pro' ),
			ucfirst( $priority )
		);

		if ( $project ) {
			$message_parts[] = sprintf(
				/* translators: %s: project name */
				__( 'Project: %s', 'mcp-ai-wpoos-pro' ),
				$project->post_title
			);
		}

		$admin_url = admin_url( 'post.php?post=' . $task->ID . '&action=edit' );
		$message_parts[] = '';
		$message_parts[] = sprintf(
			/* translators: %s: admin URL */
			__( 'View task: %s', 'mcp-ai-wpoos-pro' ),
			$admin_url
		);

		$message_parts[] = '';
		$message_parts[] = sprintf(
			/* translators: %s: site name */
			__( '— %s', 'mcp-ai-wpoos-pro' ),
			$site_name
		);

		$message = implode( "\n", $message_parts );

		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		wp_mail( $user->user_email, $subject, $message, $headers );
	}

	/**
	 * Send a status-change notification email to the task assignee.
	 *
	 * @param WP_Post $task       The task post.
	 * @param int     $user_id    Assignee's WordPress user ID.
	 * @param string  $new_status New task status.
	 */
	public function send_status_change_notification( $task, $user_id, $new_status ) {
		$settings = get_option( self::SETTINGS_KEY, array() );
		if ( isset( $settings['pm_disable_status_emails'] ) && $settings['pm_disable_status_emails'] ) {
			return;
		}

		// Skip if the actor is the same as the assignee AND we are in an interactive
		// (non-cron) context. In cron / REST background jobs get_current_user_id()
		// returns 0, so we skip this check to avoid suppressing automated notifications.
		$actor_id = get_current_user_id();
		if ( $actor_id > 0 && $actor_id === $user_id ) {
			return;
		}

		$user = get_user_by( 'id', $user_id );
		if ( ! $user || ! is_email( $user->user_email ) ) {
			return;
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		$subject = sprintf(
			/* translators: 1: site name, 2: task title, 3: new status */
			__( '[%1$s] Task status updated: %2$s → %3$s', 'mcp-ai-wpoos-pro' ),
			$site_name,
			$task->post_title,
			ucfirst( str_replace( '-', ' ', $new_status ) )
		);

		$message_parts = array(
			sprintf(
				/* translators: %s: user display name */
				__( 'Hi %s,', 'mcp-ai-wpoos-pro' ),
				$user->display_name
			),
			'',
			sprintf(
				/* translators: 1: task title, 2: new status */
				__( 'The status of task "%1$s" has been updated to: %2$s', 'mcp-ai-wpoos-pro' ),
				$task->post_title,
				ucfirst( str_replace( '-', ' ', $new_status ) )
			),
		);

		$admin_url       = admin_url( 'post.php?post=' . $task->ID . '&action=edit' );
		$message_parts[] = '';
		$message_parts[] = sprintf(
			/* translators: %s: admin URL */
			__( 'View task: %s', 'mcp-ai-wpoos-pro' ),
			$admin_url
		);

		$message_parts[] = '';
		$message_parts[] = sprintf(
			/* translators: %s: site name */
			__( '— %s', 'mcp-ai-wpoos-pro' ),
			$site_name
		);

		$message = implode( "\n", $message_parts );
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

		wp_mail( $user->user_email, $subject, $message, $headers );
	}

	/**
	 * Cron callback: send due-date digest emails.
	 *
	 * Queries all tasks due within the configured reminder window and
	 * sends one summary email per assignee.
	 */
	public function send_due_date_digest() {
		$settings = get_option( self::SETTINGS_KEY, array() );

		if ( empty( $settings['enable_project_management'] ) ) {
			return;
		}

		if ( isset( $settings['pm_disable_due_date_emails'] ) && $settings['pm_disable_due_date_emails'] ) {
			return;
		}

		$reminder_days = isset( $settings['pm_reminder_days'] ) ? absint( $settings['pm_reminder_days'] ) : 1;
		$target_date   = date_i18n( 'Y-m-d', strtotime( '+' . $reminder_days . ' days' ) );

		/**
		 * Filter the maximum number of tasks included in a single due-date digest run.
		 *
		 * Increase this value if your site regularly has more than 200 tasks due on the
		 * same day. Set to -1 to remove the limit (use with caution on large sites).
		 *
		 * @since 1.2.0
		 *
		 * @param int    $limit       Maximum tasks per digest run. Default 200.
		 * @param string $target_date The due date being processed (YYYY-MM-DD).
		 */
		$digest_limit = (int) apply_filters( 'wp_mcp_ai_pm_digest_task_limit', 200, $target_date );

		// Query tasks due on the target date that are not completed or cancelled.
		$due_tasks = get_posts(
			array(
				'post_type'      => 'mcp_ai_task',
				'post_status'    => 'publish',
				'posts_per_page' => $digest_limit, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- bounded, configurable via wp_mcp_ai_pm_digest_task_limit filter.
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- intentional filtered query for due-date digest.
					'relation' => 'AND',
					array(
						'key'     => '_task_due_date',
						'value'   => $target_date,
						'compare' => '=',
						'type'    => 'DATE',
					),
					array(
						'key'     => '_task_status',
						'value'   => array( 'completed', 'cancelled' ),
						'compare' => 'NOT IN',
					),
					array(
						'key'     => '_task_assigned_to',
						'value'   => '0',
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		if ( empty( $due_tasks ) ) {
			return;
		}

		// Group tasks by assignee.
		$by_user = array();
		foreach ( $due_tasks as $task ) {
			$assignee_id = (int) get_post_meta( $task->ID, '_task_assigned_to', true );
			if ( $assignee_id > 0 ) {
				$by_user[ $assignee_id ][] = $task;
			}
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );

		foreach ( $by_user as $user_id => $tasks ) {
			$user = get_user_by( 'id', $user_id );
			if ( ! $user || ! is_email( $user->user_email ) ) {
				continue;
			}

			$task_count = count( $tasks );

			$subject = sprintf(
				/* translators: 1: site name, 2: number of tasks, 3: due date */
				_n(
					'[%1$s] You have %2$d task due on %3$s',
					'[%1$s] You have %2$d tasks due on %3$s',
					$task_count,
					'mcp-ai-wpoos-pro'
				),
				$site_name,
				$task_count,
				$target_date
			);

			$message_parts = array(
				sprintf(
					/* translators: %s: user display name */
					__( 'Hi %s,', 'mcp-ai-wpoos-pro' ),
					$user->display_name
				),
				'',
				sprintf(
					/* translators: 1: number of tasks, 2: due date */
					_n(
						'You have %1$d task due on %2$s:',
						'You have %1$d tasks due on %2$s:',
						$task_count,
						'mcp-ai-wpoos-pro'
					),
					$task_count,
					$target_date
				),
				'',
			);

			foreach ( $tasks as $task ) {
				$priority = get_post_meta( $task->ID, '_task_priority', true ) ?: 'medium';
				$message_parts[] = sprintf(
					/* translators: 1: task title, 2: priority */
					__( '• %1$s [%2$s]', 'mcp-ai-wpoos-pro' ),
					$task->post_title,
					ucfirst( $priority )
				);
				$message_parts[] = '  ' . admin_url( 'post.php?post=' . $task->ID . '&action=edit' );
				$message_parts[] = '';
			}

			$message_parts[] = sprintf(
				/* translators: %s: site name */
				__( '— %s', 'mcp-ai-wpoos-pro' ),
				$site_name
			);

			$message = implode( "\n", $message_parts );
			$headers = array( 'Content-Type: text/plain; charset=UTF-8' );

			wp_mail( $user->user_email, $subject, $message, $headers );
		}
	}

	/**
	 * Initialize the notification manager.
	 *
	 * Called from project-management-init.php when PM is enabled.
	 */
	public static function init() {
		new self();
		self::schedule_cron();
	}
}
