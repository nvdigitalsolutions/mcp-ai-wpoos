<?php
/**
 * Tool Chain Predictor Service
 *
 * Predicts and optimizes tool execution sequences using historical patterns
 * and task analysis. Inspired by Multi-Token Prediction for speculative execution.
 * Part of Phase 2: Load Balancing & Efficiency enhancements.
 *
 * Features:
 * - Historical pattern matching
 * - Tool chain prediction with confidence scores
 * - Chain optimization (parallelization, redundancy elimination)
 * - Speculative pre-warming
 *
 * @package WP_MCP_AI
 * @since 1.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool Chain Predictor class
 *
 * Predicts and optimizes tool execution sequences.
 *
 * @since 1.1.1
 */
class WP_MCP_AI_Tool_Chain_Predictor {

	/**
	 * Storage keys
	 */
	const CHAIN_HISTORY_KEY = 'wp_mcp_ai_tool_chain_history';
	const PATTERN_CACHE_KEY = 'wp_mcp_ai_tool_chain_patterns';

	/**
	 * Configuration
	 */
	const MAX_CHAIN_LENGTH  = 10;
	const MIN_CONFIDENCE    = 0.5;
	const HISTORY_LIMIT     = 1000;
	const PATTERN_CACHE_TTL = 3600; // 1 hour.

	/**
	 * Tool registry instance
	 *
	 * @var WP_MCP_AI_Tool_Registry|null
	 */
	protected $registry;

	/**
	 * Constructor
	 *
	 * @param WP_MCP_AI_Tool_Registry|null $registry Tool registry.
	 */
	public function __construct( $registry = null ) {
		$this->registry = $registry;
	}

	/**
	 * Predict likely tool sequence for a task
	 *
	 * Analyzes task requirements and historical patterns to predict
	 * the most likely sequence of tools.
	 *
	 * @param string $task Task description.
	 * @param array  $context Task context.
	 * @param array  $available_tools List of available tool slugs.
	 * @return array Predicted tool chain with confidence scores.
	 */
	public function predict_tool_chain( $task, $context, $available_tools = array() ) {
		// Get available tools from registry if not provided.
		if ( empty( $available_tools ) ) {
			$registry = $this->get_registry();
			if ( $registry ) {
				$tools            = $registry->get_all_tools();
				$available_tools  = array_keys( $tools );
			}
		}

		if ( empty( $available_tools ) ) {
			return array();
		}

		// Analyze task to determine type.
		$task_type = $this->analyze_task_type( $task, $context );

		// Get historical patterns for this task type.
		$patterns = $this->get_patterns_for_task_type( $task_type );

		// If we have patterns, use them.
		if ( ! empty( $patterns ) ) {
			return $this->predict_from_patterns( $patterns, $available_tools, $task );
		}

		// Fall back to heuristic prediction.
		return $this->predict_heuristically( $task, $available_tools );
	}

	/**
	 * Optimize tool execution chain
	 *
	 * Identifies opportunities for parallelization and eliminates redundancy.
	 *
	 * @param array $tool_chain Predicted or actual tool chain.
	 * @return array Optimized chain with execution plan.
	 */
	public function optimize_chain( $tool_chain ) {
		if ( empty( $tool_chain ) ) {
			return array();
		}

		$optimized = array(
			'sequential' => array(),
			'parallel'   => array(),
			'optimizations' => array(),
		);

		// Build dependency graph.
		$dependencies = $this->build_dependency_graph( $tool_chain );

		// Identify parallel execution opportunities.
		$parallel_groups = $this->identify_parallel_groups( $tool_chain, $dependencies );

		// Eliminate redundant tools.
		$unique_tools = $this->eliminate_redundancy( $tool_chain );

		// Reorder for optimal data flow.
		$reordered = $this->reorder_for_data_flow( $unique_tools, $dependencies );

		// Build execution plan.
		foreach ( $reordered as $step_index => $tool_info ) {
			$tool_slug = is_array( $tool_info ) ? $tool_info['tool_slug'] : $tool_info;

			// Check if this tool can run in parallel.
			$parallel_group = $this->find_parallel_group( $tool_slug, $parallel_groups );

			if ( $parallel_group ) {
				if ( ! isset( $optimized['parallel'][ $parallel_group ] ) ) {
					$optimized['parallel'][ $parallel_group ] = array();
				}
				$optimized['parallel'][ $parallel_group ][] = $tool_info;
			} else {
				$optimized['sequential'][] = $tool_info;
			}
		}

		// Record optimizations made.
		$original_count = count( $tool_chain );
		$optimized_count = count( $reordered );
		if ( $original_count > $optimized_count ) {
			$optimized['optimizations'][] = sprintf(
				'Eliminated %d redundant tool(s)',
				$original_count - $optimized_count
			);
		}

		if ( ! empty( $optimized['parallel'] ) ) {
			$parallel_count = array_sum( array_map( 'count', $optimized['parallel'] ) );
			$optimized['optimizations'][] = sprintf(
				'Identified %d tool(s) for parallel execution',
				$parallel_count
			);
		}

		return $optimized;
	}

