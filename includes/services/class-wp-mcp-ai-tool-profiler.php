<?php
/**
 * Tool Profiler Service
 *
 * Profiles tool performance characteristics and recommends optimal tools for tasks.
 * Part of Phase 4.1: Tool Specialization System.
 *
 * @package WP_MCP_AI
 * @since 1.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool Profiler Service Class
 *
 * Analyzes tool execution history to identify performance patterns, optimal use cases,
 * and complementary tools. Provides data-driven recommendations for tool selection.
 *
 * @since 1.1.1
 */
class WP_MCP_AI_Tool_Profiler {

	/**
	 * Transient keys
	 */
	const PROFILE_CACHE_KEY  = 'wp_mcp_ai_tool_profiles';
	const EXECUTION_LOG_KEY  = 'wp_mcp_ai_tool_executions_';
	const RECOMMENDATION_KEY = 'wp_mcp_ai_tool_recommendations';

	/**
	 * Configuration
	 */
	const EXECUTION_HISTORY_LIMIT    = 100;  // Per tool.
	const PROFILE_CACHE_TTL          = 3600; // 1 hour.
	const MIN_EXECUTIONS_FOR_PROFILE = 5;    // Minimum data for reliable profile.

	/**
	 * Tool registry instance
	 *
	 * @var WP_MCP_AI_Tool_Registry|null
	 */
	protected $registry;

	/**
	 * Constructor
	 *
	 * @param WP_MCP_AI_Tool_Registry|null $registry Tool registry instance.
	 */
	public function __construct( $registry = null ) {
		$this->registry = $registry ?? WP_MCP_AI_Tool_Registry::get_instance();
	}

	/**
	 * Profile tool performance characteristics
	 *
	 * @param string $tool_slug Tool slug to profile.
	 * @return array|WP_Error Tool profile or error.
	 */
	public function profile_tool( $tool_slug ) {
		// Check cache first.
		$cached = $this->get_cached_profile( $tool_slug );
		if ( false !== $cached ) {
			return $cached;
		}

		// Get execution history.
		$executions = $this->get_tool_execution_history( $tool_slug );

		if ( count( $executions ) < self::MIN_EXECUTIONS_FOR_PROFILE ) {
			return new WP_Error(
				'insufficient_data',
				sprintf(
					/* translators: 1: tool slug, 2: minimum executions required */
					__( 'Insufficient execution data for %1$s. Need at least %2$d executions for reliable profiling.', 'mcp-ai-wpoos' ),
					$tool_slug,
					self::MIN_EXECUTIONS_FOR_PROFILE
				)
			);
		}

		// Build profile.
		$profile = array(
			'tool_slug'       => $tool_slug,
			'performance'     => $this->analyze_performance( $executions ),
			'specialization'  => $this->analyze_specialization( $executions, $tool_slug ),
			'recommendations' => $this->generate_recommendations( $executions, $tool_slug ),
			'last_updated'    => current_time( 'mysql' ),
			'sample_size'     => count( $executions ),
		);

		// Cache profile.
		$this->cache_profile( $tool_slug, $profile );

		return $profile;
	}

	/**
	 * Recommend tools for task
	 *
	 * @param string $task_description Task description.
	 * @param array  $context Task context.
	 * @return array Ranked tool recommendations with confidence scores.
	 */
	public function recommend_tools_for_task( $task_description, $context = array() ) {
		// Check cache.
		$cache_key = $this->get_recommendation_cache_key( $task_description, $context );
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached;
		}

		// Analyze task requirements.
		$task_features = $this->analyze_task_features( $task_description, $context );

		// Get all available tools.
		$available_tools = $this->registry->get_all_tools();

		// Score each tool.
		$scored_tools = array();
		foreach ( $available_tools as $tool_slug => $tool ) {
			$score = $this->calculate_tool_task_fit( $tool_slug, $task_features );
			if ( $score > 0 ) {
				$scored_tools[] = array(
					'tool_slug'  => $tool_slug,
					'tool_name'  => $tool->get_name(),
					'confidence' => $score,
					'reason'     => $this->get_recommendation_reason( $tool_slug, $task_features ),
				);
			}
		}

		// Sort by confidence (descending).
		usort(
			$scored_tools,
			function ( $a, $b ) {
				return $b['confidence'] <=> $a['confidence'];
			}
		);

