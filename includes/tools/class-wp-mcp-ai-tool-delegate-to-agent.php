<?php
/**
 * Tool for delegating tasks to agents.
 *
 * Allows AI assistants to delegate subtasks to specialized agents within a team.
 * Part of DeepSeek V4-inspired multi-agent orchestration enhancements.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Delegates a subtask to a specialized agent.
 *
 * This tool enables AI models to assign specific subtasks to appropriate agent roles
 * (planner, executor, critic) by profession ID, assistant name/slug, or virtual agent ID.
 * The agent will execute the task using its specialized tools and expertise.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Delegate_To_Agent implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'delegate_to_agent';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Delegate to Agent', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Delegates a subtask to a specialized agent. The agent will use its expertise and tools to complete the task. Use this for complex workflows where different specialists handle different aspects of the work.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'agent_id'        => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'The target agent to delegate to. Accepts: (1) numeric assistant post ID, (2) assistant name or slug (e.g., "content-writer" or "Content Writer"), (3) profession name/slug (resolves to the profession\'s associated assistant), or (4) virtual agent string ID from create_agent_team (e.g., "virtual_executor_abc123").', 'mcp-ai-wpoos' ),
				),
				'task'            => array(
					'type'        => 'string',
					'description' => __( 'Clear description of the subtask to be completed', 'mcp-ai-wpoos' ),
				),
				'context'         => array(
					'type'        => 'object',
					'description' => __( 'Shared context from parent task', 'mcp-ai-wpoos' ),
					'properties'  => array(
						'parent_task_id' => array(
							'type'        => 'string',
							'description' => __( 'ID of the parent task', 'mcp-ai-wpoos' ),
						),
						'dependencies'   => array(
							'type'        => 'array',
							'description' => __( 'IDs of subtasks that must complete first', 'mcp-ai-wpoos' ),
							'items'       => array( 'type' => 'string' ),
						),
						'shared_data'    => array(
							'type'        => 'object',
							'description' => __( 'Data to share with the agent', 'mcp-ai-wpoos' ),
						),
					),
				),
				'expected_output' => array(
					'type'        => 'object',
					'description' => __( 'Description of expected output format', 'mcp-ai-wpoos' ),
					'properties'  => array(
						'format' => array(
							'type'        => 'string',
							'description' => __( 'Expected format: text, json, html, markdown', 'mcp-ai-wpoos' ),
							'enum'        => array( 'text', 'json', 'html', 'markdown' ),
						),
						'fields' => array(
							'type'        => 'array',
							'description' => __( 'Required fields in the output', 'mcp-ai-wpoos' ),
							'items'       => array( 'type' => 'string' ),
						),
					),
				),
			),
			'required'             => array( 'agent_id', 'task' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Tool results.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate required arguments.
		if ( empty( $arguments['agent_id'] ) || empty( $arguments['task'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_error',
				__( 'Agent ID and task description are required.', 'mcp-ai-wpoos' )
			);
		}

		// agent_id can be an integer (real assistant), string name/slug, or virtual agent string.
		$raw_agent_id    = $arguments['agent_id'];
		$task            = sanitize_textarea_field( $arguments['task'] );
		$task_context    = isset( $arguments['context'] ) ? $arguments['context'] : array();
		$expected_output = isset( $arguments['expected_output'] ) ? $arguments['expected_output'] : array();

		// Resolve agent_id: numeric IDs and virtual_ strings pass through;
		// names/slugs/profession names are resolved to assistant post IDs.
		$resolution  = $this->resolve_agent_id( $raw_agent_id );
		$agent_id    = $resolution['agent_id'];
		$target_type = $resolution['target_type'];
		$resolved_by = $resolution['resolved_by'];

		if ( null === $agent_id ) {
			return new WP_Error(
				'wp_mcp_ai_error',
				$resolution['error']
			);
		}

		// Merge execution context with task context to preserve team_id and other workflow data.
		// This ensures virtual agents can be resolved properly.
		$merged_context = array_merge(
			$task_context,
			array_filter(
				array(
					'team_id'      => isset( $context['team_id'] ) ? $context['team_id'] : null,
					'workflow_id'  => isset( $context['workflow_id'] ) ? $context['workflow_id'] : null,
					'assistant_id' => isset( $context['assistant_id'] ) ? $context['assistant_id'] : null,
				)
			)
		);

		// Log delegation attempt for debugging.
		WP_MCP_AI_Logger::log_event(
			'agent_delegation_initiated',
			'Task delegation requested',
			array(
				'agent_id'    => $agent_id,
				'raw_input'   => $raw_agent_id,
				'target_type' => $target_type,
				'resolved_by' => $resolved_by,
				'is_virtual'  => 'virtual_agent' === $target_type,
				'team_id'     => isset( $merged_context['team_id'] ) ? $merged_context['team_id'] : 'none',
				'workflow_id' => isset( $merged_context['workflow_id'] ) ? $merged_context['workflow_id'] : 'none',
				'task'        => substr( $task, 0, 100 ) . ( strlen( $task ) > 100 ? '...' : '' ),
			)
		);

		// Get communication service.
		if ( ! class_exists( 'WP_MCP_AI_Agent_Communication_Service' ) ) {
			return new WP_Error(
				'wp_mcp_ai_error',
				__( 'Agent communication system not available.', 'mcp-ai-wpoos' )
			);
		}

		$communication_service = new WP_MCP_AI_Agent_Communication_Service();

		// Prepare task data.
		$task_data = array(
			'description'     => $task,
			'context'         => $merged_context,
			'expected_output' => $expected_output,
			'delegated_by'    => isset( $context['user_id'] ) ? $context['user_id'] : 0,
			'delegated_at'    => current_time( 'mysql' ),
		);

		// Delegate task inline (run_inline = true) so the parent assistant
		// receives the actual result rather than a fire-and-forget status.
		// This matches the synchronous behaviour of image generation and
		// other AI-interactive tools — the tool blocks until the target
		// agent completes, then returns the agent's response.
		$result = $communication_service->delegate_task(
			isset( $context['assistant_id'] ) ? $context['assistant_id'] : 0,
			$agent_id,
			$task_data,
			$merged_context,
			true // Run inline.
		);

		if ( is_wp_error( $result ) ) {
			// Log delegation failure.
			WP_MCP_AI_Logger::log_error(
				'agent_delegation_failed',
				'Agent delegation encountered an error',
				array(
					'agent_id'   => $agent_id,
					'error_code' => $result->get_error_code(),
					'error_msg'  => $result->get_error_message(),
					'team_id'    => isset( $merged_context['team_id'] ) ? $merged_context['team_id'] : 'none',
				)
			);

			return new WP_Error(
				'wp_mcp_ai_error',
				$result->get_error_message(),
				array( 'code' => $result->get_error_code() )
			);
		}

		$delegation_id = $result['delegation_id'];

		// Log successful delegation record creation.
		WP_MCP_AI_Logger::log_event(
			'agent_delegation_created',
			'Delegation record created; processing inline',
			array(
				'delegation_id' => $delegation_id,
				'agent_id'      => $agent_id,
				'agent_name'    => $result['agent_name'],
				'agent_role'    => $result['agent_role'],
				'mode'          => 'inline',
			)
		);

		// Process the delegation synchronously — the parent assistant
		// waits for the target agent to complete before continuing.
		// Virtual agents cannot be dispatched to the chat endpoint and
		// will fail inside process_pending_delegation(); the result is
		// still captured so callers always see a concrete outcome.
		WP_MCP_AI_Agent_Communication_Service::process_pending_delegation( $delegation_id );

		// Read the final result from the delegation transient.
		$delegation_key  = 'wp_mcp_ai_delegation_' . $delegation_id;
		$delegation_data = get_transient( $delegation_key );

		$final_status = is_array( $delegation_data ) && isset( $delegation_data['status'] )
			? $delegation_data['status']
			: 'unknown';

		// Log the final outcome.
		WP_MCP_AI_Logger::log_event(
			'agent_delegation_completed',
			'Inline delegation processing finished',
			array(
				'delegation_id' => $delegation_id,
				'agent_id'      => $agent_id,
				'status'        => $final_status,
			)
		);

		// Update workflow task status if this is part of a workflow.
		if ( ! empty( $merged_context['workflow_id'] ) && ! empty( $merged_context['task_name'] ) ) {
			if ( class_exists( 'WP_MCP_AI_Agent_Team_Orchestrator' ) ) {
				$orchestrator = new WP_MCP_AI_Agent_Team_Orchestrator();
				$orchestrator->update_workflow_task_status(
					$merged_context['workflow_id'],
					$merged_context['task_name'],
					'completed' === $final_status ? 'completed' : 'failed',
					array(
						'agent_id'      => $agent_id,
						'agent_name'    => $result['agent_name'],
						'delegation_id' => $delegation_id,
						'final_status'  => $final_status,
					)
				);
			}
		}

		// Build the response that the parent assistant sees.
		$response = array(
			'success'    => true,
			'message'    => __( 'Task delegated and completed.', 'mcp-ai-wpoos' ),
			'delegation' => array(
				'delegation_id' => $delegation_id,
				'agent_id'      => $agent_id,
				'agent_name'    => $result['agent_name'],
				'agent_role'    => $result['agent_role'],
				'target_type'   => $target_type,
				'task'          => $task,
				'status'        => $final_status,
				'delegated_at'  => $result['delegated_at'],
			),
		);

		// Surface the agent's actual response when available.
		if ( 'completed' === $final_status && isset( $delegation_data['result']['response'] ) ) {
			$response['agent_response'] = $delegation_data['result']['response'];
		} elseif ( 'failed' === $final_status && isset( $delegation_data['error'] ) ) {
			$response['error']   = $delegation_data['error'];
			$response['message'] = sprintf(
				/* translators: %s: error message from the target agent */
				__( 'Agent task failed: %s', 'mcp-ai-wpoos' ),
				$delegation_data['error']
			);
		}

		return $response;
	}


	/**
	 * Resolve an agent_id input to a concrete agent identifier.
	 *
	 * Accepts numeric post IDs, virtual agent strings, assistant names/slugs,
	 * and profession names/slugs. Returns the resolved ID, target type flag,
	 * and resolution method used.
	 *
	 * @since 1.1.0
	 *
	 * @param int|string $raw_agent_id Agent identifier from tool arguments.
	 * @return array {
	 *     @type int|string|null $agent_id    Resolved agent ID, or null on failure.
	 *     @type string         $target_type 'assistant', 'virtual_agent', or 'unknown'.
	 *     @type string         $resolved_by How the ID was resolved: 'numeric_id', 'virtual_prefix',
	 *                                        'assistant_slug', 'assistant_title', 'profession_name', 'none'.
	 *     @type string         $error       Error message when agent_id is null.
	 * }
	 */
	protected function resolve_agent_id( $raw_agent_id ) {
		// 1. Numeric ID — pass through as real assistant.
		if ( is_numeric( $raw_agent_id ) ) {
			return array(
				'agent_id'    => absint( $raw_agent_id ),
				'target_type' => 'assistant',
				'resolved_by' => 'numeric_id',
				'error'       => '',
			);
		}

		// 2. String input.
		if ( ! is_string( $raw_agent_id ) || '' === trim( $raw_agent_id ) ) {
			return array(
				'agent_id'    => null,
				'target_type' => 'unknown',
				'resolved_by' => 'none',
				'error'       => __( 'Agent ID is empty.', 'mcp-ai-wpoos' ),
			);
		}

		$raw_agent_id = trim( $raw_agent_id );

		// 3. Virtual agent — pass through unchanged.
		if ( 0 === strpos( $raw_agent_id, 'virtual_' ) ) {
			return array(
				'agent_id'    => $raw_agent_id,
				'target_type' => 'virtual_agent',
				'resolved_by' => 'virtual_prefix',
				'error'       => '',
			);
		}

		// 4. Try to resolve as an assistant by slug (post_name).
		$slug  = sanitize_title( $raw_agent_id );
		$posts = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'post_status'    => 'publish',
				'name'           => $slug,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( ! empty( $posts ) ) {
			return array(
				'agent_id'    => $posts[0],
				'target_type' => 'assistant',
				'resolved_by' => 'assistant_slug',
				'error'       => '',
			);
		}

		// 5. Try exact match by assistant title.
		$posts = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'post_status'    => 'publish',
				'title'          => $raw_agent_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( ! empty( $posts ) ) {
			return array(
				'agent_id'    => $posts[0],
				'target_type' => 'assistant',
				'resolved_by' => 'assistant_title',
				'error'       => '',
			);
		}

		// 6. Try partial match by assistant title.
		$posts = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'post_status'    => 'publish',
				's'              => $raw_agent_id,
				'posts_per_page' => 1,
				'fields'         => 'ids',
			)
		);

		if ( ! empty( $posts ) ) {
			return array(
				'agent_id'    => $posts[0],
				'target_type' => 'assistant',
				'resolved_by' => 'assistant_title',
				'error'       => '',
			);
		}

		// 7. Try profession name/slug → associated assistant.
		$profession_post = null;

		// Look up profession by slug.
		$prof_query = get_posts(
			array(
				'post_type'      => 'mcp_ai_profession',
				'post_status'    => 'publish',
				'name'           => $slug,
				'posts_per_page' => 1,
			)
		);

		if ( empty( $prof_query ) ) {
			// Try profession by title.
			$prof_query = get_posts(
				array(
					'post_type'      => 'mcp_ai_profession',
					'post_status'    => 'publish',
					'title'          => $raw_agent_id,
					'posts_per_page' => 1,
				)
			);
		}

		if ( ! empty( $prof_query ) ) {
			$profession_post = $prof_query[0];
		}

		if ( $profession_post ) {
			$associated_assistant = get_post_meta(
				$profession_post->ID,
				'_wp_mcp_ai_profession_associated_assistant',
				true
			);
			$associated_assistant = absint( $associated_assistant );

			if ( $associated_assistant > 0 ) {
				$assistant_post = get_post( $associated_assistant );
				if ( $assistant_post && 'mcp_ai_assistant' === $assistant_post->post_type && 'publish' === $assistant_post->post_status ) {
					return array(
						'agent_id'    => $associated_assistant,
						'target_type' => 'assistant',
						'resolved_by' => 'profession_name',
						'error'       => '',
					);
				}
			}
		}

		// 8. Could not resolve.
		return array(
			'agent_id'    => null,
			'target_type' => 'unknown',
			'resolved_by' => 'none',
			'error'       => sprintf(
				/* translators: %s: the unresolved agent identifier */
				__( 'Could not find an assistant or profession named "%s". Use an assistant post ID, assistant name/slug, profession name, or virtual agent ID from create_agent_team.', 'mcp-ai-wpoos' ),
				$raw_agent_id
			),
		);
	}


	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'workflow_automation',
			'pattern_compatibility' => array( 'hierarchical' ),
			'profession_tags'       => array( 'project_manager' ),
			'risk_level'            => 'standard',
		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'local-only',          // No external API calls.
			'write',               // Creates delegation records.
			'state-changing',      // Stores delegation in transients.
			'requires-capability', // Needs user authentication.
		);
	}
}
