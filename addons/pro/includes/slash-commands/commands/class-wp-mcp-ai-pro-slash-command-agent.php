<?php
/**
 * Agent Pro Slash Command
 *
 * A2A Agent delegation: list tasks, check status, cancel, send messages, discover agents.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Slash_Commands
 * @since 2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Agent Command Class
 *
 * Flags:
 *   --list              List active A2A tasks (default)
 *   --status=<task_id>  Get task status
 *   --cancel=<task_id>  Cancel a task
 *   --send=<agent_url>  Send message to remote agent (requires --message=<text>)
 *   --discover=<url>    Discover agent capabilities
 *   --message=<text>    Message text for --send
 *   --limit=<n>         Max results (default 10)
 *   --json              JSON output
 *
 * @since 2.1.0
 */
class WP_MCP_AI_Pro_Slash_Command_Agent {

	/**
	 * Execute agent command.
	 *
	 * @param array $args    Positional arguments.
	 * @param array $flags   Command flags.
	 * @param array $context Execution context.
	 * @return string|array|WP_Error
	 */
	public function execute( $args, $flags, $context ) {
		// Block guest requests.
		if ( ! empty( $context['guest_request'] ) ) {
			return new WP_Error(
				'guest_forbidden',
				__( 'This command requires authentication.', 'mcp-ai-wpoos-pro' )
			);
		}

		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$as_json = isset( $flags['json'] );
		$limit   = isset( $flags['limit'] ) ? absint( $flags['limit'] ) : 10;
		$limit   = max( 1, min( 100, $limit ) );

		// Require edit_posts.
		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'forbidden',
				__( 'Permission denied. Requires edit_posts capability.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Validate A2A classes exist.
		$has_task_manager = class_exists( 'WP_MCP_AI_A2A_Task_Manager' );
		$has_client       = class_exists( 'WP_MCP_AI_A2A_Client' );

		// --discover: discover remote agent capabilities.
		if ( isset( $flags['discover'] ) ) {
			if ( ! $has_client ) {
				return new WP_Error( 'service_unavailable', __( 'A2A Client service is not available.', 'mcp-ai-wpoos-pro' ) );
			}
			$agent_url = esc_url_raw( $flags['discover'] );
			if ( empty( $agent_url ) ) {
				return new WP_Error( 'missing_url', __( 'Agent URL required. Usage: --discover=<url>', 'mcp-ai-wpoos-pro' ) );
			}
			return $this->action_discover( $agent_url, $as_json );
		}

		// --send: send message to remote agent.
		if ( isset( $flags['send'] ) ) {
			if ( ! $has_client ) {
				return new WP_Error( 'service_unavailable', __( 'A2A Client service is not available.', 'mcp-ai-wpoos-pro' ) );
			}
			$agent_url = esc_url_raw( $flags['send'] );
			if ( empty( $agent_url ) ) {
				return new WP_Error( 'missing_url', __( 'Agent URL required. Usage: --send=<url> --message=<text>', 'mcp-ai-wpoos-pro' ) );
			}
			$message = isset( $flags['message'] ) ? sanitize_textarea_field( $flags['message'] ) : '';
			if ( empty( $message ) && ! empty( $args[0] ) ) {
				$message = sanitize_textarea_field( $args[0] );
			}
			if ( empty( $message ) ) {
				return new WP_Error( 'missing_message', __( 'Message text required. Usage: --message=<text>', 'mcp-ai-wpoos-pro' ) );
			}
			return $this->action_send( $agent_url, $message, $context, $as_json );
		}

		// Remaining operations require task manager.
		if ( ! $has_task_manager ) {
			return new WP_Error( 'service_unavailable', __( 'A2A Task Manager service is not available.', 'mcp-ai-wpoos-pro' ) );
		}

		// --cancel: cancel a task.
		if ( isset( $flags['cancel'] ) ) {
			$task_id = sanitize_text_field( $flags['cancel'] );
			if ( empty( $task_id ) ) {
				return new WP_Error( 'missing_id', __( 'Task ID required. Usage: --cancel=<task_id>', 'mcp-ai-wpoos-pro' ) );
			}
			return $this->action_cancel( $task_id, $as_json );
		}

		// --status: get task status.
		if ( isset( $flags['status'] ) ) {
			$task_id = sanitize_text_field( $flags['status'] );
			if ( empty( $task_id ) ) {
				return new WP_Error( 'missing_id', __( 'Task ID required. Usage: --status=<task_id>', 'mcp-ai-wpoos-pro' ) );
			}
			return $this->action_status( $task_id, $as_json );
		}

		// Default: list tasks.
		return $this->action_list( $limit, $as_json );
	}

	/**
	 * List active A2A tasks.
	 *
	 * @param int  $limit   Max tasks.
	 * @param bool $as_json JSON output.
	 * @return string|array|WP_Error
	 */
	private function action_list( $limit, $as_json ) {
		$tasks = WP_MCP_AI_A2A_Task_Manager::list_tasks( array( 'limit' => $limit ) );

		if ( is_wp_error( $tasks ) ) {
			return $tasks;
		}

		$tasks = is_array( $tasks ) ? $tasks : array();

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => __( 'A2A tasks retrieved.', 'mcp-ai-wpoos-pro' ),
				'data'    => $tasks,
			);
		}

