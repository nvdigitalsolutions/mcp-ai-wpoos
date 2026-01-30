<?php
/**
 * Tool for aggregating agent results.
 *
 * Allows AI assistants to combine outputs from multiple agents using various strategies.
 * Part of DeepSeek V4-inspired multi-agent orchestration enhancements.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Aggregates results from multiple agents.
 *
 * This tool enables AI models to combine outputs from different specialist agents
 * using various aggregation strategies (consensus, weighted, hierarchical, etc.).
 * Useful for synthesizing multi-agent collaboration results.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Aggregate_Agent_Results implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'aggregate_agent_results';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Aggregate Agent Results', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Combines results from multiple agents using various aggregation strategies. Use this after receiving outputs from multiple specialized agents to synthesize a unified result.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'agent_results'  => array(
					'type'        => 'array',
					'description' => __( 'Array of results from different agents', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'agent_id'   => array(
								'type'        => 'integer',
								'description' => __( 'ID of the agent that produced this result', 'mcp-ai-wpoos' ),
							),
							'agent_role' => array(
								'type'        => 'string',
								'description' => __( 'Role of the agent: planner, executor, critic', 'mcp-ai-wpoos' ),
							),
							'result'     => array(
								'description' => __( 'The actual result data from the agent', 'mcp-ai-wpoos' ),
							),
							'confidence' => array(
								'type'        => 'number',
								'description' => __( 'Confidence score (0.0-1.0)', 'mcp-ai-wpoos' ),
								'minimum'     => 0.0,
								'maximum'     => 1.0,
							),
							'metadata'   => array(
								'type'        => 'object',
								'description' => __( 'Additional metadata about the result', 'mcp-ai-wpoos' ),
							),
						),
						'required'   => array( 'agent_id', 'result' ),
					),
				),
				'strategy'       => array(
					'type'        => 'string',
					'description' => __( 'Aggregation strategy to use', 'mcp-ai-wpoos' ),
					'enum'        => array( 'consensus', 'weighted', 'hierarchical', 'first', 'best' ),
					'default'     => 'consensus',
				),
				'weights'        => array(
					'type'        => 'object',
					'description' => __( 'Weight for each agent (for weighted strategy). Keys are agent_ids, values are weights.', 'mcp-ai-wpoos' ),
				),
				'priority_order' => array(
					'type'        => 'array',
					'description' => __( 'Agent role priority order (for hierarchical strategy)', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'planner', 'executor', 'critic', 'specialist' ),
					),
				),
			),
			'required'             => array( 'agent_results' ),
			'additionalProperties' => false,
		);
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
		if ( empty( $arguments['agent_results'] ) || ! is_array( $arguments['agent_results'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Agent results array is required.', 'mcp-ai-wpoos' ),
			);
		}

		$agent_results  = $arguments['agent_results'];
		$strategy       = isset( $arguments['strategy'] ) ? sanitize_key( $arguments['strategy'] ) : 'consensus';
		$weights        = isset( $arguments['weights'] ) ? $arguments['weights'] : array();
		$priority_order = isset( $arguments['priority_order'] ) ? $arguments['priority_order'] : array( 'critic', 'planner', 'executor' );

		// Get communication service.
		if ( ! class_exists( 'WP_MCP_AI_Agent_Communication_Service' ) ) {
			return array(
				'success' => false,
				'message' => __( 'Agent communication system not available.', 'mcp-ai-wpoos' ),
			);
		}

		$communication_service = new WP_MCP_AI_Agent_Communication_Service();

		// Prepare results for aggregation.
		$prepared_results = array();
		foreach ( $agent_results as $result_data ) {
			if ( ! isset( $result_data['agent_id'] ) || ! isset( $result_data['result'] ) ) {
				continue; // Skip invalid results.
			}

			$prepared_results[] = array(
				'agent_id'   => absint( $result_data['agent_id'] ),
				'agent_role' => isset( $result_data['agent_role'] ) ? sanitize_key( $result_data['agent_role'] ) : 'executor',
				'result'     => $result_data['result'],
				'confidence' => isset( $result_data['confidence'] ) ? floatval( $result_data['confidence'] ) : 0.85,
				'metadata'   => isset( $result_data['metadata'] ) ? $result_data['metadata'] : array(),
			);
		}

		if ( empty( $prepared_results ) ) {
			return array(
				'success' => false,
				'message' => __( 'No valid agent results to aggregate.', 'mcp-ai-wpoos' ),
			);
		}

		// Set aggregation options.
		$aggregation_options = array();
		if ( 'weighted' === $strategy && ! empty( $weights ) ) {
			$aggregation_options['weights'] = $weights;
		}
		if ( 'hierarchical' === $strategy && ! empty( $priority_order ) ) {
			$aggregation_options['priority_order'] = $priority_order;
		}

		// Aggregate results.
		$aggregated = $communication_service->aggregate_results( $prepared_results, $strategy, $aggregation_options );

		if ( is_wp_error( $aggregated ) ) {
			return array(
				'success' => false,
				'message' => $aggregated->get_error_message(),
				'code'    => $aggregated->get_error_code(),
			);
		}

		// Format aggregated result.
		return array(
			'success'     => true,
			'message'     => __( 'Results aggregated successfully.', 'mcp-ai-wpoos' ),
			'aggregation' => array(
				'strategy'            => $strategy,
				'agent_count'         => count( $prepared_results ),
				'result'              => $aggregated['result'],
				'confidence'          => $aggregated['confidence'],
				'contributing_agents' => array_map(
					function ( $result ) {
						return array(
							'agent_id'   => $result['agent_id'],
							'agent_role' => $result['agent_role'],
						);
					},
					$prepared_results
				),
				'metadata'            => isset( $aggregated['metadata'] ) ? $aggregated['metadata'] : array(),
			),
			'explanation' => $this->get_strategy_explanation( $strategy ),
		);
	}

	/**
	 * Get explanation of aggregation strategy.
	 *
	 * @param string $strategy Strategy name.
	 * @return string Explanation.
	 */
	private function get_strategy_explanation( $strategy ) {
		$explanations = array(
			'consensus'    => __( 'All agent results were considered equally. Common elements were identified and conflicts resolved.', 'mcp-ai-wpoos' ),
			'weighted'     => __( 'Agent results were weighted based on provided weights. Higher-weighted agents had more influence.', 'mcp-ai-wpoos' ),
			'hierarchical' => __( 'Agent results were prioritized by role. Higher-priority roles (e.g., critic) had precedence.', 'mcp-ai-wpoos' ),
			'first'        => __( 'The first agent result was used as the primary output.', 'mcp-ai-wpoos' ),
			'best'         => __( 'The agent result with the highest confidence score was selected.', 'mcp-ai-wpoos' ),
		);

		return isset( $explanations[ $strategy ] ) ? $explanations[ $strategy ] : __( 'Results were combined using the specified strategy.', 'mcp-ai-wpoos' );
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

			'pattern_compatibility' => array( 'hierarchical', 'orchestrator' ),

			'profession_tags'       => array( 'project_manager', 'systems_administrator' ),

			'risk_level'            => 'info',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'safe'              => true,  // Read-only aggregation.
			'local-only'        => true,  // No external API calls.
			'read-only'         => true,  // Does not write data.
			'idempotent'        => true,  // Same inputs = same output.
			'cacheable'         => true,  // Results can be cached.
			'requires-auth'     => false, // Can be used by any authenticated user.
			'blocking'          => false, // Fast operation.
			'uses-network'      => false, // No network calls.
			'modifies-wp'       => false, // No database writes.
			'expensive'         => false, // Low cost operation.
			'requires-approval' => false, // Auto-approved.
		);
	}
}
