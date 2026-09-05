<?php
/**
 * WordPress-specific tool for delegating tasks to remote A2A agents.
 *
 * Lives in the WordPress adapter because it directly depends on
 * WP_MCP_AI_A2A_Client and WP_MCP_AI_A2A_Task_Manager.
 *
 * @package Nvoos\WordPress
 * @since   1.0.0
 * @license GPL-3.0-or-later
 * @see     https://a2a-protocol.org/latest/specification/
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Tool;

use Nvoos\Core\Tool\AbstractTool;

/**
 * Delegates tasks to remote A2A-compliant agents.
 *
 * Discovers the agent via /.well-known/agent.json, sends a message,
 * optionally polls for task completion, and returns the result.
 */
class DelegateToA2aAgentTool extends AbstractTool {

	private const MAX_POLL_ATTEMPTS = 30;
	private const POLL_INTERVAL     = 2;

	public function getSlug(): string {
		return 'delegate_to_a2a_agent';
	}

	public function getName(): string {
		return 'Delegate to A2A Agent';
	}

	public function getDescription(): string {
		return 'Delegate a task to a remote A2A-compliant agent. Discovers the agent, sends a message, and returns the result. Use when the task requires capabilities available on an external agent.';
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'agent_url'        => array(
					'type'        => 'string',
					'description' => 'Base URL of the remote A2A agent (e.g., https://example.com).',
				),
				'task_description' => array(
					'type'        => 'string',
					'description' => 'Clear description of the task to delegate.',
				),
				'context'          => array(
					'type'        => 'string',
					'description' => 'Additional context for the remote agent.',
				),
				'auth_type'        => array(
					'type'        => 'string',
					'description' => 'Authentication type.',
					'enum'        => array( 'bearer', 'apiKey', 'none' ),
					'default'     => 'none',
				),
				'auth_token'       => array(
					'type'        => 'string',
					'description' => 'Authentication token or API key.',
				),
				'wait_for_result'  => array(
					'type'        => 'boolean',
					'description' => 'Wait for task completion before returning.',
					'default'     => true,
				),
			),
			'required'   => array( 'agent_url', 'task_description' ),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$agent_url        = $arguments['agent_url'] ?? '';
		$task_description = $arguments['task_description'] ?? '';
		$extra_context    = $arguments['context'] ?? '';
		$auth_type        = $arguments['auth_type'] ?? 'none';
		$auth_token       = $arguments['auth_token'] ?? '';
		$wait_for_result  = (bool) ( $arguments['wait_for_result'] ?? true );

		if ( '' === $agent_url ) {
			return $this->errors->validationFailed(
				'Agent URL is required.',
				array( 'agent_url' => array( 'This field is required.' ) ),
			);
		}

		if ( '' === $task_description ) {
			return $this->errors->validationFailed(
				'Task description is required.',
				array( 'task_description' => array( 'This field is required.' ) ),
			);
		}

		// Standalone gate: A2A transport lives in the base plugin.
		if ( ! \defined( 'WP_MCP_AI_PATH' ) || ! \class_exists( 'WP_MCP_AI_A2A_Client' ) ) {
			return $this->errors->create(
				'wp_mcp_ai_a2a_unavailable',
				'A2A client is unavailable in this install.',
			);
		}

		// Step 1: Discover the remote agent.
		$agent_card = \WP_MCP_AI_A2A_Client::discover_agent( $agent_url );
		if ( \is_wp_error( $agent_card ) ) {
			return $this->errors->create(
				$agent_card->get_error_code(),
				$agent_card->get_error_message(),
			);
		}

		$a2a_endpoint = $agent_card['url'] ?? '';
		if ( '' === $a2a_endpoint ) {
			return $this->errors->create(
				'wp_mcp_ai_error',
				'Agent Card does not specify an A2A endpoint URL.',
			);
		}

		// Build message text.
		$message_text = $task_description;
		if ( '' !== $extra_context ) {
			$message_text .= "\n\nContext:\n" . $extra_context;
		}