		if ( empty( $tasks ) ) {
			return __( 'No active A2A tasks found.', 'mcp-ai-wpoos-pro' );
		}

		$output  = '## ' . __( 'A2A Tasks', 'mcp-ai-wpoos-pro' ) . "\n\n";
		$output .= "| ID | Status | Created |\n";
		$output .= "|----|--------|---------|\n";

		foreach ( $tasks as $task ) {
			$tid     = isset( $task['id'] ) ? esc_html( $task['id'] ) : '–';
			$status  = isset( $task['status'] ) ? esc_html( $task['status'] ) : '–';
			$created = isset( $task['created_at'] ) ? esc_html( $task['created_at'] ) : '–';
			$output .= "| {$tid} | {$status} | {$created} |\n";
		}

		return $output;
	}

	/**
	 * Get status of a specific task.
	 *
	 * @param string $task_id Task ID.
	 * @param bool   $as_json JSON output.
	 * @return string|array|WP_Error
	 */
	private function action_status( $task_id, $as_json ) {
		$tasks = WP_MCP_AI_A2A_Task_Manager::list_tasks( array( 'id' => $task_id ) );

		if ( is_wp_error( $tasks ) ) {
			return $tasks;
		}

		$task = is_array( $tasks ) && ! empty( $tasks ) ? reset( $tasks ) : null;

		if ( ! $task ) {
			return new WP_Error( 'not_found', __( 'Task not found.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => __( 'Task status retrieved.', 'mcp-ai-wpoos-pro' ),
				'data'    => $task,
			);
		}

		$tid     = isset( $task['id'] ) ? esc_html( $task['id'] ) : esc_html( $task_id );
		$status  = isset( $task['status'] ) ? esc_html( $task['status'] ) : '–';
		$created = isset( $task['created_at'] ) ? esc_html( $task['created_at'] ) : '–';
		$updated = isset( $task['updated_at'] ) ? esc_html( $task['updated_at'] ) : '–';

		$output  = "## Task: {$tid}\n\n";
		$output .= "- **Status:** {$status}\n";
		$output .= "- **Created:** {$created}\n";
		$output .= "- **Updated:** {$updated}\n";

		return $output;
	}

	/**
	 * Cancel a task.
	 *
	 * @param string $task_id Task ID.
	 * @param bool   $as_json JSON output.
	 * @return string|array|WP_Error
	 */
	private function action_cancel( $task_id, $as_json ) {
		$result = WP_MCP_AI_A2A_Task_Manager::cancel_task( $task_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => sprintf(
					/* translators: %s: task ID */
					__( 'Task "%s" cancelled.', 'mcp-ai-wpoos-pro' ),
					esc_html( $task_id )
				),
				'data'    => array( 'task_id' => $task_id ),
			);
		}

		return sprintf(
			/* translators: %s: task ID */
			__( '✅ Task "%s" cancelled.', 'mcp-ai-wpoos-pro' ),
			esc_html( $task_id )
		);
	}

	/**
	 * Discover a remote agent's capabilities.
	 *
	 * @param string $agent_url Agent URL.
	 * @param bool   $as_json   JSON output.
	 * @return string|array|WP_Error
	 */
	private function action_discover( $agent_url, $as_json ) {
		$result = WP_MCP_AI_A2A_Client::discover_agent( $agent_url );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => __( 'Agent capabilities discovered.', 'mcp-ai-wpoos-pro' ),
				'data'    => $result,
			);
		}

		$output  = '## ' . __( 'Agent Capabilities', 'mcp-ai-wpoos-pro' ) . "\n\n";
		$output .= '- **URL:** ' . esc_html( $agent_url ) . "\n";

		if ( is_array( $result ) ) {
			foreach ( $result as $key => $value ) {
				$output .= '- **' . esc_html( ucfirst( $key ) ) . ':** ' . esc_html( is_scalar( $value ) ? $value : wp_json_encode( $value ) ) . "\n";
			}
		}

		return $output;
	}

	/**
	 * Send a message to a remote agent.
	 *
	 * @param string $agent_url Agent URL.
	 * @param string $message   Message text.
	 * @param array  $context   Execution context.
	 * @param bool   $as_json   JSON output.
	 * @return string|array|WP_Error
	 */
	private function action_send( $agent_url, $message, $context, $as_json ) {
		$result = WP_MCP_AI_A2A_Client::send_message( $agent_url, $message, $context );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => __( 'Message sent to agent.', 'mcp-ai-wpoos-pro' ),
				'data'    => $result,
			);
		}

		$output  = '## ' . __( 'Message Sent', 'mcp-ai-wpoos-pro' ) . "\n\n";
		$output .= '- **Agent:** ' . esc_html( $agent_url ) . "\n";
		$output .= '- **Message:** ' . esc_html( $message ) . "\n";

		if ( is_array( $result ) && isset( $result['task_id'] ) ) {
			$output .= '- **Task ID:** ' . esc_html( $result['task_id'] ) . "\n";
		}

		return $output;
	}
}