		// Return top recommendations.
		$recommendations = array(
			'task'            => $task_description,
			'recommendations' => array_slice( $scored_tools, 0, 10 ),
			'task_features'   => $task_features,
			'timestamp'       => current_time( 'mysql' ),
		);

		// Cache for 5 minutes.
		set_transient( $cache_key, $recommendations, 300 );

		return $recommendations;
	}

	/**
	 * Record tool execution
	 *
	 * @param string $tool_slug Tool slug.
	 * @param array  $execution_data Execution data.
	 */
	public function record_execution( $tool_slug, $execution_data ) {
		$history = $this->get_tool_execution_history( $tool_slug );

		// Add new execution.
		$execution_record = array(
			'timestamp'      => time(),
			'execution_time' => $execution_data['execution_time'] ?? 0,
			'success'        => $execution_data['success'] ?? true,
			'memory_used'    => $execution_data['memory_used'] ?? 0,
			'context'        => $execution_data['context'] ?? array(),
			'task_type'      => $execution_data['task_type'] ?? 'unknown',
		);

		$history[] = $execution_record;

		// Maintain history limit (ring buffer).
		if ( count( $history ) > self::EXECUTION_HISTORY_LIMIT ) {
			$history = array_slice( $history, -self::EXECUTION_HISTORY_LIMIT );
		}

		// Store updated history.
		set_transient( self::EXECUTION_LOG_KEY . $tool_slug, $history, WEEK_IN_SECONDS );

		// Invalidate cached profile.
		$this->invalidate_cached_profile( $tool_slug );
	}

	/**
	 * Get tool execution history
	 *
	 * @param string $tool_slug Tool slug.
	 * @return array Execution history.
	 */
	protected function get_tool_execution_history( $tool_slug ) {
		$history = get_transient( self::EXECUTION_LOG_KEY . $tool_slug );
		return is_array( $history ) ? $history : array();
	}

	/**
	 * Analyze performance metrics
	 *
	 * @param array $executions Execution history.
	 * @return array Performance metrics.
	 */
	protected function analyze_performance( $executions ) {
		$execution_times = array_column( $executions, 'execution_time' );
		$successes       = array_filter( $executions, fn( $e ) => $e['success'] ?? true );
		$memory_usage    = array_column( $executions, 'memory_used' );

		return array(
			'avg_execution_time' => ! empty( $execution_times ) ? array_sum( $execution_times ) / count( $execution_times ) : 0,
			'min_execution_time' => ! empty( $execution_times ) ? min( $execution_times ) : 0,
			'max_execution_time' => ! empty( $execution_times ) ? max( $execution_times ) : 0,
			'success_rate'       => count( $executions ) > 0 ? ( count( $successes ) / count( $executions ) ) * 100 : 0,
			'total_executions'   => count( $executions ),
			'failed_executions'  => count( $executions ) - count( $successes ),
			'avg_memory_usage'   => ! empty( $memory_usage ) ? array_sum( $memory_usage ) / count( $memory_usage ) : 0,
		);
	}

	/**
	 * Analyze specialization characteristics
	 *
	 * @param array  $executions Execution history.
	 * @param string $tool_slug Tool slug.
	 * @return array Specialization analysis.
	 */
	protected function analyze_specialization( $executions, $tool_slug ) {
		// Group by task type.
		$task_types = array();
		foreach ( $executions as $execution ) {
			$task_type = $execution['task_type'] ?? 'unknown';
			if ( ! isset( $task_types[ $task_type ] ) ) {
				$task_types[ $task_type ] = array(
					'count'        => 0,
					'success_rate' => 0,
					'successes'    => 0,
				);
			}
			++$task_types[ $task_type ]['count'];
			if ( $execution['success'] ?? true ) {
				++$task_types[ $task_type ]['successes'];
			}
		}

		// Calculate success rates.
		foreach ( $task_types as $type => $data ) {
			$task_types[ $type ]['success_rate'] = ( $data['successes'] / $data['count'] ) * 100;
		}

		// Sort by count to find optimal use cases.
		uasort(
			$task_types,
			function ( $a, $b ) {
				return $b['count'] <=> $a['count'];
			}
		);

		return array(
			'optimal_use_cases'   => array_keys( array_slice( $task_types, 0, 3 ) ),
			'task_type_breakdown' => $task_types,
			'complementary_tools' => $this->find_complementary_tools( $executions, $tool_slug ),
		);
	}

	/**
	 * Find complementary tools
	 *
	 * @param array  $executions Execution history.
	 * @param string $tool_slug Tool slug.
	 * @return array Complementary tool slugs.
	 */
	protected function find_complementary_tools( $executions, $tool_slug ) {
		// Look for tools frequently used in same context.
		$co_occurring_tools = array();

		foreach ( $executions as $execution ) {
			$context_tools = $execution['context']['tools_used'] ?? array();
			foreach ( $context_tools as $other_tool ) {
				if ( $other_tool !== $tool_slug ) {
					if ( ! isset( $co_occurring_tools[ $other_tool ] ) ) {
						$co_occurring_tools[ $other_tool ] = 0;
					}
					++$co_occurring_tools[ $other_tool ];
				}
			}
		}

		// Sort by frequency.
		arsort( $co_occurring_tools );

		return array_keys( array_slice( $co_occurring_tools, 0, 5 ) );
	}

	/**
	 * Generate recommendations
	 *
	 * @param array  $executions Execution history.
	 * @param string $tool_slug Tool slug.
	 * @return array Recommendations.
	 */
	protected function generate_recommendations( $executions, $tool_slug  ) // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for tool-specific profiling. {
		$performance = $this->analyze_performance( $executions );

		$recommendations = array(
			'best_practices' => array(),
			'configuration'  => array(),
			'alternatives'   => array(),
		);

		// Performance-based recommendations.
		if ( $performance['avg_execution_time'] > 5 ) {
			$recommendations['best_practices'][] = __( 'Consider using async execution for this tool due to longer execution times.', 'mcp-ai-wpoos' );
		}

		if ( $performance['success_rate'] < 80 ) {
			$recommendations['best_practices'][] = __( 'Success rate is below 80%. Review error patterns and improve error handling.', 'mcp-ai-wpoos' );
		}

		if ( $performance['avg_memory_usage'] > 50 * MB_IN_BYTES ) {
			$recommendations['configuration'][] = __( 'High memory usage detected. Consider implementing result streaming or pagination.', 'mcp-ai-wpoos' );
		}

		return $recommendations;
	}

	/**
	 * Analyze task features
	 *
	 * @param string $task_description Task description.
	 * @param array  $context Task context.
	 * @return array Task features.
	 */
	protected function analyze_task_features( $task_description, $context  ) // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for future implementation. {
		$features = array(
			'keywords'      => array(),
			'task_type'     => 'general',
			'complexity'    => 'medium',
			'requires_auth' => false,
			'data_type'     => 'text',
		);

		$task_lower = strtolower( $task_description );

		// Detect task type from keywords.
		if ( preg_match( '/\b(search|find|lookup|query)\b/', $task_lower ) ) {
			$features['task_type'] = 'search';
			$features['keywords']  = array( 'search', 'query' );
		} elseif ( preg_match( '/\b(create|generate|make|build)\b/', $task_lower ) ) {
			$features['task_type'] = 'create';
			$features['keywords']  = array( 'create', 'generate' );
		} elseif ( preg_match( '/\b(analyze|review|check|validate)\b/', $task_lower ) ) {
			$features['task_type'] = 'analyze';
			$features['keywords']  = array( 'analyze', 'review' );
		} elseif ( preg_match( '/\b(update|modify|edit|change)\b/', $task_lower ) ) {
			$features['task_type'] = 'update';
			$features['keywords']  = array( 'update', 'modify' );
		}

		// Detect data type.
		if ( preg_match( '/\b(image|picture|photo)\b/', $task_lower ) ) {
			$features['data_type'] = 'image';
		} elseif ( preg_match( '/\b(video|movie|clip)\b/', $task_lower ) ) {
			$features['data_type'] = 'video';
		} elseif ( preg_match( '/\b(audio|sound|music|speech)\b/', $task_lower ) ) {
			$features['data_type'] = 'audio';
		} elseif ( preg_match( '/\b(code|program|script|function)\b/', $task_lower ) ) {
			$features['data_type'] = 'code';
		}

		return $features;
	}

	/**
	 * Calculate tool-task fit score
	 *
	 * @param string $tool_slug Tool slug.
	 * @param array  $task_features Task features.
	 * @return float Confidence score (0-1).
	 */
	protected function calculate_tool_task_fit( $tool_slug, $task_features ) {
		$tool = $this->registry->get_tool( $tool_slug );
		if ( ! $tool ) {
			return 0;
		}

		$score = 0;

		// Get tool description and name.
		$tool_text = strtolower( $tool->get_name() . ' ' . $tool->get_description() );

		// Match keywords.
		foreach ( $task_features['keywords'] as $keyword ) {
			if ( false !== strpos( $tool_text, $keyword ) ) {
				$score += 0.2;
			}
		}

		// Match data type.
		if ( false !== strpos( $tool_text, $task_features['data_type'] ) ) {
			$score += 0.3;
		}

		// Match task type.
		if ( false !== strpos( $tool_text, $task_features['task_type'] ) ) {
			$score += 0.3;
		}

		// Get historical performance for similar tasks.
		$profile = $this->profile_tool( $tool_slug );
		if ( ! is_wp_error( $profile ) ) {
			$specialization = $profile['specialization'] ?? array();
			if ( in_array( $task_features['task_type'], $specialization['optimal_use_cases'] ?? array(), true ) ) {
				$score += 0.2;
			}
		}

		return min( 1.0, $score );
	}

	/**
	 * Get recommendation reason
	 *
	 * @param string $tool_slug Tool slug.
	 * @param array  $task_features Task features.
	 * @return string Reason for recommendation.
	 */
	protected function get_recommendation_reason( $tool_slug, $task_features ) {
		return sprintf(
			/* translators: 1: tool slug, 2: task type */
			__( '%1$s is recommended for %2$s tasks based on keyword matching and historical performance.', 'mcp-ai-wpoos' ),
			$tool_slug,
			$task_features['task_type']
		);
	}

	/**
	 * Get cached profile
	 *
	 * @param string $tool_slug Tool slug.
	 * @return array|false Cached profile or false.
	 */
	protected function get_cached_profile( $tool_slug ) {
		$all_profiles = get_transient( self::PROFILE_CACHE_KEY );
		if ( ! is_array( $all_profiles ) ) {
			return false;
		}
		return $all_profiles[ $tool_slug ] ?? false;
	}

	/**
	 * Cache profile
	 *
	 * @param string $tool_slug Tool slug.
	 * @param array  $profile Profile data.
	 */
	protected function cache_profile( $tool_slug, $profile ) {
		$all_profiles = get_transient( self::PROFILE_CACHE_KEY );
		if ( ! is_array( $all_profiles ) ) {
			$all_profiles = array();
		}
		$all_profiles[ $tool_slug ] = $profile;
		set_transient( self::PROFILE_CACHE_KEY, $all_profiles, self::PROFILE_CACHE_TTL );
	}

	/**
	 * Invalidate cached profile
	 *
	 * @param string $tool_slug Tool slug.
	 */
	protected function invalidate_cached_profile( $tool_slug ) {
		$all_profiles = get_transient( self::PROFILE_CACHE_KEY );
		if ( is_array( $all_profiles ) && isset( $all_profiles[ $tool_slug ] ) ) {
			unset( $all_profiles[ $tool_slug ] );
			set_transient( self::PROFILE_CACHE_KEY, $all_profiles, self::PROFILE_CACHE_TTL );
		}
	}

	/**
	 * Get recommendation cache key
	 *
	 * @param string $task_description Task description.
	 * @param array  $context Task context.
	 * @return string Cache key.
	 */
	protected function get_recommendation_cache_key( $task_description, $context ) {
		return self::RECOMMENDATION_KEY . '_' . md5( $task_description . wp_json_encode( $context ) );
	}

	/**
	 * Clear all profile data
	 */
	public function clear_profiles() {
		delete_transient( self::PROFILE_CACHE_KEY );
	}

	/**
	 * Clear execution history for tool
	 *
	 * @param string $tool_slug Tool slug.
	 */
	public function clear_tool_history( $tool_slug ) {
		delete_transient( self::EXECUTION_LOG_KEY . $tool_slug );
		$this->invalidate_cached_profile( $tool_slug );
	}
}