		// Build auth options.
		$auth_options = array();
		if ( 'none' !== $auth_type && '' !== $auth_token ) {
			$auth_options = array(
				'type'  => $auth_type,
				'token' => $auth_token,
				'key'   => $auth_token,
			);
		}

		// Step 2: Send the message.
		$result = \WP_MCP_AI_A2A_Client::send_message(
			$a2a_endpoint,
			$message_text,
			array( 'auth' => $auth_options )
		);

		if ( \is_wp_error( $result ) ) {
			return $this->errors->create(
				$result->get_error_code(),
				$result->get_error_message(),
			);
		}

		// Direct message response.
		if ( isset( $result['kind'] ) && 'message' === $result['kind'] ) {
			return $this->formatMessageResult( $result, $agent_card );
		}

		// Task response with optional polling.
		if ( isset( $result['kind'] ) && 'task' === $result['kind'] ) {
			$task = $result;

			if ( ! $wait_for_result || \WP_MCP_AI_A2A_Task_Manager::is_terminal_state( $task['status']['state'] ) ) {
				return $this->formatTaskResult( $task, $agent_card );
			}

			// Step 3: Poll for completion.
			$task_id = $task['id'];
			$attempt = 0;

			while ( $attempt < self::MAX_POLL_ATTEMPTS ) {
				\sleep( self::POLL_INTERVAL );

				$task = \WP_MCP_AI_A2A_Client::get_task(
					$a2a_endpoint,
					$task_id,
					array( 'auth' => $auth_options )
				);

				if ( \is_wp_error( $task ) ) {
					return $this->errors->create(
						$task->get_error_code(),
						$task->get_error_message(),
					);
				}

				if (
					isset( $task['status']['state'] )
					&& \WP_MCP_AI_A2A_Task_Manager::is_terminal_state( $task['status']['state'] )
				) {
					return $this->formatTaskResult( $task, $agent_card );
				}

				++$attempt;
			}

			return $this->errors->create(
				'wp_mcp_ai_error',
				'Task did not complete within the polling window.',
			);
		}

		// Unknown response format.
		return $this->success(
			'Task delegated.',
			array(
				'response' => $result,
				'agent'    => $agent_card['name'] ?? '',
			)
		);
	}

	/**
	 * Format a message-kind result.
	 */
	private function formatMessageResult( array $message, array $agent_card ): array {
		$text = '';
		if ( isset( $message['parts'] ) && \is_array( $message['parts'] ) ) {
			foreach ( $message['parts'] as $part ) {
				if ( isset( $part['text'] ) ) {
					$text .= $part['text'] . "\n";
				}
			}
		}

		return $this->success(
			'Agent responded.',
			array(
				'type'    => 'message',
				'content' => \trim( $text ),
				'agent'   => $agent_card['name'] ?? '',
			)
		);
	}

	/**
	 * Format a task-kind result.
	 */
	private function formatTaskResult( array $task, array $agent_card ): array {
		$result = array(
			'type'    => 'task',
			'task_id' => $task['id'],
			'state'   => $task['status']['state'] ?? 'unknown',
			'agent'   => $agent_card['name'] ?? '',
		);

		// Extract content from agent messages in history.
		if ( isset( $task['history'] ) && \is_array( $task['history'] ) ) {
			$agent_messages = \array_filter(
				$task['history'],
				static function ( array $msg ): bool {
					return isset( $msg['role'] ) && 'agent' === $msg['role'];
				}
			);

			$last = \end( $agent_messages );
			if ( $last && isset( $last['parts'] ) ) {
				$text = '';
				foreach ( $last['parts'] as $part ) {
					if ( isset( $part['text'] ) ) {
						$text .= $part['text'] . "\n";
					}
				}
				$result['content'] = \trim( $text );
			}
		}

		if ( isset( $task['artifacts'] ) && ! empty( $task['artifacts'] ) ) {
			$result['artifacts'] = $task['artifacts'];
		}

		$is_completed = 'completed' === ( $task['status']['state'] ?? '' );

		return $this->success(
			$is_completed ? 'Task completed.' : 'Task status: ' . $result['state'],
			$result
		);
	}
}
