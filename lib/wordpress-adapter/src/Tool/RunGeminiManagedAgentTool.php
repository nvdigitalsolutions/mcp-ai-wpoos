<?php
/**
 * WordPress-specific tool for running Gemini Managed Agents.
 *
 * Lives in the WordPress adapter because it directly depends on
 * WP_MCP_AI_Gemini_Managed_Agent_Service.
 *
 * @package Nvoos\WordPress
 * @since   1.0.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Tool;

use Nvoos\Core\Tool\AbstractTool;
use WP_MCP_AI_Gemini_Managed_Agent_Service;

/**
 * Creates and manages agent sessions with isolated Linux containers.
 *
 * Agents reason, plan, call tools, and iterate toward complex goals.
 * Sessions persist for 24 hours — continue work by passing the session_id.
 */
class RunGeminiManagedAgentTool extends AbstractTool {

	public function getSlug(): string {
		return 'run_gemini_managed_agent';
	}

	public function getName(): string {
		return 'Run Gemini Managed Agent';
	}

	public function getDescription(): string {
		return 'Creates and runs tasks with a managed AI agent powered by Gemini. The agent operates in an isolated Linux container with persistent files, code execution, and access to all NV oOS tools. Sessions persist for 24 hours. Use "create" to set up a session, "run" to execute, "status" to check, or "terminate" to clean up.';
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getParametersSchema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'operation'      => array(
					'type'        => 'string',
					'description' => 'Operation: create, run, status, list, or terminate.',
					'enum'        => array( 'create', 'run', 'status', 'list', 'terminate' ),
				),
				'session_id'     => array(
					'type'        => 'string',
					'description' => 'Session ID from a previous create. Required for run, status, terminate.',
				),
				'task'           => array(
					'type'        => 'string',
					'description' => 'The task to execute.',
				),
				'system_prompt'  => array(
					'type'        => 'string',
					'description' => 'System instructions for the agent role and constraints.',
				),
				'tool_slugs'     => array(
					'type'        => 'array',
					'description' => 'Tool slugs the agent can use. Empty = all available.',
					'items'       => array( 'type' => 'string' ),
				),
				'max_iterations' => array(
					'type'        => 'integer',
					'description' => 'Max iterations (1-100). Default: 10.',
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 10,
				),
				'timeout'        => array(
					'type'        => 'integer',
					'description' => 'Timeout in seconds (30-3600). Default: 300.',
					'minimum'     => 30,
					'maximum'     => 3600,
					'default'     => 300,
				),
				'model'          => array(
					'type'        => 'string',
					'description' => 'Model to use. Defaults to gemini-3.5-flash.',
					'default'     => 'gemini-3.5-flash',
				),
			),
			'required'   => array( 'operation' ),
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$operation     = \sanitize_text_field( $arguments['operation'] ?? '' );
		$session_id    = \sanitize_text_field( $arguments['session_id'] ?? '' );
		$task          = \sanitize_textarea_field( $arguments['task'] ?? '' );
		$system_prompt = \sanitize_textarea_field( $arguments['system_prompt'] ?? '' );
		$tool_slugs    = isset( $arguments['tool_slugs'] )
			? \array_map( 'sanitize_key', (array) $arguments['tool_slugs'] )
			: array();
		$max_iter      = \absint( $arguments['max_iterations'] ?? 10 );
		$timeout       = \absint( $arguments['timeout'] ?? 300 );
		$model         = \sanitize_text_field( $arguments['model'] ?? 'gemini-3.5-flash' );

		$service = new WP_MCP_AI_Gemini_Managed_Agent_Service();

