<?php
/**
 * WP-CLI task management commands for NV oOS Pro.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CLI
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/class-wp-mcp-ai-pro-cli-base-command.php';

/**
 * Manage NV oOS Pro tasks (mcp_ai_task CPT) from the command line.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_CLI_Task_Command extends WP_MCP_AI_Pro_CLI_Base_Command {

	/**
	 * List tasks, optionally filtered by project or status.
	 *
	 * ## OPTIONS
	 *
	 * [--project=<id>]
	 * : Filter tasks by project post ID.
	 *
	 * [--status=<status>]
	 * : Filter by task status meta value (e.g. pending, in_progress, completed, cancelled).
	 *
	 * [--post-status=<status>]
	 * : Filter by WordPress post status.
	 * ---
	 * default: publish
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 *   - ids
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # List all tasks.
	 *     $ wp mcp-ai task list
	 *
	 *     # List tasks for a specific project.
	 *     $ wp mcp-ai task list --project=42
	 *
	 *     # List only completed tasks.
	 *     $ wp mcp-ai task list --status=completed
	 *
	 * @subcommand list
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function list( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_project_management', 'Project Management' );

		$project_id  = absint( \WP_CLI\Utils\get_flag_value( $assoc_args, 'project', 0 ) );
		$task_status = sanitize_key( \WP_CLI\Utils\get_flag_value( $assoc_args, 'status', '' ) );
		$post_status = sanitize_key( \WP_CLI\Utils\get_flag_value( $assoc_args, 'post-status', 'publish' ) );
		$format      = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$query_args = array(
			'post_type'      => 'mcp_ai_task',
			'posts_per_page' => -1,
			'post_status'    => $post_status,
			'orderby'        => 'title',
			'order'          => 'ASC',
		);

		$posts = get_posts( $query_args );

		// Filter by project ID in PHP to avoid slow meta_query.
		if ( $project_id ) {
			$posts = array_filter(
				$posts,
				function ( $post ) use ( $project_id ) {
					return absint( get_post_meta( $post->ID, '_task_project_id', true ) ) === $project_id;
				}
			);
		}

		// Filter by task status meta if requested.
		if ( $task_status ) {
			$posts = array_filter(
				$posts,
				function ( $post ) use ( $task_status ) {
					return get_post_meta( $post->ID, '_task_status', true ) === $task_status;
				}
			);
		}

		if ( empty( $posts ) ) {
			WP_CLI::log( __( 'No tasks found.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', wp_list_pluck( $posts, 'ID' ) ) );
			return;
		}

		$items = array();
		foreach ( $posts as $post ) {
			$task_status = get_post_meta( $post->ID, '_task_status', true );
			$priority    = get_post_meta( $post->ID, '_task_priority', true );
			$project_id  = get_post_meta( $post->ID, '_task_project_id', true );
			$due_date    = get_post_meta( $post->ID, '_task_due_date', true );
			$items[]     = array(
				'ID'         => $post->ID,
				'title'      => $post->post_title,
				'status'     => ! empty( $task_status ) ? $task_status : 'pending',
				'priority'   => ! empty( $priority ) ? $priority : '',
				'project_id' => ! empty( $project_id ) ? $project_id : '',
				'due_date'   => ! empty( $due_date ) ? $due_date : '',
			);
		}

		\WP_CLI\Utils\format_items( $format, $items, array( 'ID', 'title', 'status', 'priority', 'project_id', 'due_date' ) );
	}

	/**
	 * Get details for a single task.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The task post ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai task get 99
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function get( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_project_management', 'Project Management' );

		$id     = isset( $args[0] ) ? absint( $args[0] ) : 0;
		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( ! $id ) {
			WP_CLI::error( __( 'Please provide a valid task ID.', 'mcp-ai-wpoos-pro' ) );
		}

		$post = get_post( $id );

		if ( ! $post || 'mcp_ai_task' !== $post->post_type ) {
			/* translators: %d: task ID */
			WP_CLI::error( sprintf( __( 'Task %d not found.', 'mcp-ai-wpoos-pro' ), $id ) );
		}

		$data = array(
			'ID'      => $post->ID,
			'title'   => $post->post_title,
			'content' => $post->post_content,
			'created' => $post->post_date,
			'updated' => $post->post_modified,
		);

		foreach ( get_post_meta( $id ) as $meta_key => $values ) {
			if ( 0 === strpos( $meta_key, '_task_' ) ) {
				$data[ $meta_key ] = $values[0] ?? '';
			}
		}

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
			return;
		}

		if ( 'yaml' === $format ) {
			foreach ( $data as $key => $value ) {
				WP_CLI::line( "{$key}: " . ( is_scalar( $value ) ? $value : wp_json_encode( $value ) ) );
			}
			return;
		}

		$items = array();
		foreach ( $data as $key => $value ) {
			$items[] = array(
				'field' => $key,
				'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $items, array( 'field', 'value' ) );
	}

	/**
	 * Create a new task.
	 *
	 * ## OPTIONS
	 *
	 * --title=<title>
	 * : Task title.
	 *
	 * [--project=<id>]
	 * : Parent project post ID.
	 *
	 * [--status=<status>]
	 * : Task status (pending, in_progress, completed, cancelled).
	 * ---
	 * default: pending
	 * ---
	 *
	 * [--priority=<priority>]
	 * : Task priority (low, medium, high, critical).
	 * ---
	 * default: medium
	 * ---
	 *
	 * [--due-date=<date>]
	 * : Due date in YYYY-MM-DD format.
	 *
	 * [--description=<text>]
	 * : Task description.
	 *
	 * [--porcelain]
	 * : Output only the new task ID.
	 *
	 * ## EXAMPLES
	 *
	 *     # Create a task.
	 *     $ wp mcp-ai task create --title="Write tests" --project=42
	 *
	 *     # Capture the new ID.
	 *     $ TID=$(wp mcp-ai task create --title="Write tests" --porcelain)
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function create( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_project_management', 'Project Management' );

		$title       = sanitize_text_field( \WP_CLI\Utils\get_flag_value( $assoc_args, 'title', '' ) );
		$project_id  = absint( \WP_CLI\Utils\get_flag_value( $assoc_args, 'project', 0 ) );
		$task_status = sanitize_key( \WP_CLI\Utils\get_flag_value( $assoc_args, 'status', 'pending' ) );
		$priority    = sanitize_key( \WP_CLI\Utils\get_flag_value( $assoc_args, 'priority', 'medium' ) );
		$due_date    = sanitize_text_field( \WP_CLI\Utils\get_flag_value( $assoc_args, 'due-date', '' ) );
		$description = \WP_CLI\Utils\get_flag_value( $assoc_args, 'description', '' );
		$porcelain   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'porcelain', false );

		if ( '' === $title ) {
			WP_CLI::error( __( 'Please provide a --title for the task.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate project if provided.
		if ( $project_id ) {
			$project = get_post( $project_id );
			if ( ! $project || 'mcp_ai_project' !== $project->post_type ) {
				/* translators: %d: project ID */
				WP_CLI::error( sprintf( __( 'Project %d not found.', 'mcp-ai-wpoos-pro' ), $project_id ) );
			}
		}

		$valid_statuses   = array( 'pending', 'in_progress', 'completed', 'cancelled' );
		$valid_priorities = array( 'low', 'medium', 'high', 'critical' );

		if ( ! in_array( $task_status, $valid_statuses, true ) ) {
			$task_status = 'pending';
		}

		if ( ! in_array( $priority, $valid_priorities, true ) ) {
			$priority = 'medium';
		}

		$id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_task',
				'post_title'   => $title,
				'post_status'  => 'publish',
				'post_content' => sanitize_textarea_field( $description ),
			),
			true
		);

		if ( is_wp_error( $id ) ) {
			WP_CLI::error( $id->get_error_message() );
		}

		update_post_meta( $id, '_task_status', $task_status );
		update_post_meta( $id, '_task_priority', $priority );

		if ( $project_id ) {
			update_post_meta( $id, '_task_project_id', $project_id );
		}

		if ( $due_date ) {
			update_post_meta( $id, '_task_due_date', $due_date );
		}

		if ( $porcelain ) {
			WP_CLI::line( $id );
			return;
		}

		/* translators: 1: task title, 2: task post ID */
		WP_CLI::success( sprintf( __( 'Created task "%1$s" (ID: %2$d).', 'mcp-ai-wpoos-pro' ), $title, $id ) );
	}

	/**
	 * Update an existing task.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The task post ID.
	 *
	 * [--title=<title>]
	 * : New task title.
	 *
	 * [--status=<status>]
	 * : Task status (pending, in_progress, completed, cancelled).
	 *
	 * [--priority=<priority>]
	 * : Task priority (low, medium, high, critical).
	 *
	 * [--due-date=<date>]
	 * : Due date in YYYY-MM-DD format.
	 *
	 * [--description=<text>]
	 * : New task description.
	 *
	 * [--project=<id>]
	 * : Reassign to a different project post ID.
	 *
	 * ## EXAMPLES
	 *
	 *     # Update a task title.
	 *     $ wp mcp-ai task update 99 --title="Write integration tests"
	 *
	 *     # Change status and priority.
	 *     $ wp mcp-ai task update 99 --status=in_progress --priority=high
	 *
	 *     # Reassign to a different project.
	 *     $ wp mcp-ai task update 99 --project=43
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function update( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_project_management', 'Project Management' );

		$id = isset( $args[0] ) ? absint( $args[0] ) : 0;

		if ( ! $id ) {
			WP_CLI::error( __( 'Please provide a valid task ID.', 'mcp-ai-wpoos-pro' ) );
		}

		$post = get_post( $id );

		if ( ! $post || 'mcp_ai_task' !== $post->post_type ) {
			/* translators: %d: task ID */
			WP_CLI::error( sprintf( __( 'Task %d not found.', 'mcp-ai-wpoos-pro' ), $id ) );
		}

		$title       = sanitize_text_field( \WP_CLI\Utils\get_flag_value( $assoc_args, 'title', '' ) );
		$project_id  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'project', null );
		$task_status = sanitize_key( \WP_CLI\Utils\get_flag_value( $assoc_args, 'status', '' ) );
		$priority    = sanitize_key( \WP_CLI\Utils\get_flag_value( $assoc_args, 'priority', '' ) );
		$due_date    = sanitize_text_field( \WP_CLI\Utils\get_flag_value( $assoc_args, 'due-date', '' ) );
		$description = \WP_CLI\Utils\get_flag_value( $assoc_args, 'description', null );
		$has_project = isset( $assoc_args['project'] );
		$has_desc    = isset( $assoc_args['description'] );

		// Validate project if provided.
		if ( $has_project && $project_id ) {
			$project_id = absint( $project_id );
			$project    = get_post( $project_id );
			if ( ! $project || 'mcp_ai_project' !== $project->post_type ) {
				/* translators: %d: project ID */
				WP_CLI::error( sprintf( __( 'Project %d not found.', 'mcp-ai-wpoos-pro' ), $project_id ) );
			}
		} elseif ( $has_project && ! $project_id ) {
			$project_id = 0;
		}

		$valid_statuses   = array( 'pending', 'in_progress', 'completed', 'cancelled' );
		$valid_priorities = array( 'low', 'medium', 'high', 'critical' );

		// Build post data for wp_update_post (title / content only).
		$post_data = array( 'ID' => $id );

		if ( '' !== $title ) {
			$post_data['post_title'] = $title;
		}

		if ( $has_desc ) {
			$post_data['post_content'] = sanitize_textarea_field( $description );
		}

		if ( count( $post_data ) > 1 ) {
			$result = wp_update_post( $post_data, true );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
		}

		// Update meta fields only when explicitly provided.
		if ( '' !== $task_status && in_array( $task_status, $valid_statuses, true ) ) {
			update_post_meta( $id, '_task_status', $task_status );
		}

		if ( '' !== $priority && in_array( $priority, $valid_priorities, true ) ) {
			update_post_meta( $id, '_task_priority', $priority );
		}

		if ( $has_project ) {
			if ( $project_id ) {
				update_post_meta( $id, '_task_project_id', $project_id );
			} else {
				delete_post_meta( $id, '_task_project_id' );
			}
		}

		if ( '' !== $due_date ) {
			update_post_meta( $id, '_task_due_date', $due_date );
		}

		/* translators: 1: task title, 2: task ID */
		WP_CLI::success( sprintf( __( 'Updated task "%1$s" (ID %2$d).', 'mcp-ai-wpoos-pro' ), $post->post_title, $id ) );
	}

	/**
	 * Mark a task as completed.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The task post ID.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai task complete 99
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function complete( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_project_management', 'Project Management' );

		$id = isset( $args[0] ) ? absint( $args[0] ) : 0;

		if ( ! $id ) {
			WP_CLI::error( __( 'Please provide a valid task ID.', 'mcp-ai-wpoos-pro' ) );
		}

		$post = get_post( $id );

		if ( ! $post || 'mcp_ai_task' !== $post->post_type ) {
			/* translators: %d: task ID */
			WP_CLI::error( sprintf( __( 'Task %d not found.', 'mcp-ai-wpoos-pro' ), $id ) );
		}

		update_post_meta( $id, '_task_status', 'completed' );

		/* translators: 1: task title, 2: task ID */
		WP_CLI::success( sprintf( __( 'Task "%1$s" (ID %2$d) marked as completed.', 'mcp-ai-wpoos-pro' ), $post->post_title, $id ) );
	}

	/**
	 * Delete a task.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The task post ID.
	 *
	 * [--force]
	 * : Permanently delete without trashing.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp mcp-ai task delete 99 --force --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function delete( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_project_management', 'Project Management' );

		$id    = isset( $args[0] ) ? absint( $args[0] ) : 0;
		$force = \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		$yes   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false );

		if ( ! $id ) {
			WP_CLI::error( __( 'Please provide a valid task ID.', 'mcp-ai-wpoos-pro' ) );
		}

		$post = get_post( $id );

		if ( ! $post || 'mcp_ai_task' !== $post->post_type ) {
			/* translators: %d: task ID */
			WP_CLI::error( sprintf( __( 'Task %d not found.', 'mcp-ai-wpoos-pro' ), $id ) );
		}

		if ( ! $yes ) {
			$action = $force
				? /* translators: 1: task title, 2: task ID */
					sprintf( __( 'Permanently delete task "%1$s" (ID %2$d)?', 'mcp-ai-wpoos-pro' ), $post->post_title, $id )
				: /* translators: 1: task title, 2: task ID */
					sprintf( __( 'Move task "%1$s" (ID %2$d) to trash?', 'mcp-ai-wpoos-pro' ), $post->post_title, $id );
			WP_CLI::confirm( $action );
		}

		$result = wp_delete_post( $id, (bool) $force );

		if ( ! $result ) {
			/* translators: %d: task ID */
			WP_CLI::error( sprintf( __( 'Failed to delete task %d.', 'mcp-ai-wpoos-pro' ), $id ) );
		}

		if ( $force ) {
			/* translators: 1: task title, 2: task ID */
			WP_CLI::success( sprintf( __( 'Permanently deleted task "%1$s" (ID %2$d).', 'mcp-ai-wpoos-pro' ), $post->post_title, $id ) );
		} else {
			/* translators: 1: task title, 2: task ID */
			WP_CLI::success( sprintf( __( 'Moved task "%1$s" (ID %2$d) to trash.', 'mcp-ai-wpoos-pro' ), $post->post_title, $id ) );
		}
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai task', 'WP_MCP_AI_Pro_CLI_Task_Command' );
}
