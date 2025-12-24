<?php
/**
 * Tool that recommends the best OpenAI model for a given task.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recommends the best OpenAI model based on task requirements.
 */
class WP_MCP_AI_Tool_Suggest_Best_Model implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * Cost calculation factor for low cost requirement.
	 *
	 * @var float
	 */
	const COST_FACTOR_BASE = 0.001;

	/**
	 * Cost calculation multiplier for low cost requirement.
	 *
	 * @var float
	 */
	const COST_FACTOR_MULTIPLIER = 0.1;

	/**
	 * Cost calculation multiplier for budget adjustment.
	 *
	 * @var float
	 */
	const COST_FACTOR_BUDGET = 0.5;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'suggest_best_model';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Suggest Best Model', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Recommends the best OpenAI model for a given task based on requirements. Use this for dynamic model selection, cost optimization, performance optimization, or task-appropriate model matching.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'task_type'          => array(
					'type'        => 'string',
					'enum'        => array( 'chat', 'embeddings', 'images', 'audio-transcription', 'audio-tts', 'moderation' ),
					'description' => __( 'Type of task to perform.', 'wp-mcp-ai' ),
				),
				'requirements'       => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'speed', 'quality', 'cost', 'vision', 'function_calling' ),
					),
					'description' => __( 'Array of requirements (speed, quality, cost, vision, function_calling).', 'wp-mcp-ai' ),
					'default'     => array( 'quality' ),
				),
				'context_length'     => array(
					'type'        => 'integer',
					'description' => __( 'Required context length in tokens.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'multimodal'         => array(
					'type'        => 'boolean',
					'description' => __( 'Requires vision or audio capabilities.', 'wp-mcp-ai' ),
					'default'     => false,
				),
				'budget_preference'  => array(
					'type'        => 'string',
					'enum'        => array( 'low', 'medium', 'high' ),
					'description' => __( 'Budget preference level.', 'wp-mcp-ai' ),
					'default'     => 'medium',
				),
			),
			'required'             => array( 'task_type' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Check permissions.
		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to get model suggestions.', 'wp-mcp-ai' )
			);
		}

		
		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}