	/**
	 * Execute speculative tool chain
	 *
	 * Pre-warms tools and caches likely intermediate results.
	 *
	 * @param array $predicted_chain Predicted tool chain.
	 * @param array $context Execution context.
	 * @return array Speculation status.
	 */
	public function execute_speculative_chain( $predicted_chain, $context ) {
		if ( empty( $predicted_chain ) ) {
			return array(
				'prewarmed' => 0,
				'cached'    => 0,
			);
		}

		$prewarmed = 0;
		$cached    = 0;

		// Pre-warm high-confidence tools.
		foreach ( $predicted_chain as $tool_prediction ) {
			if ( ! is_array( $tool_prediction ) ) {
				continue;
			}

			$tool_slug  = $tool_prediction['tool_slug'] ?? '';
			$confidence = $tool_prediction['confidence'] ?? 0;

			// Only pre-warm if confidence > 0.8.
			if ( $confidence < 0.8 ) {
				continue;
			}

			// Pre-warm tool (ensure it's loaded and ready).
			if ( $this->prewarm_tool( $tool_slug ) ) {
				++$prewarmed;
			}

			// Check if result can be cached speculatively.
			// This is done by the load balancer, so we just track intent.
			++$cached;
		}

		return array(
			'prewarmed'  => $prewarmed,
			'cached'     => $cached,
			'total_predicted' => count( $predicted_chain ),
		);
	}

	/**
	 * Record tool chain execution for learning
	 *
	 * Stores executed tool chains to improve future predictions.
	 *
	 * @param array $tool_chain Executed tool chain.
	 * @param array $context Execution context.
	 * @param bool  $success Whether execution succeeded.
	 * @return void
	 */
	public function record_chain_execution( $tool_chain, $context, $success ) {
		if ( empty( $tool_chain ) ) {
			return;
		}

		// Get current history.
		$history = get_option( self::CHAIN_HISTORY_KEY, array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}

		// Extract task type if available.
		$task_type = $context['task_type'] ?? 'general';

		// Add new entry.
		$history[] = array(
			'task_type'  => $task_type,
			'tool_chain' => $tool_chain,
			'success'    => $success,
			'timestamp'  => time(),
			'context'    => $this->sanitize_context( $context ),
		);

		// Limit history size.
		if ( count( $history ) > self::HISTORY_LIMIT ) {
			$history = array_slice( $history, -self::HISTORY_LIMIT );
		}

		// Update option.
		update_option( self::CHAIN_HISTORY_KEY, $history, false );

		// Invalidate pattern cache.
		wp_cache_delete( self::PATTERN_CACHE_KEY, 'mcp_ai_tool_chains' );
	}

	/**
	 * Analyze task type from description
	 *
	 * @param string $task Task description.
	 * @param array  $context Task context.
	 * @return string Task type identifier.
	 */
	protected function analyze_task_type( $task, $context ) {
		$task_lower = strtolower( $task );

		// Check for explicit task type in context.
		if ( isset( $context['task_type'] ) ) {
			return sanitize_key( $context['task_type'] );
		}

		// Heuristic task type detection.
		$type_patterns = array(
			'research'   => array( 'research', 'find', 'search', 'gather', 'collect' ),
			'create'     => array( 'create', 'write', 'generate', 'compose', 'draft' ),
			'analyze'    => array( 'analyze', 'review', 'evaluate', 'assess', 'examine' ),
			'update'     => array( 'update', 'modify', 'change', 'edit', 'revise' ),
			'list'       => array( 'list', 'show', 'display', 'enumerate', 'get all' ),
		);

		foreach ( $type_patterns as $type => $keywords ) {
			foreach ( $keywords as $keyword ) {
				if ( false !== strpos( $task_lower, $keyword ) ) {
					return $type;
				}
			}
		}

		return 'general';
	}

