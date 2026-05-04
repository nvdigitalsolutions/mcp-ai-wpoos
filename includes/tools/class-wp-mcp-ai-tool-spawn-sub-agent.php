<?php
/**
 * Tool: Spawn Sub-Agent.
 *
 * @package WP_MCP_AI
 * @since   2.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Spawns a subordinate AI assistant to handle a delegated task.
 *
 * @since 2.2.0
 */
class WP_MCP_AI_Tool_Spawn_Sub_Agent implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'spawn_sub_agent';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Spawn Sub-Agent', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Spawns a subordinate AI assistant (sub-agent) to handle a specific task. Enforces depth and fanout limits to prevent runaway recursion.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'agent_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Post ID of the target assistant to spawn.', 'mcp-ai-wpoos' ),
				),
				'task'       => array(
					'type'        => 'string',
					'description' => __( 'Task description for the sub-agent.', 'mcp-ai-wpoos' ),
				),
				'input'      => array(
					'type'        => 'object',
					'description' => __( 'Optional structured input data for the sub-agent.', 'mcp-ai-wpoos' ),
				),
				'max_depth'  => array(
					'type'        => 'integer',
					'description' => __( 'Maximum recursive spawn depth. Defaults to 3.', 'mcp-ai-wpoos' ),
					'default'     => 3,
					'minimum'     => 1,
					'maximum'     => 10,
				),
				'max_fanout' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum parallel sub-agents at same depth. Defaults to 5.', 'mcp-ai-wpoos' ),
					'default'     => 5,
					'minimum'     => 1,
					'maximum'     => 20,
				),
				'budget_usd' => array(
					'type'        => 'number',
					'description' => __( 'Max cost budget for this sub-agent run in USD. Defaults to 1.0.', 'mcp-ai-wpoos' ),
					'default'     => 1.0,
					'minimum'     => 0.01,
				),
			),
			'required'             => array( 'agent_id', 'task' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', __( 'You do not have permission to spawn sub-agents.', 'mcp-ai-wpoos' ) );
		}

		$agent_id   = absint( isset( $arguments['agent_id'] ) ? $arguments['agent_id'] : 0 );
		$task       = isset( $arguments['task'] ) ? sanitize_textarea_field( $arguments['task'] ) : '';
		$input      = isset( $arguments['input'] ) && is_array( $arguments['input'] ) ? $arguments['input'] : array();
		$max_depth  = isset( $arguments['max_depth'] ) ? absint( $arguments['max_depth'] ) : 3;
		$max_fanout = isset( $arguments['max_fanout'] ) ? absint( $arguments['max_fanout'] ) : 5;
		$budget_usd = isset( $arguments['budget_usd'] ) ? (float) $arguments['budget_usd'] : 1.0;

		if ( empty( $agent_id ) || empty( $task ) ) {
			return new WP_Error( 'invalid_args', __( 'agent_id and task are required.', 'mcp-ai-wpoos' ) );
		}

		$current_depth = isset( $context['spawn_depth'] ) ? absint( $context['spawn_depth'] ) : 0;
		if ( $current_depth >= $max_depth ) {
			return new WP_Error(
				'max_depth_exceeded',
				sprintf(
					/* translators: 1: current depth 2: max depth */
					__( 'Maximum spawn depth of %2$d exceeded (current depth: %1$d).', 'mcp-ai-wpoos' ),
					$current_depth,
					$max_depth
				)
			);
		}

		$current_fanout = isset( $context['spawn_fanout_count'] ) ? absint( $context['spawn_fanout_count'] ) : 0;
		if ( $current_fanout >= $max_fanout ) {
			return new WP_Error(
				'max_fanout_exceeded',
				sprintf(
					/* translators: 1: current fanout 2: max fanout */
					__( 'Maximum spawn fanout of %2$d exceeded (current fanout count: %1$d).', 'mcp-ai-wpoos' ),
					$current_fanout,
					$max_fanout
				)
			);
		}

		$agent_post = get_post( $agent_id );
		if ( ! $agent_post || 'mcp_ai_assistant' !== $agent_post->post_type ) {
			return new WP_Error( 'invalid_agent', __( 'Target assistant post not found.', 'mcp-ai-wpoos' ) );
		}

		$sub_agent_run_id = 'run_' . wp_generate_password( 12, false );
		$child_depth      = $current_depth + 1;

		/**
		 * Fires before a sub-agent is spawned.
		 *
		 * @since 2.2.0
		 * @param int    $agent_id         Target assistant post ID.
		 * @param string $task             Task description.
		 * @param array  $input            Structured input.
		 * @param string $sub_agent_run_id Unique run ID.
		 * @param array  $context          Parent execution context.
		 */
		do_action( 'wp_mcp_ai_before_spawn_sub_agent', $agent_id, $task, $input, $sub_agent_run_id, $context );

		$sub_context = array_merge(
			$context,
			array(
				'spawn_depth'        => $child_depth,
				'spawn_fanout_count' => 0,
				'parent_run_id'      => isset( $context['run_id'] ) ? $context['run_id'] : null,
				'sub_agent_run_id'   => $sub_agent_run_id,
				'assistant_id'       => $agent_id,
				'budget_usd'         => $budget_usd,
			)
		);

		$result   = null;
		$cost_usd = 0.0;

		if ( class_exists( 'WP_MCP_AI_Agent_Communication_Service' ) ) {
			$comm    = new WP_MCP_AI_Agent_Communication_Service();
			$outcome = $comm->delegate_task(
				isset( $context['assistant_id'] ) ? (int) $context['assistant_id'] : 0,
				$agent_id,
				array(
					'description'  => $task,
					'context'      => $sub_context,
					'input'        => $input,
					'budget_usd'   => $budget_usd,
					'delegated_at' => current_time( 'mysql' ),
				),
				$sub_context
			);
			if ( is_wp_error( $outcome ) ) {
				return $outcome;
			}
			$result = $outcome;
		} else {
			$result = array(
				'agent_id'   => $agent_id,
				'agent_name' => esc_html( $agent_post->post_title ),
				'status'     => 'deferred',
				'task'       => $task,
			);
		}

		$return = array(
			'success'          => true,
			'sub_agent_run_id' => $sub_agent_run_id,
			'result'           => $result,
			'cost_usd'         => $cost_usd,
			'depth'            => $child_depth,
			'fanout_count'     => $current_fanout + 1,
			'message'          => sprintf(
				/* translators: %s: agent name */
				__( 'Sub-agent "%s" spawned successfully.', 'mcp-ai-wpoos' ),
				esc_html( $agent_post->post_title )
			),
		);

		/**
		 * Fires after a sub-agent has been spawned.
		 *
		 * @since 2.2.0
		 * @param array  $return           Return data.
		 * @param int    $agent_id         Target assistant post ID.
		 * @param string $sub_agent_run_id Unique run ID.
		 * @param array  $context          Parent execution context.
		 */
		do_action( 'wp_mcp_ai_after_spawn_sub_agent', $return, $agent_id, $sub_agent_run_id, $context );

		return $return;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'safe'              => false,
			'local-only'        => true,
			'read-only'         => false,
			'idempotent'        => false,
			'cacheable'         => false,
			'requires-auth'     => true,
			'blocking'          => false,
			'uses-network'      => false,
			'modifies-wp'       => true,
			'expensive'         => true,
			'requires-approval' => false,
		);
	}
}