		switch ( $operation ) {
			case 'create':
				return $this->handleCreate( $service, $task, $system_prompt, $tool_slugs, $max_iter, $timeout, $model );
			case 'run':
				return $this->handleRun( $service, $session_id, $task, $timeout );
			case 'status':
				return $this->handleStatus( $service, $session_id );
			case 'list':
				return $this->handleList( $service );
			case 'terminate':
				return $this->handleTerminate( $service, $session_id );
			default:
				return $this->errors->validationFailed(
					\sprintf(
						'Invalid operation: %s. Valid: create, run, status, list, terminate.',
						\esc_html( $operation )
					),
					array( 'operation' => array( 'Invalid value.' ) ),
				);
		}
	}

	/**
	 * Create a new agent session. If a task is provided, runs it immediately.
	 */
	private function handleCreate(
		WP_MCP_AI_Gemini_Managed_Agent_Service $service,
		string $task,
		string $system_prompt,
		array $tool_slugs,
		int $max_iter,
		int $timeout,
		string $model
	): mixed {
		$create_args = array(
			'system_prompt'  => $system_prompt,
			'tool_slugs'     => $tool_slugs,
			'model'          => $model,
			'max_iterations' => $max_iter,
			'timeout'        => $timeout,
		);

		$result = $service->create_session( $create_args );

		if ( \is_wp_error( $result ) ) {
			return $this->errors->create(
				$result->get_error_code(),
				$result->get_error_message(),
			);
		}

		if ( '' !== $task ) {
			$task_result = $service->run_task( array(
				'session_id' => $result['session_id'],
				'task'       => $task,
				'timeout'    => $timeout,
			) );

			if ( \is_wp_error( $task_result ) ) {
				return $this->success(
					\sprintf(
						'Session created (ID: %s) but task failed: %s. Session is still active.',
						\esc_html( $result['session_id'] ),
						\esc_html( $task_result->get_error_message() )
					),
					\array_merge( $result, array(
						'task_error' => $task_result->get_error_message(),
					) )
				);
			}

			return $this->success(
				$task_result['message'] ?? 'Task completed.',
				\array_merge( $result, $task_result )
			);
		}

		return $this->success(
			\sprintf(
				'Agent session created. Session ID: %s. Use this ID with "run" to execute tasks.',
				\esc_html( $result['session_id'] )
			),
			$result
		);
	}

	/**
	 * Execute a task in an existing session.
	 */
	private function handleRun(
		WP_MCP_AI_Gemini_Managed_Agent_Service $service,
		string $session_id,
		string $task,
		int $timeout
	): mixed {
		if ( '' === $session_id ) {
			return $this->errors->validationFailed(
				'Session ID is required for the "run" operation.',
				array( 'session_id' => array( 'This field is required.' ) ),
			);
		}

		if ( '' === $task ) {
			return $this->errors->validationFailed(
				'A task description is required for the "run" operation.',
				array( 'task' => array( 'This field is required.' ) ),
			);
		}

		$result = $service->run_task( array(
			'session_id' => $session_id,
			'task'       => $task,
			'timeout'    => $timeout,
		) );

		if ( \is_wp_error( $result ) ) {
			if ( 'wp_mcp_ai_managed_agents_unavailable' === $result->get_error_code() ) {
				return $this->success(
					$result->get_error_message(),
					array(
						'session_id' => $session_id,
						'status'     => 'unavailable',
						'suggestion' => 'The Managed Agents API will be available in the coming weeks. Use individual tools directly in the meantime.',
					)
				);
			}

			return $this->errors->create(
				$result->get_error_code(),
				$result->get_error_message(),
			);
		}

		return $this->success(
			$result['message'] ?? 'Task completed.',
			$result
		);
	}

	/**
	 * Check the status of a session.
	 */
	private function handleStatus(
		WP_MCP_AI_Gemini_Managed_Agent_Service $service,
		string $session_id
	): mixed {
		if ( '' === $session_id ) {
			return $this->errors->validationFailed(
				'Session ID is required for the "status" operation.',
				array( 'session_id' => array( 'This field is required.' ) ),
			);
		}

		$result = $service->get_session( $session_id );

		if ( \is_wp_error( $result ) ) {
			return $this->errors->create(
				$result->get_error_code(),
				$result->get_error_message(),
			);
		}

		return $this->success(
			\sprintf( 'Session status: %s', \esc_html( $result['status'] ) ),
			$result
		);
	}

	/**
	 * List all active sessions.
	 */
	private function handleList( WP_MCP_AI_Gemini_Managed_Agent_Service $service ): array {
		$sessions = $service->list_sessions();

		if ( empty( $sessions ) ) {
			return $this->success(
				'No active agent sessions.',
				array( 'sessions' => array() )
			);
		}

		return $this->success(
			\sprintf( '%d active agent session(s).', \count( $sessions ) ),
			array( 'sessions' => $sessions )
		);
	}

	/**
	 * Terminate a session and clean up resources.
	 */
	private function handleTerminate(
		WP_MCP_AI_Gemini_Managed_Agent_Service $service,
		string $session_id
	): mixed {
		if ( '' === $session_id ) {
			return $this->errors->validationFailed(
				'Session ID is required for the "terminate" operation.',
				array( 'session_id' => array( 'This field is required.' ) ),
			);
		}

		$result = $service->terminate_session( $session_id );

		if ( \is_wp_error( $result ) ) {
			return $this->errors->create(
				$result->get_error_code(),
				$result->get_error_message(),
			);
		}

		return $this->success( 'Agent session terminated.', $result );
	}
}
