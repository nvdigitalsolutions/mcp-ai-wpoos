<?php
/**
 * A2A Task Manager — task state machine and storage.
 *
 * Manages A2A task lifecycle including state transitions,
 * persistence, history, and artifact management.
 *
 * @package NvoosContentGraphAiPlatform
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 * @see       https://a2a-protocol.org/latest/specification/
 * @since     2.0.0 Ported from mcp-ai-wpoos/includes/a2a/class-wp-mcp-ai-a2a-task-manager.php.
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\A2A;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages A2A tasks with state machine, persistence, and event hooks.
 */
class TaskManager {

	/**
	 * Task states per A2A specification.
	 */
	const STATE_SUBMITTED      = 'submitted';
	const STATE_WORKING        = 'working';
	const STATE_INPUT_REQUIRED = 'input-required';
	const STATE_COMPLETED      = 'completed';
	const STATE_FAILED         = 'failed';
	const STATE_CANCELED       = 'canceled';
	const STATE_REJECTED       = 'rejected';
	const STATE_AUTH_REQUIRED  = 'auth-required';

	/**
	 * Valid terminal states.
	 *
	 * @var array
	 */
	const TERMINAL_STATES = array(
		self::STATE_COMPLETED,
		self::STATE_FAILED,
		self::STATE_CANCELED,
		self::STATE_REJECTED,
	);

	/**
	 * Valid state transitions.
	 *
	 * @var array
	 */
	const VALID_TRANSITIONS = array(
		self::STATE_SUBMITTED      => array( self::STATE_WORKING, self::STATE_COMPLETED, self::STATE_FAILED, self::STATE_CANCELED, self::STATE_REJECTED ),
		self::STATE_WORKING        => array( self::STATE_COMPLETED, self::STATE_FAILED, self::STATE_CANCELED, self::STATE_INPUT_REQUIRED, self::STATE_AUTH_REQUIRED ),
		self::STATE_INPUT_REQUIRED => array( self::STATE_WORKING, self::STATE_COMPLETED, self::STATE_FAILED, self::STATE_CANCELED ),
		self::STATE_AUTH_REQUIRED  => array( self::STATE_WORKING, self::STATE_FAILED, self::STATE_CANCELED ),
	);

	/**
	 * WordPress option key for task storage.
	 *
	 * @var string
	 */
	const TASKS_OPTION = 'wp_mcp_ai_a2a_tasks';

	/**
	 * Maximum number of tasks to store.
	 *
	 * @var int
	 */
	const MAX_TASKS = 500;

	/**
	 * Task expiration time in seconds (24 hours).
	 *
	 * @var int
	 */
	const TASK_TTL = 86400;

	/**
	 * Create a new A2A task.
	 *
	 * @param array  $message    The initial user message (A2A Message object).
	 * @param string $context_id Optional context ID for grouping tasks.
	 * @return array The created Task object.
	 */
	public static function create_task( $message, $context_id = '' ) {
		$task_id = wp_generate_uuid4();

		if ( empty( $context_id ) ) {
			$context_id = wp_generate_uuid4();
		}

		$now = gmdate( 'Y-m-d\TH:i:s\Z' );

		$task = array(
			'kind'      => 'task',
			'id'        => $task_id,
			'contextId' => $context_id,
			'status'    => array(
				'state'     => self::STATE_SUBMITTED,
				'timestamp' => $now,
			),
			'history'   => array( $message ),
			'artifacts' => array(),
			'metadata'  => array(),
		);

		/**
		 * Fires before an A2A task is created.
		 *
		 * @param array $task The task data.
		 */
		do_action( 'wp_mcp_ai_a2a_before_task_create', $task );

		self::save_task( $task );

		return $task;
	}

