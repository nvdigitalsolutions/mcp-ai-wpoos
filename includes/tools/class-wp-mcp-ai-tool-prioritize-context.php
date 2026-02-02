<?php
/**
 * Tool for prioritizing context items within token budgets.
 *
 * Allows AI assistants to optimize context selection based on relevance,
 * importance, and token budget constraints.
 * Part of DeepSeek V4-inspired multi-agent orchestration enhancements (Phase 5).
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prioritizes context items for optimal token budget usage.
 *
 * This tool enables AI models to select the most relevant subset of context
 * items that fit within a given token budget. It considers relevance to the
 * current task, importance level, recency, and token costs.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Prioritize_Context implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'prioritize_context';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Prioritize Context', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Prioritizes and filters context items to fit within a token budget. Ranks contexts by relevance to the current task, importance level, and recency. Returns an optimized subset of contexts that maximizes value while respecting token limits.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'context_items' => array(
					'type'        => 'array',
					'description' => __( 'Array of context items to prioritize', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'context_id' => array(
								'type'        => 'string',
								'description' => __( 'Unique context identifier', 'mcp-ai-wpoos' ),
							),
							'title'      => array(
								'type'        => 'string',
								'description' => __( 'Context title or summary', 'mcp-ai-wpoos' ),
							),
							'content'    => array(
								'type'        => 'string',
								'description' => __( 'Full context content', 'mcp-ai-wpoos' ),
							),
							'importance' => array(
								'type'        => 'string',
								'description' => __( 'Importance level: low, medium, high, critical', 'mcp-ai-wpoos' ),
								'enum'        => array( 'low', 'medium', 'high', 'critical' ),
							),
							'stored_at'  => array(
								'type'        => 'string',
								'description' => __( 'When the context was stored (ISO 8601 date)', 'mcp-ai-wpoos' ),
							),
							'tags'       => array(
								'type'        => 'array',
								'description' => __( 'Context tags for relevance matching', 'mcp-ai-wpoos' ),
								'items'       => array( 'type' => 'string' ),
							),
						),
						'required'   => array( 'context_id', 'content' ),
					),
				),
				'token_budget'  => array(
					'type'        => 'integer',
					'description' => __( 'Maximum tokens to allocate for context', 'mcp-ai-wpoos' ),
					'minimum'     => 100,
					'maximum'     => 100000,
				),
				'current_task'  => array(
					'type'        => 'object',
					'description' => __( 'Current task description for relevance scoring', 'mcp-ai-wpoos' ),
					'properties'  => array(
						'query'    => array(
							'type'        => 'string',
							'description' => __( 'Task query or description', 'mcp-ai-wpoos' ),
						),
						'keywords' => array(
							'type'        => 'array',
							'description' => __( 'Important keywords for matching', 'mcp-ai-wpoos' ),
							'items'       => array( 'type' => 'string' ),
						),
						'type'     => array(
							'type'        => 'string',
							'description' => __( 'Task type (research, analysis, creation, etc.)', 'mcp-ai-wpoos' ),
						),
					),
				),
				'strategy'      => array(
					'type'        => 'string',
					'description' => __( 'Prioritization strategy', 'mcp-ai-wpoos' ),
					'enum'        => array( 'relevance', 'importance', 'recency', 'balanced' ),
					'default'     => 'balanced',
				),
				'weights'       => array(
					'type'        => 'object',
					'description' => __( 'Custom weights for scoring factors (0.0-1.0)', 'mcp-ai-wpoos' ),
					'properties'  => array(
						'relevance'  => array(
							'type'    => 'number',
							'minimum' => 0,
							'maximum' => 1,
							'default' => 0.4,
						),
						'importance' => array(
							'type'    => 'number',
							'minimum' => 0,
							'maximum' => 1,
							'default' => 0.4,
						),
						'recency'    => array(
							'type'    => 'number',
							'minimum' => 0,
							'maximum' => 1,
							'default' => 0.2,
						),
					),
				),
			),
			'required'             => array( 'context_items', 'token_budget' ),
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
		// Validate required parameters.
		if ( empty( $arguments['context_items'] ) || ! is_array( $arguments['context_items'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Context items array is required.', 'mcp-ai-wpoos' ),
			);
		}

		if ( empty( $arguments['token_budget'] ) || ! is_numeric( $arguments['token_budget'] ) ) {
			return array(
				'success' => false,
				'message' => __( 'Token budget is required and must be a number.', 'mcp-ai-wpoos' ),
			);
		}

		// Sanitize inputs.
		$context_items = $arguments['context_items'];
		$token_budget  = max( 100, min( 100000, absint( $arguments['token_budget'] ) ) );
		$current_task  = isset( $arguments['current_task'] ) && is_array( $arguments['current_task'] ) ? $arguments['current_task'] : array();
		$strategy      = isset( $arguments['strategy'] ) ? sanitize_key( $arguments['strategy'] ) : 'balanced';
		$weights       = isset( $arguments['weights'] ) && is_array( $arguments['weights'] ) ? $arguments['weights'] : array();

		// Normalize weights.
		$weights = $this->normalize_weights( $weights, $strategy );

		// Score each context item.
		$scored_items = array();
		foreach ( $context_items as $item ) {
			if ( ! isset( $item['context_id'] ) || ! isset( $item['content'] ) ) {
				continue; // Skip invalid items.
			}

			$score  = $this->calculate_context_score( $item, $current_task, $weights );
			$tokens = $this->estimate_tokens( $item['content'] );

			$scored_items[] = array(
				'item'   => $item,
				'score'  => $score,
				'tokens' => $tokens,
			);
		}

		// Sort by score (highest first).
		usort(
			$scored_items,
			function ( $a, $b ) {
				return $b['score'] <=> $a['score'];
			}
		);

		// Select items within token budget.
		$selected_items = array();
		$used_tokens    = 0;

		foreach ( $scored_items as $scored ) {
			$item_tokens = $scored['tokens'];

			// Check if adding this item would exceed budget.
			if ( $used_tokens + $item_tokens > $token_budget ) {
				// Skip if it doesn't fit.
				continue;
			}

			$selected_items[] = array(
				'context_id' => $scored['item']['context_id'],
				'title'      => isset( $scored['item']['title'] ) ? $scored['item']['title'] : '',
				'content'    => $scored['item']['content'],
				'importance' => isset( $scored['item']['importance'] ) ? $scored['item']['importance'] : 'medium',
				'tags'       => isset( $scored['item']['tags'] ) ? $scored['item']['tags'] : array(),
				'score'      => round( $scored['score'], 3 ),
				'tokens'     => $item_tokens,
			);

			$used_tokens += $item_tokens;
		}

		return array(
			'success'         => true,
			'message'         => sprintf(
				/* translators: 1: number of selected items, 2: total items */
				__( 'Prioritized %1$d of %2$d context items within token budget.', 'mcp-ai-wpoos' ),
				count( $selected_items ),
				count( $context_items )
			),
			'prioritized'     => $selected_items,
			'count'           => count( $selected_items ),
			'total_tokens'    => $used_tokens,
			'budget'          => $token_budget,
			'budget_used_pct' => round( ( $used_tokens / $token_budget ) * 100, 1 ),
			'strategy'        => $strategy,
			'weights'         => $weights,
			'excluded_count'  => count( $context_items ) - count( $selected_items ),
		);
	}

	/**
	 * Normalize scoring weights based on strategy.
	 *
	 * @param array  $weights User-provided weights.
	 * @param string $strategy Prioritization strategy.
	 * @return array Normalized weights.
	 */
	private function normalize_weights( $weights, $strategy ) {
		// Default weights for balanced strategy.
		$defaults = array(
			'relevance'  => 0.4,
			'importance' => 0.4,
			'recency'    => 0.2,
		);

		// Adjust defaults based on strategy.
		switch ( $strategy ) {
			case 'relevance':
				$defaults = array(
					'relevance'  => 0.7,
					'importance' => 0.2,
					'recency'    => 0.1,
				);
				break;
			case 'importance':
				$defaults = array(
					'relevance'  => 0.2,
					'importance' => 0.7,
					'recency'    => 0.1,
				);
				break;
			case 'recency':
				$defaults = array(
					'relevance'  => 0.2,
					'importance' => 0.2,
					'recency'    => 0.6,
				);
				break;
		}

		// Merge with user-provided weights.
		$merged = array_merge( $defaults, $weights );

		// Ensure weights sum to 1.0.
		$total = $merged['relevance'] + $merged['importance'] + $merged['recency'];
		if ( $total > 0 ) {
			$merged['relevance']  = $merged['relevance'] / $total;
			$merged['importance'] = $merged['importance'] / $total;
			$merged['recency']    = $merged['recency'] / $total;
		}

		return $merged;
	}

	/**
	 * Calculate overall score for a context item.
	 *
	 * @param array $item Context item.
	 * @param array $current_task Current task description.
	 * @param array $weights Scoring weights.
	 * @return float Score (0.0-1.0).
	 */
	private function calculate_context_score( $item, $current_task, $weights ) {
		$relevance_score  = $this->calculate_relevance_score( $item, $current_task );
		$importance_score = $this->calculate_importance_score( $item );
		$recency_score    = $this->calculate_recency_score( $item );

		$total_score = (
			( $relevance_score * $weights['relevance'] ) +
			( $importance_score * $weights['importance'] ) +
			( $recency_score * $weights['recency'] )
		);

		return min( 1.0, max( 0.0, $total_score ) );
	}

	/**
	 * Calculate relevance score against current task.
	 *
	 * @param array $item Context item.
	 * @param array $current_task Current task description.
	 * @return float Score (0.0-1.0).
	 */
	private function calculate_relevance_score( $item, $current_task ) {
		if ( empty( $current_task ) ) {
			return 0.5; // Neutral if no task provided.
		}

		$score   = 0.0;
		$title   = isset( $item['title'] ) ? strtolower( $item['title'] ) : '';
		$content = isset( $item['content'] ) ? strtolower( $item['content'] ) : '';
		$tags    = isset( $item['tags'] ) ? array_map( 'strtolower', $item['tags'] ) : array();

		// Match against query.
		if ( isset( $current_task['query'] ) ) {
			$query       = strtolower( $current_task['query'] );
			$query_words = explode( ' ', $query );

			// Title matches (high weight).
			if ( strpos( $title, $query ) !== false ) {
				$score += 0.5;
			}
			foreach ( $query_words as $word ) {
				if ( strlen( $word ) > 3 && strpos( $title, $word ) !== false ) {
					$score += 0.1;
				}
			}

			// Content matches (medium weight).
			foreach ( $query_words as $word ) {
				if ( strlen( $word ) > 3 && strpos( $content, $word ) !== false ) {
					$score += 0.05;
				}
			}
		}

		// Match against keywords.
		if ( isset( $current_task['keywords'] ) && is_array( $current_task['keywords'] ) ) {
			foreach ( $current_task['keywords'] as $keyword ) {
				$keyword = strtolower( $keyword );
				if ( strpos( $title, $keyword ) !== false || strpos( $content, $keyword ) !== false ) {
					$score += 0.15;
				}
				// Check tags.
				foreach ( $tags as $tag ) {
					if ( strpos( $tag, $keyword ) !== false || strpos( $keyword, $tag ) !== false ) {
						$score += 0.1;
					}
				}
			}
		}

		return min( 1.0, $score );
	}

	/**
	 * Calculate importance score.
	 *
	 * @param array $item Context item.
	 * @return float Score (0.0-1.0).
	 */
	private function calculate_importance_score( $item ) {
		$importance = isset( $item['importance'] ) ? $item['importance'] : 'medium';

		$scores = array(
			'critical' => 1.0,
			'high'     => 0.75,
			'medium'   => 0.5,
			'low'      => 0.25,
		);

		return isset( $scores[ $importance ] ) ? $scores[ $importance ] : 0.5;
	}

	/**
	 * Calculate recency score.
	 *
	 * @param array $item Context item.
	 * @return float Score (0.0-1.0).
	 */
	private function calculate_recency_score( $item ) {
		if ( ! isset( $item['stored_at'] ) ) {
			return 0.5; // Neutral if no date.
		}

		$stored_timestamp = strtotime( $item['stored_at'] );
		if ( ! $stored_timestamp ) {
			return 0.5;
		}

		$age_seconds = time() - $stored_timestamp;
		$age_days    = $age_seconds / 86400;

		// Exponential decay: newer = higher score.
		// Perfect score for items < 1 day old.
		// 0.5 score at ~7 days old.
		// 0.25 score at ~14 days old.
		if ( $age_days < 1 ) {
			return 1.0;
		}

		// Decay function: score = e^(-age_days / 10).
		$score = exp( -$age_days / 10 );
		return max( 0.0, min( 1.0, $score ) );
	}

	/**
	 * Estimate token count for text.
	 *
	 * Uses rough approximation: ~4 characters per token.
	 *
	 * @param string $text Text to estimate.
	 * @return int Estimated token count.
	 */
	private function estimate_tokens( $text ) {
		if ( empty( $text ) ) {
			return 0;
		}

		// Rough estimate: 4 characters per token (conservative).
		$char_count = strlen( $text );
		return (int) ceil( $char_count / 4 );
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

			'toolkit'               => 'ai_model_management',

			'pattern_compatibility' => array( 'orchestrator' ),

			'profession_tags'       => array( 'ai_researcher', 'machine_learning_engineer' ),

			'risk_level'            => 'info',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'safe'              => true,  // Computation only, no side effects.
			'local-only'        => true,  // No external API calls.
			'read-only'         => true,  // Does not modify data.
			'idempotent'        => true,  // Same input = same output.
			'cacheable'         => true,  // Results can be cached.
			'requires-auth'     => true,  // Needs user authentication.
			'blocking'          => false, // Fast operation.
			'uses-network'      => false, // No network calls.
			'modifies-wp'       => false, // Does not modify data.
			'expensive'         => false, // Low cost operation.
			'requires-approval' => false, // Auto-approved.
		);
	}
}
