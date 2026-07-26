<?php
/**
 * WordPress-specific tool for aggregating agent results.
 *
 * Lives in the WordPress adapter because it directly depends on
 * WP_MCP_AI_Agent_Communication_Service.
 *
 * @package Nvoos\WordPress
 * @since   1.0.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Tool;

use Nvoos\Core\Tool\AbstractTool;

/**
 * Aggregates results from multiple agents using various strategies.
 *
 * Combines outputs from different specialist agents using consensus,
 * weighted, hierarchical, first, or best aggregation strategies.
 */
class AggregateAgentResultsTool extends AbstractTool {

	public function getSlug(): string {
		return 'aggregate_agent_results';
	}

	public function getName(): string {
		return 'Aggregate Agent Results';
	}

	public function getDescription(): string {
		return 'Combines results from multiple agents using various aggregation strategies. Use after receiving outputs from multiple specialized agents to synthesize a unified result.';
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'agent_results'  => array(
					'type'        => 'array',
					'description' => 'Array of results from different agents.',
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'agent_id'   => array(
								'type'        => 'integer',
								'description' => 'ID of the agent that produced this result.',
							),
							'agent_role' => array(
								'type'        => 'string',
								'description' => 'Role: planner, executor, critic.',
							),
							'result'     => array(
								'description' => 'The actual result data from the agent.',
							),
							'confidence' => array(
								'type'        => 'number',
								'description' => 'Confidence score (0.0-1.0).',
								'minimum'     => 0.0,
								'maximum'     => 1.0,
							),
							'metadata'   => array(
								'type'        => 'object',
								'description' => 'Additional metadata about the result.',
							),
						),
						'required'   => array( 'agent_id', 'result' ),
					),
				),
				'strategy'       => array(
					'type'        => 'string',
					'description' => 'Aggregation strategy to use.',
					'enum'        => array( 'consensus', 'weighted', 'hierarchical', 'first', 'best' ),
					'default'     => 'consensus',
				),
				'weights'        => array(
					'type'        => 'object',
					'description' => 'Weight per agent (keys = agent_ids, values = weights).',
				),
				'priority_order' => array(
					'type'        => 'array',
					'description' => 'Agent role priority order for hierarchical strategy.',
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

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		if ( empty( $arguments['agent_results'] ) || ! \is_array( $arguments['agent_results'] ) ) {
			return $this->errors->validationFailed(
				'Agent results array is required.',
				array( 'agent_results' => array( 'This field is required.' ) ),
			);
		}

		$agent_results  = $arguments['agent_results'];
		$strategy       = isset( $arguments['strategy'] )
			? \sanitize_key( $arguments['strategy'] )
			: 'consensus';
		$weights        = $arguments['weights'] ?? array();
		$priority_order = $arguments['priority_order'] ?? array( 'critic', 'planner', 'executor' );

		if ( ! \class_exists( 'WP_MCP_AI_Agent_Communication_Service' ) ) {
			return $this->errors->create(
				'wp_mcp_ai_error',
				'Agent communication system not available.',
			);
		}

		$communication_service = new \WP_MCP_AI_Agent_Communication_Service();

		$prepared_results = array();
		foreach ( $agent_results as $result_data ) {
			if ( ! isset( $result_data['agent_id'], $result_data['result'] ) ) {
				continue;
			}

			$prepared_results[] = array(
				'agent_id'   => \absint( $result_data['agent_id'] ),
				'agent_role' => isset( $result_data['agent_role'] )
					? \sanitize_key( $result_data['agent_role'] )
					: 'executor',
				'result'     => $result_data['result'],
				'confidence' => isset( $result_data['confidence'] )
					? (float) $result_data['confidence']
					: 0.85,
				'metadata'   => $result_data['metadata'] ?? array(),
			);
		}

		if ( empty( $prepared_results ) ) {
			return $this->errors->validationFailed(
				'No valid agent results to aggregate.',
				array( 'agent_results' => array( 'No valid result entries found.' ) ),
			);
		}

		$aggregation_options = array();
		if ( 'weighted' === $strategy && ! empty( $weights ) ) {
			$aggregation_options['weights'] = $weights;
		}
		if ( 'hierarchical' === $strategy && ! empty( $priority_order ) ) {
			$aggregation_options['priority_order'] = $priority_order;
		}

		$aggregated = $communication_service->aggregate_results(
			$prepared_results,
			$strategy,
			$aggregation_options
		);

		if ( \is_wp_error( $aggregated ) ) {
			return $this->errors->create(
				$aggregated->get_error_code(),
				$aggregated->get_error_message(),
			);
		}

		return $this->success(
			'Results aggregated successfully.',
			array(
				'aggregation' => array(
					'strategy'            => $strategy,
					'agent_count'         => \count( $prepared_results ),
					'result'              => $aggregated['result'],
					'confidence'          => $aggregated['confidence'],
					'contributing_agents' => \array_map(
						function ( array $result ): array {
							return array(
								'agent_id'   => $result['agent_id'],
								'agent_role' => $result['agent_role'],
							);
						},
						$prepared_results
					),
					'metadata'            => $aggregated['metadata'] ?? array(),
				),
				'explanation' => $this->getStrategyExplanation( $strategy ),
			)
		);
	}

	/**
	 * Get a human-readable explanation of the aggregation strategy used.
	 */
	private function getStrategyExplanation( string $strategy ): string {
		$explanations = array(
			'consensus'    => 'All agent results were considered equally. Common elements were identified and conflicts resolved.',
			'weighted'     => 'Agent results were weighted based on provided weights. Higher-weighted agents had more influence.',
			'hierarchical' => 'Agent results were prioritized by role. Higher-priority roles had precedence.',
			'first'        => 'The first agent result was used as the primary output.',
			'best'         => 'The agent result with the highest confidence score was selected.',
		);

		return $explanations[ $strategy ] ?? 'Results were combined using the specified strategy.';
	}
}
