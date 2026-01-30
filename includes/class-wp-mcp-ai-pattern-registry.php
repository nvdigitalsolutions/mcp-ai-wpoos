<?php
/**
 * Multi-Agent Pattern Registry
 *
 * Defines and manages the 8 standard multi-agent coordination patterns
 * based on industry best practices (OpenAI, Microsoft, Salesforce, Google Cloud).
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pattern Registry class
 *
 * Manages multi-agent patterns and provides pattern selection logic
 * for optimal agent team coordination.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Pattern_Registry {

	/**
	 * Pattern definitions
	 *
	 * @var array
	 */
	protected $patterns = array();

	/**
	 * Toolkit registry instance
	 *
	 * @var WP_MCP_AI_Toolkit_Registry|null
	 */
	protected $toolkit_registry;

	/**
	 * Constructor
	 *
	 * @param WP_MCP_AI_Toolkit_Registry|null $toolkit_registry Optional toolkit registry.
	 */
	public function __construct( $toolkit_registry = null ) {
		$this->toolkit_registry = $toolkit_registry;
		$this->init_patterns();
	}

	/**
	 * Initialize pattern definitions
	 */
	protected function init_patterns() {
		$this->patterns = array(
			WP_MCP_AI_Pattern_Constants::PATTERN_ORCHESTRATOR        => array(
				'name'               => 'Orchestrator (Supervisor)',
				'description'        => 'Centralized coordinator managing other agents. Best for complex tasks requiring oversight and coordination.',
				'use_cases'          => array(
					'Content creation workflows',
					'Multi-step business processes',
					'Complex analysis requiring multiple perspectives',
					'Customer service automation',
				),
				'strengths'          => array(
					'Clear accountability and decision-making',
					'Easy to debug and monitor',
					'Good for complex, multi-step tasks',
					'Centralized error handling',
				),
				'weaknesses'         => array(
					'Single point of failure',
					'Can become bottleneck with many agents',
					'Orchestrator requires high capability',
				),
				'best_for_toolkits'  => array(
					'content_publishing',
					'ecommerce_business',
					'research_discovery',
					'communication_outreach',
				),
				'team_size'          => array(
					'min'     => 2,
					'max'     => 10,
					'optimal' => 5,
				),
				'complexity'         => 'medium',
				'scalability'        => 'medium',
				'fault_tolerance'    => 'low',
				'coordination_style' => 'centralized',
			),
			WP_MCP_AI_Pattern_Constants::PATTERN_SEQUENTIAL         => array(
				'name'               => 'Sequential Pipeline',
				'description'        => 'Linear chain where each agent processes and passes results to the next. Like an assembly line.',
				'use_cases'          => array(
					'Media processing (resize → crop → optimize)',
					'Data transformation pipelines',
					'Multi-stage content enhancement',
					'Progressive analysis workflows',
				),
				'strengths'          => array(
					'Simple to understand and implement',
					'Predictable execution flow',
					'Easy to optimize individual stages',
					'Natural error isolation',
				),
				'weaknesses'         => array(
					'No parallel processing',
					'Failure in one stage blocks entire pipeline',
					'Can be slow for long chains',
				),
				'best_for_toolkits'  => array(
					'media_processing',
					'data_analytics',
					'workflow_automation',
				),
				'team_size'          => array(
					'min'     => 2,
					'max'     => 8,
					'optimal' => 4,
				),
				'complexity'         => 'low',
				'scalability'        => 'low',
				'fault_tolerance'    => 'low',
				'coordination_style' => 'linear',
			),
			WP_MCP_AI_Pattern_Constants::PATTERN_PEER_TO_PEER       => array(
				'name'               => 'Peer-to-Peer Collaboration',
				'description'        => 'Agents work together as equals, collaborating and negotiating. Best for creative and analytical tasks.',
				'use_cases'          => array(
					'Brainstorming and ideation',
					'Multi-perspective analysis',
					'Collaborative problem solving',
					'Consensus building',
				),
				'strengths'          => array(
					'Diverse perspectives',
					'No single point of failure',
					'Good for creative tasks',
					'Democratic decision-making',
				),
				'weaknesses'         => array(
					'Can be slow to reach consensus',
					'Coordination overhead',
					'Risk of conflicts',
				),
				'best_for_toolkits'  => array(
					'data_analytics',
					'research_discovery',
					'content_publishing',
				),
				'team_size'          => array(
					'min'     => 3,
					'max'     => 6,
					'optimal' => 4,
				),
				'complexity'         => 'high',
				'scalability'        => 'low',
				'fault_tolerance'    => 'medium',
				'coordination_style' => 'distributed',
			),
			WP_MCP_AI_Pattern_Constants::PATTERN_SKILL_ROUTER       => array(
				'name'               => 'Skill Router',
				'description'        => 'Routes tasks to specialized agents based on required skills. Like a smart dispatcher.',
				'use_cases'          => array(
					'Technical support routing',
					'Development task assignment',
					'API integration management',
					'Specialized tool selection',
				),
				'strengths'          => array(
					'Optimal resource utilization',
					'Expert handling of specialized tasks',
					'Easy to add new specialists',
					'Good scalability',
				),
				'weaknesses'         => array(
					'Requires good skill taxonomy',
					'Router becomes critical component',
					'May have skill gaps',
				),
				'best_for_toolkits'  => array(
					'developer_technical',
					'integration_external',
					'ai_model_management',
				),
				'team_size'          => array(
					'min'     => 3,
					'max'     => 15,
					'optimal' => 7,
				),
				'complexity'         => 'medium',
				'scalability'        => 'high',
				'fault_tolerance'    => 'medium',
				'coordination_style' => 'centralized',
			),
			WP_MCP_AI_Pattern_Constants::PATTERN_LAYERED_DEFENSE    => array(
				'name'               => 'Layered Defense',
				'description'        => 'Multiple layers of agents provide validation and security checks. Defense in depth approach.',
				'use_cases'          => array(
					'Security validation',
					'Content moderation',
					'Compliance checking',
					'Multi-stage approval',
				),
				'strengths'          => array(
					'High security and reliability',
					'Multiple validation points',
					'Reduces false positives/negatives',
					'Thorough error checking',
				),
				'weaknesses'         => array(
					'Higher latency',
					'More resource intensive',
					'Can be overly cautious',
				),
				'best_for_toolkits'  => array(
					'security_compliance',
					'content_publishing',
					'ecommerce_business',
				),
				'team_size'          => array(
					'min'     => 2,
					'max'     => 5,
					'optimal' => 3,
				),
				'complexity'         => 'medium',
				'scalability'        => 'medium',
				'fault_tolerance'    => 'high',
				'coordination_style' => 'linear',
			),
			WP_MCP_AI_Pattern_Constants::PATTERN_EVENT_DRIVEN       => array(
				'name'               => 'Event-Driven Response',
				'description'        => 'Agents react to events and triggers. Best for real-time monitoring and response.',
				'use_cases'          => array(
					'Real-time monitoring',
					'Alert and notification systems',
					'Location-based services',
					'Dynamic response to conditions',
				),
				'strengths'          => array(
					'Responsive and real-time',
					'Efficient resource usage',
					'Good for monitoring',
					'Scales with event volume',
				),
				'weaknesses'         => array(
					'Complex event management',
					'Potential event storms',
					'Harder to debug',
				),
				'best_for_toolkits'  => array(
					'geospatial_location',
					'workflow_automation',
					'security_compliance',
				),
				'team_size'          => array(
					'min'     => 2,
					'max'     => 12,
					'optimal' => 5,
				),
				'complexity'         => 'high',
				'scalability'        => 'high',
				'fault_tolerance'    => 'medium',
				'coordination_style' => 'event-based',
			),
			WP_MCP_AI_Pattern_Constants::PATTERN_HIERARCHICAL       => array(
				'name'               => 'Hierarchical Orchestrator',
				'description'        => 'Multi-level management with supervisors and workers. Like a company org chart.',
				'use_cases'          => array(
					'Large-scale project management',
					'Enterprise workflow automation',
					'Multi-department coordination',
					'Complex hierarchical processes',
				),
				'strengths'          => array(
					'Scales to large teams',
					'Clear chain of command',
					'Good for complex organizations',
					'Parallel execution at each level',
				),
				'weaknesses'         => array(
					'High coordination overhead',
					'Complex to set up',
					'Communication delays',
				),
				'best_for_toolkits'  => array(
					'workflow_automation',
					'ecommerce_business',
					'developer_technical',
				),
				'team_size'          => array(
					'min'     => 5,
					'max'     => 20,
					'optimal' => 10,
				),
				'complexity'         => 'high',
				'scalability'        => 'high',
				'fault_tolerance'    => 'medium',
				'coordination_style' => 'hierarchical',
			),
			WP_MCP_AI_Pattern_Constants::PATTERN_EXPERIMENTATION    => array(
				'name'               => 'Experimentation Pipeline',
				'description'        => 'Multiple agents try different approaches, then select the best result. A/B testing for AI.',
				'use_cases'          => array(
					'Model selection and optimization',
					'A/B testing content variations',
					'Hyperparameter tuning',
					'Best practice discovery',
				),
				'strengths'          => array(
					'Finds optimal solutions',
					'Good for uncertain problems',
					'Self-improving over time',
					'Reduces bias',
				),
				'weaknesses'         => array(
					'Resource intensive',
					'Requires result comparison',
					'May be slow',
				),
				'best_for_toolkits'  => array(
					'ai_model_management',
					'data_analytics',
					'content_publishing',
				),
				'team_size'          => array(
					'min'     => 2,
					'max'     => 8,
					'optimal' => 4,
				),
				'complexity'         => 'high',
				'scalability'        => 'medium',
				'fault_tolerance'    => 'high',
				'coordination_style' => 'parallel',
			),
		);
	}

	/**
	 * Get all patterns
	 *
	 * @return array Pattern definitions.
	 */
	public function get_all_patterns() {
		return $this->patterns;
	}

	/**
	 * Get a specific pattern
	 *
	 * @param string $pattern_slug Pattern slug.
	 * @return array|null Pattern definition or null if not found.
	 */
	public function get_pattern( $pattern_slug ) {
		return isset( $this->patterns[ $pattern_slug ] ) ? $this->patterns[ $pattern_slug ] : null;
	}

	/**
	 * Get patterns for a toolkit
	 *
	 * @param string $toolkit_slug Toolkit slug.
	 * @return array Array of pattern slugs suitable for the toolkit.
	 */
	public function get_patterns_for_toolkit( $toolkit_slug ) {
		$suitable_patterns = array();

		foreach ( $this->patterns as $pattern_slug => $pattern ) {
			if ( in_array( $toolkit_slug, $pattern['best_for_toolkits'], true ) ) {
				$suitable_patterns[] = $pattern_slug;
			}
		}

		return $suitable_patterns;
	}

	/**
	 * Select best pattern for a task
	 *
	 * @param array $task_requirements Task requirements including toolkit, team size, etc.
	 * @return string|null Best pattern slug or null if no suitable pattern found.
	 */
	public function select_pattern( $task_requirements ) {
		$toolkit    = isset( $task_requirements['toolkit'] ) ? $task_requirements['toolkit'] : null;
		$team_size  = isset( $task_requirements['team_size'] ) ? absint( $task_requirements['team_size'] ) : 3;
		$complexity = isset( $task_requirements['complexity'] ) ? $task_requirements['complexity'] : 'medium';
		$need_fault = isset( $task_requirements['fault_tolerance'] ) ? $task_requirements['fault_tolerance'] : false;
		$need_scale = isset( $task_requirements['scalability'] ) ? $task_requirements['scalability'] : false;

		$scores = array();

		foreach ( $this->patterns as $pattern_slug => $pattern ) {
			$score = 0;

			// Toolkit match (highest weight).
			if ( $toolkit && in_array( $toolkit, $pattern['best_for_toolkits'], true ) ) {
				$score += 50;
			}

			// Team size fit.
			if ( $team_size >= $pattern['team_size']['min'] && $team_size <= $pattern['team_size']['max'] ) {
				$score += 20;
				// Bonus for optimal size.
				if ( abs( $team_size - $pattern['team_size']['optimal'] ) <= 1 ) {
					$score += 10;
				}
			}

			// Complexity match.
			if ( $complexity === $pattern['complexity'] ) {
				$score += 15;
			}

			// Fault tolerance need.
			if ( $need_fault && 'high' === $pattern['fault_tolerance'] ) {
				$score += 10;
			}

			// Scalability need.
			if ( $need_scale && 'high' === $pattern['scalability'] ) {
				$score += 10;
			}

			$scores[ $pattern_slug ] = $score;
		}

		// Return pattern with highest score.
		arsort( $scores );
		$best_pattern = key( $scores );

		// Return null if no pattern scored above threshold.
		return $scores[ $best_pattern ] >= 20 ? $best_pattern : null;
	}

	/**
	 * Get pattern recommendations for a toolkit
	 *
	 * @param string $toolkit_slug Toolkit slug.
	 * @return array Array of patterns with scores.
	 */
	public function recommend_patterns_for_toolkit( $toolkit_slug ) {
		$recommendations = array();

		foreach ( $this->patterns as $pattern_slug => $pattern ) {
			$score = 0;

			if ( in_array( $toolkit_slug, $pattern['best_for_toolkits'], true ) ) {
				// Primary pattern for this toolkit.
				$score = 100;
			} elseif ( $this->toolkit_registry ) {
				// Check if toolkit and pattern have compatible characteristics.
				$toolkit_info = $this->toolkit_registry->get_toolkit( $toolkit_slug );
				if ( $toolkit_info && isset( $toolkit_info['primary_pattern'] ) ) {
					$primary_pattern = $toolkit_info['primary_pattern'];
					$pattern_info    = $this->get_pattern( $primary_pattern );

					// Give partial score if coordination styles match.
					if ( $pattern_info && $pattern_info['coordination_style'] === $pattern['coordination_style'] ) {
						$score = 40;
					}
				}
			}

			if ( 0 < $score ) {
				$recommendations[ $pattern_slug ] = array(
					'pattern'     => $pattern,
					'score'       => $score,
					'primary'     => 100 === $score,
					'description' => $pattern['description'],
				);
			}
		}

		// Sort by score descending.
		uasort(
			$recommendations,
			function ( $a, $b ) {
				return $b['score'] - $a['score'];
			}
		);

		return $recommendations;
	}

	/**
	 * Validate pattern compatibility with team composition
	 *
	 * @param string $pattern_slug Pattern slug.
	 * @param array  $team_members Team members array.
	 * @return bool|WP_Error True if compatible, WP_Error otherwise.
	 */
	public function validate_pattern_compatibility( $pattern_slug, $team_members ) {
		$pattern = $this->get_pattern( $pattern_slug );
		if ( ! $pattern ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_pattern',
				__( 'Invalid pattern specified.', 'mcp-ai-wpoos' )
			);
		}

		$team_size = count( $team_members );

		// Check team size requirements.
		if ( $team_size < $pattern['team_size']['min'] ) {
			return new WP_Error(
				'wp_mcp_ai_team_too_small',
				sprintf(
					/* translators: 1: pattern name, 2: minimum team size */
					__( 'Pattern "%1$s" requires at least %2$d team members.', 'mcp-ai-wpoos' ),
					$pattern['name'],
					$pattern['team_size']['min']
				)
			);
		}

		if ( $team_size > $pattern['team_size']['max'] ) {
			return new WP_Error(
				'wp_mcp_ai_team_too_large',
				sprintf(
					/* translators: 1: pattern name, 2: maximum team size */
					__( 'Pattern "%1$s" works best with up to %2$d team members. Consider using a different pattern for larger teams.', 'mcp-ai-wpoos' ),
					$pattern['name'],
					$pattern['team_size']['max']
				)
			);
		}

		return true;
	}

	/**
	 * Get pattern statistics
	 *
	 * @return array Pattern usage and distribution statistics.
	 */
	public function get_pattern_statistics() {
		$stats = array(
			'total_patterns'     => count( $this->patterns ),
			'by_complexity'      => array(),
			'by_scalability'     => array(),
			'by_fault_tolerance' => array(),
			'by_coordination'    => array(),
			'avg_team_size'      => array(),
			'toolkit_coverage'   => array(),
		);

		foreach ( $this->patterns as $pattern_slug => $pattern ) {
			// Complexity distribution.
			if ( ! isset( $stats['by_complexity'][ $pattern['complexity'] ] ) ) {
				$stats['by_complexity'][ $pattern['complexity'] ] = 0;
			}
			++$stats['by_complexity'][ $pattern['complexity'] ];

			// Scalability distribution.
			if ( ! isset( $stats['by_scalability'][ $pattern['scalability'] ] ) ) {
				$stats['by_scalability'][ $pattern['scalability'] ] = 0;
			}
			++$stats['by_scalability'][ $pattern['scalability'] ];

			// Fault tolerance distribution.
			if ( ! isset( $stats['by_fault_tolerance'][ $pattern['fault_tolerance'] ] ) ) {
				$stats['by_fault_tolerance'][ $pattern['fault_tolerance'] ] = 0;
			}
			++$stats['by_fault_tolerance'][ $pattern['fault_tolerance'] ];

			// Coordination style distribution.
			if ( ! isset( $stats['by_coordination'][ $pattern['coordination_style'] ] ) ) {
				$stats['by_coordination'][ $pattern['coordination_style'] ] = 0;
			}
			++$stats['by_coordination'][ $pattern['coordination_style'] ];

			// Average team size.
			$stats['avg_team_size'][ $pattern_slug ] = $pattern['team_size']['optimal'];

			// Toolkit coverage.
			foreach ( $pattern['best_for_toolkits'] as $toolkit ) {
				if ( ! isset( $stats['toolkit_coverage'][ $toolkit ] ) ) {
					$stats['toolkit_coverage'][ $toolkit ] = array();
				}
				$stats['toolkit_coverage'][ $toolkit ][] = $pattern_slug;
			}
		}

		return $stats;
	}

	/**
	 * Get pattern comparison
	 *
	 * @param array $pattern_slugs Array of pattern slugs to compare.
	 * @return array Comparison matrix.
	 */
	public function compare_patterns( $pattern_slugs ) {
		$comparison = array();

		foreach ( $pattern_slugs as $slug ) {
			$pattern = $this->get_pattern( $slug );
			if ( $pattern ) {
				$comparison[ $slug ] = array(
					'name'              => $pattern['name'],
					'complexity'        => $pattern['complexity'],
					'scalability'       => $pattern['scalability'],
					'fault_tolerance'   => $pattern['fault_tolerance'],
					'coordination'      => $pattern['coordination_style'],
					'optimal_team_size' => $pattern['team_size']['optimal'],
					'toolkits'          => count( $pattern['best_for_toolkits'] ),
					'strengths'         => count( $pattern['strengths'] ),
					'weaknesses'        => count( $pattern['weaknesses'] ),
				);
			}
		}

		return $comparison;
	}
}