	/**
	 * Get patterns for task type
	 *
	 * @param string $task_type Task type identifier.
	 * @return array Tool chain patterns.
	 */
	protected function get_patterns_for_task_type( $task_type ) {
		// Check cache first.
		$cache_key = self::PATTERN_CACHE_KEY . '_' . $task_type;
		$cached    = wp_cache_get( $cache_key, 'mcp_ai_tool_chains' );

		if ( false !== $cached ) {
			return $cached;
		}

		// Get chain history.
		$history = get_option( self::CHAIN_HISTORY_KEY, array() );
		if ( empty( $history ) ) {
			return array();
		}

		// Filter by task type and success.
		$relevant_chains = array_filter(
			$history,
			function ( $entry ) use ( $task_type ) {
				return isset( $entry['task_type'] ) &&
					$entry['task_type'] === $task_type &&
					! empty( $entry['success'] );
			}
		);

		if ( empty( $relevant_chains ) ) {
			return array();
		}

		// Extract and count patterns.
		$patterns = array();
		foreach ( $relevant_chains as $entry ) {
			$chain_key = $this->generate_chain_key( $entry['tool_chain'] );
			if ( ! isset( $patterns[ $chain_key ] ) ) {
				$patterns[ $chain_key ] = array(
					'chain' => $entry['tool_chain'],
					'count' => 0,
				);
			}
			++$patterns[ $chain_key ]['count'];
		}

		// Sort by frequency.
		uasort(
			$patterns,
			function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);

		// Cache patterns.
		wp_cache_set( $cache_key, $patterns, 'mcp_ai_tool_chains', self::PATTERN_CACHE_TTL );

		return $patterns;
	}

	/**
	 * Predict from historical patterns
	 *
	 * @param array $patterns Historical patterns.
	 * @param array $available_tools Available tool slugs.
	 * @param string $task Task description.
	 * @return array Predicted chain.
	 */
	protected function predict_from_patterns( $patterns, $available_tools, $task ) {
		if ( empty( $patterns ) ) {
			return array();
		}

		// Get most frequent pattern.
		$top_pattern = reset( $patterns );
		$chain       = $top_pattern['chain'];
		$total_count = array_sum( array_column( $patterns, 'count' ) );
		$confidence  = $top_pattern['count'] / $total_count;

		// Filter tools that are available.
		$predicted = array();
		foreach ( $chain as $tool_slug ) {
			if ( ! in_array( $tool_slug, $available_tools, true ) ) {
				continue;
			}

			$predicted[] = array(
				'tool_slug'  => $tool_slug,
				'confidence' => $confidence,
				'source'     => 'pattern',
			);
		}

		return $predicted;
	}

	/**
	 * Predict heuristically when no patterns available
	 *
	 * @param string $task Task description.
	 * @param array  $available_tools Available tool slugs.
	 * @return array Predicted chain.
	 */
	protected function predict_heuristically( $task, $available_tools ) {
		// Simple heuristic: suggest commonly used tools for task keywords.
		$task_lower = strtolower( $task );
		$predicted  = array();

		$heuristics = array(
			'search' => array( 'search_content', 'get_recent_posts' ),
			'create' => array( 'create_post', 'upload_media' ),
			'list'   => array( 'get_recent_posts', 'list_categories' ),
			'user'   => array( 'get_user_info', 'list_users' ),
			'post'   => array( 'get_post', 'get_recent_posts' ),
		);

		foreach ( $heuristics as $keyword => $tools ) {
			if ( false !== strpos( $task_lower, $keyword ) ) {
				foreach ( $tools as $tool_slug ) {
					if ( in_array( $tool_slug, $available_tools, true ) ) {
						$predicted[] = array(
							'tool_slug'  => $tool_slug,
							'confidence' => 0.6,
							'source'     => 'heuristic',
						);
					}
				}
			}
		}

		return $predicted;
	}

	/**
	 * Build dependency graph for tool chain
	 *
	 * @param array $tool_chain Tool chain.
	 * @return array Dependency graph.
	 */
	protected function build_dependency_graph( $tool_chain ) {
		$dependencies = array();

		// Simple dependency tracking based on position.
		// Tools later in chain may depend on earlier tools.
		foreach ( $tool_chain as $index => $tool_info ) {
			$tool_slug = is_array( $tool_info ) ? $tool_info['tool_slug'] : $tool_info;

			$dependencies[ $tool_slug ] = array();

			// Assume tools depend on all previous tools (conservative).
			for ( $i = 0; $i < $index; $i++ ) {
				$prev_tool = is_array( $tool_chain[ $i ] ) ? $tool_chain[ $i ]['tool_slug'] : $tool_chain[ $i ];
				$dependencies[ $tool_slug ][] = $prev_tool;
			}
		}

		return $dependencies;
	}

