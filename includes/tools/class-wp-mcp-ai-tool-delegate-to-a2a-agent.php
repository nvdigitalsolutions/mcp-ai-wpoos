<?php
/**
 * A2A Delegate to Agent tool.
 *
 * Enables NV oOS assistants to delegate tasks to remote A2A-compliant
 * agents, extending the existing delegation pattern to external agents.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 * @see       https://a2a-protocol.org/latest/specification/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for delegating tasks to remote A2A agents.
 */
class WP_MCP_AI_Tool_Delegate_To_A2A_Agent implements WP_MCP_AI_Tool_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Maximum poll attempts for task completion.
	 *
	 * @var int
	 */
	const MAX_POLL_ATTEMPTS = 30;

	/**
	 * Poll interval in seconds.
	 *
	 * @var int
	 */
	const POLL_INTERVAL = 2;

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'delegate_to_a2a_agent';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Delegate to A2A Agent', 'mcp-ai-wpoos' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Delegate a task to a remote A2A-compliant agent. Discovers the agent, sends a message, and returns the result. Use this when the task requires capabilities available on an external agent.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array JSON Schema for the tool parameters.
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'agent_url'        => array(
					'type'        => 'string',
					'description' => __( 'The base URL of the remote A2A agent (e.g., https://example.com). The tool will discover the agent via /.well-known/agent.json.', 'mcp-ai-wpoos' ),
				),
				'task_description' => array(
					'type'        => 'string',
					'description' => __( 'A clear description of the task to delegate to the remote agent.', 'mcp-ai-wpoos' ),
				),
				'context'          => array(
					'type'        => 'string',
					'description' => __( 'Additional context to provide to the remote agent.', 'mcp-ai-wpoos' ),
				),
				'auth_type'        => array(
					'type'        => 'string',
					'description' => __( 'Authentication type: bearer, apiKey, or none.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'bearer', 'apiKey', 'none' ),
					'default'     => 'none',
				),
				'auth_token'       => array(
					'type'        => 'string',
					'description' => __( 'Authentication token or API key for the remote agent.', 'mcp-ai-wpoos' ),
				),
				'wait_for_result'  => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to wait for the task to complete before returning. Default true.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
			),
			'required'   => array( 'agent_url', 'task_description' ),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments The tool arguments.
	 * @param array $context   The execution context.
	 * @return array|string The tool result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$agent_url        = isset( $arguments['agent_url'] ) ? $arguments['agent_url'] : '';
		$task_description = isset( $arguments['task_description'] ) ? $arguments['task_description'] : '';
		$extra_context    = isset( $arguments['context'] ) ? $arguments['context'] : '';
		$auth_type        = isset( $arguments['auth_type'] ) ? $arguments['auth_type'] : 'none';
		$auth_token       = isset( $arguments['auth_token'] ) ? $arguments['auth_token'] : '';
		$wait_for_result  = isset( $arguments['wait_for_result'] ) ? (bool) $arguments['wait_for_result'] : true;

		if ( empty( $agent_url ) ) {
			return new WP_Error(
				'wp_mcp_ai_error',
				__( 'Agent URL is required.', 'mcp-ai-wpoos' )
			);
		}

		if ( empty( $task_description ) ) {
			return new WP_Error(
				'wp_mcp_ai_error',
				__( 'Task description is required.', 'mcp-ai-wpoos' )
			);
		}

		// Step 1: Discover the remote agent.
		$agent_card = WP_MCP_AI_A2A_Client::discover_agent( $agent_url );
		if ( is_wp_error( $agent_card ) ) {
			return new WP_Error(
				'wp_mcp_ai_error',
				$agent_card->get_error_message(),
				array( 'step' => 'discovery' )
			);
		}

		// Determine the A2A endpoint URL from the agent card.
		$a2a_endpoint = isset( $agent_card['url'] ) ? $agent_card['url'] : '';
		if ( empty( $a2a_endpoint ) ) {
			return new WP_Error(
				'wp_mcp_ai_error',
				__( 'Agent Card does not specify an A2A endpoint URL.', 'mcp-ai-wpoos' ),
				array( 'step' => 'discovery' )
			);
		}

		// Build the message text.
		$message_text = $task_description;
		if ( ! empty( $extra_context ) ) {
			$message_text .= "\n\nContext:\n" . $extra_context;
		}

		// Build auth options.
		$auth_options = array();
		if ( 'none' !== $auth_type && ! empty( $auth_token ) ) {
			$auth_options = array(
				'type'  => $auth_type,
				'token' => $auth_token,
				'key'   => $auth_token,
			);
		}

		// Step 2: Send the message.
		$result = WP_MCP_AI_A2A_Client::send_message(
			$a2a_endpoint,
			$message_text,
			array(
				'auth' => $auth_options,
			)
		);

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				'wp_mcp_ai_error',
				$result->get_error_message(),
				array( 'step' => 'send_message' )
			);
		}

		// If the response is a direct message (no task), return immediately.
		if ( isset( $result['kind'] ) && 'message' === $result['kind'] ) {
			return self::format_message_result( $result, $agent_card );
		}

		// If it's a task, check if we should wait for completion.
		if ( isset( $result['kind'] ) && 'task' === $result['kind'] ) {
			$task = $result;

			// If already in a terminal state or we don't want to wait, return now.
			if ( ! $wait_for_result || WP_MCP_AI_A2A_Task_Manager::is_terminal_state( $task['status']['state'] ) ) {
				return self::format_task_result( $task, $agent_card );
			}

			// Step 3: Poll for completion.
			$task_id = $task['id'];
			$attempt = 0;

			while ( $attempt < self::MAX_POLL_ATTEMPTS ) {
				sleep( self::POLL_INTERVAL );

				$task = WP_MCP_AI_A2A_Client::get_task(
					$a2a_endpoint,
					$task_id,
					array( 'auth' => $auth_options )
				);

				if ( is_wp_error( $task ) ) {
					return new WP_Error(
						'wp_mcp_ai_error',
						$task->get_error_message(),
						array( 'step' => 'poll' )
					);
				}

				if ( isset( $task['status']['state'] ) && WP_MCP_AI_A2A_Task_Manager::is_terminal_state( $task['status']['state'] ) ) {
					return self::format_task_result( $task, $agent_card );
				}

				++$attempt;
			}

			// Timeout.
			return new WP_Error(
				'wp_mcp_ai_error',
				__( 'Task did not complete within the polling window.', 'mcp-ai-wpoos' ),
				array(
					'task_id' => $task_id,
					'state'   => isset( $task['status']['state'] ) ? $task['status']['state'] : 'unknown',
					'agent'   => $agent_card['name'],
					'step'    => 'poll_timeout',
				)
			);
		}

		// Unknown response format.
		return array(
			'success'  => true,
			'response' => $result,
			'agent'    => isset( $agent_card['name'] ) ? $agent_card['name'] : '',
		);
	}

	/**
	 * Format a message result for return.
	 *
	 * @param array $message    The A2A Message.
	 * @param array $agent_card The Agent Card.
	 * @return array Formatted result.
	 */
	protected static function format_message_result( $message, $agent_card ) {
		$text = '';
		if ( isset( $message['parts'] ) && is_array( $message['parts'] ) ) {
			foreach ( $message['parts'] as $part ) {
				if ( isset( $part['text'] ) ) {
					$text .= $part['text'] . "\n";
				}
			}
		}

		return array(
			'success' => true,
			'type'    => 'message',
			'content' => trim( $text ),
			'agent'   => isset( $agent_card['name'] ) ? $agent_card['name'] : '',
		);
	}

	/**
	 * Format a task result for return.
	 *
	 * @param array $task       The A2A Task.
	 * @param array $agent_card The Agent Card.
	 * @return array Formatted result.
	 */
	protected static function format_task_result( $task, $agent_card ) {
		$result = array(
			'success' => 'completed' === ( $task['status']['state'] ?? '' ),
			'type'    => 'task',
			'task_id' => $task['id'],
			'state'   => $task['status']['state'] ?? 'unknown',
			'agent'   => isset( $agent_card['name'] ) ? $agent_card['name'] : '',
		);

		// Extract content from history.
		if ( isset( $task['history'] ) && is_array( $task['history'] ) ) {
			$agent_messages = array_filter(
				$task['history'],
				function ( $msg ) {
					return isset( $msg['role'] ) && 'agent' === $msg['role'];
				}
			);

			$last_agent_msg = end( $agent_messages );
			if ( $last_agent_msg && isset( $last_agent_msg['parts'] ) ) {
				$text = '';
				foreach ( $last_agent_msg['parts'] as $part ) {
					if ( isset( $part['text'] ) ) {
						$text .= $part['text'] . "\n";
					}
				}
				$result['content'] = trim( $text );
			}
		}

		// Include artifacts.
		if ( isset( $task['artifacts'] ) && ! empty( $task['artifacts'] ) ) {
			$result['artifacts'] = $task['artifacts'];
		}

		return $result;
	}
}