	/**
	 * Transition a task to a new state.
	 *
	 * @param string     $task_id   The task ID.
	 * @param string     $new_state The target state.
	 * @param array|null $message   Optional status message.
	 * @return array|\WP_Error Updated task or error.
	 */
	public static function transition_state( $task_id, $new_state, $message = null ) {
		$task = self::get_task( $task_id );
		if ( ! $task ) {
			return new \WP_Error(
				'a2a_task_not_found',
				__( 'Task not found.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 404 )
			);
		}

		$current_state = $task['status']['state'];

		// Reject transitions from terminal states.
		if ( in_array( $current_state, self::TERMINAL_STATES, true ) ) {
			return new \WP_Error(
				'a2a_unsupported_operation',
				__( 'Cannot transition from a terminal state.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		// Validate transition.
		$allowed = isset( self::VALID_TRANSITIONS[ $current_state ] ) ? self::VALID_TRANSITIONS[ $current_state ] : array();
		if ( ! in_array( $new_state, $allowed, true ) ) {
			return new \WP_Error(
				'a2a_invalid_transition',
				sprintf(
					/* translators: 1: current state 2: target state */
					__( 'Invalid state transition from %1$s to %2$s.', 'nvoos-content-graph-ai-platform' ),
					$current_state,
					$new_state
				),
				array( 'status' => 400 )
			);
		}

		$now = gmdate( 'Y-m-d\TH:i:s\Z' );

		$task['status'] = array(
			'state'     => $new_state,
			'timestamp' => $now,
		);

		if ( $message ) {
			$task['status']['message'] = $message;
		}

		self::save_task( $task );

		/**
		 * Fires when an A2A task state changes.
		 *
		 * @param array  $task      The updated task.
		 * @param string $old_state The previous state.
		 * @param string $new_state The new state.
		 */
		do_action( 'wp_mcp_ai_a2a_task_state_change', $task, $current_state, $new_state );

		return $task;
	}

	/**
	 * Add a message to task history.
	 *
	 * @param string $task_id The task ID.
	 * @param array  $message The A2A Message object to append.
	 * @return array|\WP_Error Updated task or error.
	 */
	public static function add_message( $task_id, $message ) {
		$task = self::get_task( $task_id );
		if ( ! $task ) {
			return new \WP_Error(
				'a2a_task_not_found',
				__( 'Task not found.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 404 )
			);
		}

		$task['history'][] = $message;
		self::save_task( $task );

		return $task;
	}

	/**
	 * Add an artifact to a task.
	 *
	 * @param string $task_id  The task ID.
	 * @param array  $artifact The A2A Artifact object to append.
	 * @return array|\WP_Error Updated task or error.
	 */
	public static function add_artifact( $task_id, $artifact ) {
		$task = self::get_task( $task_id );
		if ( ! $task ) {
			return new \WP_Error(
				'a2a_task_not_found',
				__( 'Task not found.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 404 )
			);
		}

		$task['artifacts'][] = $artifact;
		self::save_task( $task );

		return $task;
	}

	/**
	 * Get a task by ID.
	 *
	 * @param string $task_id The task ID.
	 * @return array|null The task data or null if not found.
	 */
	public static function get_task( $task_id ) {
		$tasks = self::get_all_tasks();
		return isset( $tasks[ $task_id ] ) ? $tasks[ $task_id ] : null;
	}

	/**
	 * List tasks with optional filtering.
	 *
	 * @param array $args {
	 *     Optional. Arguments for listing tasks.
	 *
	 *     @type string $context_id        Filter by context ID.
	 *     @type string $state             Filter by state.
	 *     @type int    $per_page          Number of tasks per page. Default 20.
	 *     @type string $page_token        Cursor for pagination.
	 *     @type bool   $include_artifacts Whether to include artifacts. Default false.
	 * }
	 * @return array {
	 *     @type array  $tasks         Array of task objects.
	 *     @type string $nextPageToken Token for next page, empty if none.
	 * }
	 */
	public static function list_tasks( $args = array() ) {
		$defaults = array(
			'context_id'        => '',
			'state'             => '',
			'per_page'          => 20,
			'page_token'        => '',
			'include_artifacts' => false,
		);

		$args  = wp_parse_args( $args, $defaults );
		$tasks = self::get_all_tasks();

		// Sort by status timestamp descending (most recent first).
		uasort(
			$tasks,
			static function ( $a, $b ) {
				$ts_a = isset( $a['status']['timestamp'] ) ? $a['status']['timestamp'] : '';
				$ts_b = isset( $b['status']['timestamp'] ) ? $b['status']['timestamp'] : '';
				return strcmp( $ts_b, $ts_a );
			}
		);

		// Apply filters.
		if ( ! empty( $args['context_id'] ) ) {
			$tasks = array_filter(
				$tasks,
				static function ( $task ) use ( $args ) {
					return isset( $task['contextId'] ) && $task['contextId'] === $args['context_id'];
				}
			);
		}

		if ( ! empty( $args['state'] ) ) {
			$tasks = array_filter(
				$tasks,
				static function ( $task ) use ( $args ) {
					return isset( $task['status']['state'] ) && $task['status']['state'] === $args['state'];
				}
			);
		}

		// Pagination with cursor.
		$tasks_indexed = array_values( $tasks );
		$start_index   = 0;

		if ( ! empty( $args['page_token'] ) ) {
			foreach ( $tasks_indexed as $index => $task ) {
				if ( $task['id'] === $args['page_token'] ) {
					$start_index = $index + 1;
					break;
				}
			}
		}

		$page_tasks      = array_slice( $tasks_indexed, $start_index, $args['per_page'] );
		$next_page_token = '';

		if ( $start_index + $args['per_page'] < count( $tasks_indexed ) ) {
			$last_task       = end( $page_tasks );
			$next_page_token = $last_task ? $last_task['id'] : '';
		}

		// Optionally strip artifacts.
		if ( ! $args['include_artifacts'] ) {
			$page_tasks = array_map(
				static function ( $task ) {
					unset( $task['artifacts'] );
					return $task;
				},
				$page_tasks
			);
		}

		return array(
			'tasks'         => $page_tasks,
			'nextPageToken' => $next_page_token,
		);
	}

	/**
	 * Cancel a task.
	 *
	 * @param string $task_id The task ID.
	 * @return array|\WP_Error The updated task or error.
	 */
	public static function cancel_task( $task_id ) {
		$task = self::get_task( $task_id );
		if ( ! $task ) {
			return new \WP_Error(
				'a2a_task_not_found',
				__( 'Task not found.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 404 )
			);
		}

		if ( in_array( $task['status']['state'], self::TERMINAL_STATES, true ) ) {
			return new \WP_Error(
				'a2a_task_not_cancelable',
				__( 'Task is in a terminal state and cannot be canceled.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 400 )
			);
		}

		return self::transition_state( $task_id, self::STATE_CANCELED );
	}

	/**
	 * Delete expired tasks to prevent unbounded growth.
	 */
	public static function cleanup_expired_tasks() {
		$tasks   = self::get_all_tasks();
		$now     = time();
		$changed = false;

		foreach ( $tasks as $task_id => $task ) {
			$timestamp = isset( $task['status']['timestamp'] ) ? strtotime( $task['status']['timestamp'] ) : 0;

			if ( ( $now - $timestamp ) > self::TASK_TTL ) {
				unset( $tasks[ $task_id ] );
				$changed = true;
			}
		}

		if ( $changed ) {
			update_option( self::TASKS_OPTION, $tasks, false );
		}
	}

	/**
	 * Get all stored tasks.
	 *
	 * @return array Map of task_id => task data.
	 */
	protected static function get_all_tasks() {
		$tasks = get_option( self::TASKS_OPTION, array() );
		return is_array( $tasks ) ? $tasks : array();
	}

	/**
	 * Save a task to storage.
	 *
	 * @param array $task The task data.
	 */
	protected static function save_task( $task ) {
		$tasks = self::get_all_tasks();

		$tasks[ $task['id'] ] = $task;

		// Enforce max task limit by removing oldest expired tasks first.
		if ( count( $tasks ) > self::MAX_TASKS ) {
			self::cleanup_expired_tasks();
			$tasks                = self::get_all_tasks();
			$tasks[ $task['id'] ] = $task;

			// If still over limit, remove oldest.
			if ( count( $tasks ) > self::MAX_TASKS ) {
				// Sort by timestamp ascending.
				uasort(
					$tasks,
					static function ( $a, $b ) {
						$ts_a = isset( $a['status']['timestamp'] ) ? $a['status']['timestamp'] : '';
						$ts_b = isset( $b['status']['timestamp'] ) ? $b['status']['timestamp'] : '';
						return strcmp( $ts_a, $ts_b );
					}
				);

				// Remove oldest entries, keeping the most recent MAX_TASKS items.
				$tasks = array_slice( $tasks, -self::MAX_TASKS, null, true );
			}
		}

		update_option( self::TASKS_OPTION, $tasks, false );
	}

	/**
	 * Check if a state is terminal.
	 *
	 * @param string $state The state to check.
	 * @return bool True if the state is terminal.
	 */
	public static function is_terminal_state( $state ) {
		return in_array( $state, self::TERMINAL_STATES, true );
	}
}