	/**
	 * Identify parallel execution groups
	 *
	 * @param array $tool_chain Tool chain.
	 * @param array $dependencies Dependency graph.
	 * @return array Parallel groups.
	 */
	protected function identify_parallel_groups( $tool_chain, $dependencies ) {
		$groups    = array();
		$group_id  = 0;
		$processed = array();

		foreach ( $tool_chain as $tool_info ) {
			$tool_slug = is_array( $tool_info ) ? $tool_info['tool_slug'] : $tool_info;

			if ( in_array( $tool_slug, $processed, true ) ) {
				continue;
			}

			// Find tools with no dependencies on each other.
			$group = array( $tool_slug );
			foreach ( $tool_chain as $other_info ) {
				$other_slug = is_array( $other_info ) ? $other_info['tool_slug'] : $other_info;

				if ( $other_slug === $tool_slug || in_array( $other_slug, $processed, true ) ) {
					continue;
				}

				// Check if tools are independent.
				$tool_deps  = $dependencies[ $tool_slug ] ?? array();
				$other_deps = $dependencies[ $other_slug ] ?? array();

				$independent = ! in_array( $other_slug, $tool_deps, true ) &&
							! in_array( $tool_slug, $other_deps, true );

				if ( $independent ) {
					$group[] = $other_slug;
				}
			}

			// Only create group if more than one tool.
			if ( count( $group ) > 1 ) {
				$groups[ $group_id ] = $group;
				$processed           = array_merge( $processed, $group );
				++$group_id;
			}
		}

		return $groups;
	}

	/**
	 * Eliminate redundant tools
	 *
	 * @param array $tool_chain Tool chain.
	 * @return array Chain without redundancy.
	 */
	protected function eliminate_redundancy( $tool_chain ) {
		$seen   = array();
		$unique = array();

		foreach ( $tool_chain as $tool_info ) {
			$tool_slug = is_array( $tool_info ) ? $tool_info['tool_slug'] : $tool_info;

			if ( ! in_array( $tool_slug, $seen, true ) ) {
				$seen[]   = $tool_slug;
				$unique[] = $tool_info;
			}
		}

		return $unique;
	}

	/**
	 * Reorder tools for optimal data flow
	 *
	 * @param array $tool_chain Tool chain.
	 * @param array $dependencies Dependency graph.
	 * @return array Reordered chain.
	 */
	protected function reorder_for_data_flow( $tool_chain, $dependencies ) {
		// For now, preserve original order as dependencies are built from it.
		// More sophisticated topological sorting could be added here.
		return $tool_chain;
	}

	/**
	 * Find parallel group for a tool
	 *
	 * @param string $tool_slug Tool slug.
	 * @param array  $parallel_groups Parallel groups.
	 * @return int|null Group ID or null.
	 */
	protected function find_parallel_group( $tool_slug, $parallel_groups ) {
		foreach ( $parallel_groups as $group_id => $group ) {
			if ( in_array( $tool_slug, $group, true ) ) {
				return $group_id;
			}
		}
		return null;
	}

	/**
	 * Pre-warm tool
	 *
	 * @param string $tool_slug Tool slug.
	 * @return bool Success status.
	 */
	protected function prewarm_tool( $tool_slug ) {
		$registry = $this->get_registry();
		if ( ! $registry ) {
			return false;
		}

		// Get tool to ensure it's loaded.
		$tool = $registry->get_tool( $tool_slug );
		return null !== $tool;
	}

	/**
	 * Generate chain key for pattern matching
	 *
	 * @param array $tool_chain Tool chain.
	 * @return string Chain key.
	 */
	protected function generate_chain_key( $tool_chain ) {
		$slugs = array();
		foreach ( $tool_chain as $tool ) {
			$slugs[] = is_array( $tool ) ? $tool['tool_slug'] : $tool;
		}
		return implode( '->', $slugs );
	}

	/**
	 * Sanitize context for storage
	 *
	 * @param array $context Context array.
	 * @return array Sanitized context.
	 */
	protected function sanitize_context( $context ) {
		// Remove sensitive data from context before storage.
		$safe_keys = array( 'task_type', 'assistant_id', 'user_id' );
		$sanitized = array();

		foreach ( $safe_keys as $key ) {
			if ( isset( $context[ $key ] ) ) {
				$sanitized[ $key ] = $context[ $key ];
			}
		}

		return $sanitized;
	}

	/**
	 * Get tool registry instance
	 *
	 * @return WP_MCP_AI_Tool_Registry|null Tool registry.
	 */
	protected function get_registry() {
		if ( ! $this->registry && class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$this->registry = WP_MCP_AI_Tool_Registry::get_instance();
		}
		return $this->registry;
	}

	/**
	 * Clear chain history
	 *
	 * @return bool Success status.
	 */
	public function clear_history() {
		delete_option( self::CHAIN_HISTORY_KEY );
		wp_cache_delete( self::PATTERN_CACHE_KEY, 'mcp_ai_tool_chains' );
		return true;
	}
}