// Validate task_type.
		if ( ! isset( $arguments['task_type'] ) || empty( $arguments['task_type'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_task_type',
				__( 'Task type is required.', 'wp-mcp-ai' )
			);
		}

		$task_type         = sanitize_text_field( $arguments['task_type'] );
		$requirements      = isset( $arguments['requirements'] ) && is_array( $arguments['requirements'] ) ? array_map( 'sanitize_text_field', $arguments['requirements'] ) : array( 'quality' );
		$context_length    = isset( $arguments['context_length'] ) ? absint( $arguments['context_length'] ) : 0;
		$multimodal        = isset( $arguments['multimodal'] ) ? (bool) $arguments['multimodal'] : false;
		$budget_preference = isset( $arguments['budget_preference'] ) ? sanitize_text_field( $arguments['budget_preference'] ) : 'medium';

		// Get model recommendations based on task type.
		$recommendation = $this->get_model_recommendation(
			$task_type,
			$requirements,
			$context_length,
			$multimodal,
			$budget_preference
		);

		if ( is_wp_error( $recommendation ) ) {
			return $recommendation;
		}

		return array(
			'success'           => true,
			'recommended_model' => $recommendation['model'],
			'reasoning'         => $recommendation['reasoning'],
			'alternatives'      => $recommendation['alternatives'],
			'estimated_cost'    => $recommendation['estimated_cost'],
			'context_window'    => $recommendation['context_window'],
			'capabilities'      => $recommendation['capabilities'],
			'summary'           => sprintf(
				/* translators: %s: model name */
				__( 'Recommended model: %s', 'wp-mcp-ai' ),
				$recommendation['model']
			),
		);
	}

	/**
	 * Get model recommendation based on requirements.
	 *
	 * @param string $task_type Task type.
	 * @param array  $requirements Requirements array.
	 * @param int    $context_length Required context length.
	 * @param bool   $multimodal Requires multimodal capabilities.
	 * @param string $budget_preference Budget preference.
	 * @return array|WP_Error Recommendation or error.
	 */
	private function get_model_recommendation( $task_type, $requirements, $context_length, $multimodal, $budget_preference ) {
		$models = $this->get_model_database();

		// Filter models by task type.
		$candidates = array();
		foreach ( $models as $model_id => $model_info ) {
			if ( ! in_array( $task_type, $model_info['task_types'], true ) ) {
				continue;
			}

			// Check context length requirement.
			if ( $context_length > 0 && $model_info['context_window'] < $context_length ) {
				continue;
			}

			// Check multimodal requirement.
			if ( $multimodal && ! in_array( 'vision', $model_info['capabilities'], true ) && ! in_array( 'audio', $model_info['capabilities'], true ) ) {
				continue;
			}

			$candidates[ $model_id ] = $model_info;
		}

		if ( empty( $candidates ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_suitable_model',
				__( 'No suitable model found for the given requirements.', 'wp-mcp-ai' )
			);
		}

		// Score candidates based on requirements and budget.
		$scored_candidates = array();
		foreach ( $candidates as $model_id => $model_info ) {
			$score = $this->calculate_model_score( $model_info, $requirements, $budget_preference );
			$scored_candidates[ $model_id ] = array(
				'info'  => $model_info,
				'score' => $score,
			);
		}

		// Sort by score (descending).
		uasort(
			$scored_candidates,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		// Get top recommendation and alternatives.
		$top_candidates = array_slice( $scored_candidates, 0, 4, true );
		$top_model_id   = array_key_first( $top_candidates );
		$top_model      = $top_candidates[ $top_model_id ]['info'];

		$alternatives = array();
		$i            = 0;
		foreach ( $top_candidates as $model_id => $candidate ) {
			if ( $i > 0 ) { // Skip the first one (it's the recommended model).
				$alternatives[] = $model_id;
			}
			$i++;
		}

		// Generate reasoning.
		$reasoning = $this->generate_reasoning( $top_model, $task_type, $requirements, $budget_preference );

		return array(
			'model'           => $top_model_id,
			'reasoning'       => $reasoning,
			'alternatives'    => array_slice( $alternatives, 0, 3 ),
			'estimated_cost'  => $top_model['cost_per_1k_tokens'],
			'context_window'  => $top_model['context_window'],
			'capabilities'    => $top_model['capabilities'],
		);
	}

	/**
	 * Calculate model score based on requirements.
	 *
	 * @param array  $model_info Model information.
	 * @param array  $requirements Requirements array.
	 * @param string $budget_preference Budget preference.
	 * @return float Score.
	 */
	private function calculate_model_score( $model_info, $requirements, $budget_preference ) {
		$score = 0.0;

		// Base score from model tier.
		$score += $model_info['tier'] * 10;

		// Adjust for requirements.
		foreach ( $requirements as $requirement ) {
			if ( 'speed' === $requirement && $model_info['speed'] >= 8 ) {
				$score += 15;
			}
			if ( 'quality' === $requirement && $model_info['quality'] >= 9 ) {
				$score += 15;
			}
			if ( 'cost' === $requirement ) {
				$score += ( 1.0 / max( self::COST_FACTOR_BASE, $model_info['cost_per_1k_tokens'] ) ) * self::COST_FACTOR_MULTIPLIER;
			}
			if ( 'vision' === $requirement && in_array( 'vision', $model_info['capabilities'], true ) ) {
				$score += 20;
			}
			if ( 'function_calling' === $requirement && in_array( 'function_calling', $model_info['capabilities'], true ) ) {
				$score += 15;
			}
		}

		// Adjust for budget preference.
		if ( 'low' === $budget_preference ) {
			$score += ( 1.0 / max( self::COST_FACTOR_BASE, $model_info['cost_per_1k_tokens'] ) ) * self::COST_FACTOR_BUDGET;
		} elseif ( 'high' === $budget_preference ) {
			$score += $model_info['quality'] * 2;
		}

		return $score;
	}

	/**
	 * Generate reasoning for model recommendation.
	 *
	 * @param array  $model Model information.
	 * @param string $task_type Task type.
	 * @param array  $requirements Requirements.
	 * @param string $budget_preference Budget preference.
	 * @return string Reasoning text.
	 */
	private function generate_reasoning( $model, $task_type, $requirements, $budget_preference ) {
		$reasons = array();

		if ( in_array( 'speed', $requirements, true ) ) {
			$reasons[] = __( 'optimized for fast response times', 'wp-mcp-ai' );
		}

		if ( in_array( 'quality', $requirements, true ) ) {
			$reasons[] = __( 'provides highest quality outputs', 'wp-mcp-ai' );
		}

		if ( in_array( 'cost', $requirements, true ) || 'low' === $budget_preference ) {
			$reasons[] = __( 'cost-effective choice', 'wp-mcp-ai' );
		}

		if ( in_array( 'vision', $requirements, true ) && in_array( 'vision', $model['capabilities'], true ) ) {
			$reasons[] = __( 'supports vision capabilities', 'wp-mcp-ai' );
		}

		if ( in_array( 'function_calling', $requirements, true ) && in_array( 'function_calling', $model['capabilities'], true ) ) {
			$reasons[] = __( 'supports function calling', 'wp-mcp-ai' );
		}

		if ( empty( $reasons ) ) {
			$reasons[] = sprintf(
				/* translators: %s: task type */
				__( 'well-suited for %s tasks', 'wp-mcp-ai' ),
				$task_type
			);
		}

		return ucfirst( implode( ', ', $reasons ) ) . '.';
	}

	/**
	 * Get model database with capabilities and pricing.
	 *
	 * @return array Model database.
	 */
	private function get_model_database() {
		return array(
			'gpt-4o'                    => array(
				'task_types'        => array( 'chat' ),
				'context_window'    => 128000,
				'capabilities'      => array( 'chat', 'function_calling', 'vision' ),
				'speed'             => 9,
				'quality'           => 10,
				'tier'              => 3,
				'cost_per_1k_tokens' => 0.0025,
			),
			'gpt-4o-mini'               => array(
				'task_types'        => array( 'chat' ),
				'context_window'    => 128000,
				'capabilities'      => array( 'chat', 'function_calling', 'vision' ),
				'speed'             => 10,
				'quality'           => 8,
				'tier'              => 2,
				'cost_per_1k_tokens' => 0.00015,
			),
			'gpt-4-turbo'               => array(
				'task_types'        => array( 'chat' ),
				'context_window'    => 128000,
				'capabilities'      => array( 'chat', 'function_calling', 'vision' ),
				'speed'             => 8,
				'quality'           => 10,
				'tier'              => 3,
				'cost_per_1k_tokens' => 0.01,
			),
			'gpt-3.5-turbo'             => array(
				'task_types'        => array( 'chat' ),
				'context_window'    => 16385,
				'capabilities'      => array( 'chat', 'function_calling' ),
				'speed'             => 10,
				'quality'           => 7,
				'tier'              => 1,
				'cost_per_1k_tokens' => 0.0005,
			),
			'text-embedding-3-large'    => array(
				'task_types'        => array( 'embeddings' ),
				'context_window'    => 8191,
				'capabilities'      => array( 'embeddings' ),
				'speed'             => 9,
				'quality'           => 10,
				'tier'              => 2,
				'cost_per_1k_tokens' => 0.00013,
			),
			'text-embedding-3-small'    => array(
				'task_types'        => array( 'embeddings' ),
				'context_window'    => 8191,
				'capabilities'      => array( 'embeddings' ),
				'speed'             => 10,
				'quality'           => 9,
				'tier'              => 1,
				'cost_per_1k_tokens' => 0.00002,
			),
			'text-embedding-ada-002'    => array(
				'task_types'        => array( 'embeddings' ),
				'context_window'    => 8191,
				'capabilities'      => array( 'embeddings' ),
				'speed'             => 9,
				'quality'           => 8,
				'tier'              => 1,
				'cost_per_1k_tokens' => 0.0001,
			),
			'dall-e-3'                  => array(
				'task_types'        => array( 'images' ),
				'context_window'    => 4000,
				'capabilities'      => array( 'images' ),
				'speed'             => 6,
				'quality'           => 10,
				'tier'              => 2,
				'cost_per_1k_tokens' => 0.04,
			),
			'dall-e-2'                  => array(
				'task_types'        => array( 'images' ),
				'context_window'    => 1000,
				'capabilities'      => array( 'images' ),
				'speed'             => 7,
				'quality'           => 7,
				'tier'              => 1,
				'cost_per_1k_tokens' => 0.02,
			),
			'whisper-1'                 => array(
				'task_types'        => array( 'audio-transcription' ),
				'context_window'    => 0,
				'capabilities'      => array( 'audio' ),
				'speed'             => 8,
				'quality'           => 9,
				'tier'              => 1,
				'cost_per_1k_tokens' => 0.006,
			),
			'tts-1'                     => array(
				'task_types'        => array( 'audio-tts' ),
				'context_window'    => 4096,
				'capabilities'      => array( 'audio' ),
				'speed'             => 9,
				'quality'           => 8,
				'tier'              => 1,
				'cost_per_1k_tokens' => 0.015,
			),
			'tts-1-hd'                  => array(
				'task_types'        => array( 'audio-tts' ),
				'context_window'    => 4096,
				'capabilities'      => array( 'audio' ),
				'speed'             => 7,
				'quality'           => 10,
				'tier'              => 2,
				'cost_per_1k_tokens' => 0.03,
			),
			'text-moderation-latest'    => array(
				'task_types'        => array( 'moderation' ),
				'context_window'    => 32768,
				'capabilities'      => array( 'moderation' ),
				'speed'             => 10,
				'quality'           => 9,
				'tier'              => 1,
				'cost_per_1k_tokens' => 0.0,
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'requires-capability',  // Requires 'read' capability.
			'cacheable',            // Results can be cached.
		);
	}
}
