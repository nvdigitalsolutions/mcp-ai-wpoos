<?php
/**
 * Reasoning Mode Controller Service
 *
 * Detects when enhanced reasoning is beneficial and activates appropriate
 * reasoning modes for complex multi-step tasks. Part of Phase 3: Advanced
 * Reasoning Support enhancements.
 *
 * Features:
 * - Task complexity detection
 * - Reasoning mode activation
 * - Chain-of-thought prompting
 * - Quality tracking and metrics
 *
 * @package WP_MCP_AI
 * @since 1.1.1
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reasoning Mode Controller class
 *
 * Manages enhanced reasoning capabilities for complex tasks.
 *
 * @since 1.1.1
 */
class WP_MCP_AI_Reasoning_Controller {

	/**
	 * Storage keys
	 */
	const QUALITY_METRICS_KEY   = 'wp_mcp_ai_reasoning_quality_metrics';
	const REASONING_HISTORY_KEY = 'wp_mcp_ai_reasoning_history';

	/**
	 * Configuration
	 */
	const REASONING_THRESHOLD = 0.7;  // Score needed to activate reasoning mode.
	const HISTORY_LIMIT       = 500;  // Max reasoning tasks to track.
	const METRICS_CACHE_TTL   = 3600; // 1 hour.

	/**
	 * Reasoning mode triggers and weights
	 *
	 * @var array
	 */
	protected $trigger_weights = array(
		'multi_step'          => 0.3,
		'logical_complexity'  => 0.25,
		'code_generation'     => 0.2,
		'domain_expertise'    => 0.15,
		'verification_needed' => 0.1,
	);

	/**
	 * Detect if task requires enhanced reasoning
	 *
	 * Analyzes task characteristics and returns whether reasoning mode
	 * should be activated.
	 *
	 * @param string $task Task description.
	 * @param array  $context Task context.
	 * @return bool True if reasoning mode recommended.
	 */
	public function requires_enhanced_reasoning( $task, $context ) {
		$indicators = array(
			'multi_step'          => $this->is_multi_step_task( $task, $context ),
			'logical_complexity'  => $this->calculate_logical_complexity( $task, $context ),
			'code_generation'     => $this->involves_code_generation( $task, $context ),
			'domain_expertise'    => $this->requires_domain_expertise( $task, $context ),
			'verification_needed' => $this->needs_verification( $task, $context ),
		);

		// Calculate reasoning score.
		$reasoning_score = $this->calculate_reasoning_score( $indicators );

		// Store decision for learning.
		$this->record_reasoning_decision( $task, $indicators, $reasoning_score );

		return $reasoning_score > self::REASONING_THRESHOLD;
	}

	/**
	 * Activate reasoning mode for model configuration
	 *
	 * Enhances model configuration with reasoning-specific settings.
	 *
	 * @param array $model_config Base model configuration.
	 * @param array $task_info Task information.
	 * @return array Enhanced model configuration.
	 */
	public function activate_reasoning_mode( $model_config, $task_info = array() ) {
		// Clone config to avoid modifying original.
		$enhanced_config = $model_config;

		// Add reasoning-enhancing system prompt.
		$reasoning_prompt = $this->get_reasoning_prompt( $task_info );
		if ( isset( $enhanced_config['system_prompt'] ) ) {
			$enhanced_config['system_prompt'] .= "\n\n" . $reasoning_prompt;
		} else {
			$enhanced_config['system_prompt'] = $reasoning_prompt;
		}

		// Adjust temperature for careful thinking (lower = more deterministic).
		if ( ! isset( $enhanced_config['temperature'] ) || $enhanced_config['temperature'] > 0.5 ) {
			$enhanced_config['temperature'] = 0.3;
		}

		// Enable chain-of-thought patterns.
		$enhanced_config['reasoning_enabled'] = true;
		$enhanced_config['reasoning_type']    = 'chain_of_thought';

		// Configure verification steps.
		$enhanced_config['verify_steps'] = true;

		// Add metadata.
		$enhanced_config['reasoning_metadata'] = array(
			'activated_at' => current_time( 'mysql' ),
			'task_type'    => $task_info['type'] ?? 'general',
			'complexity'   => $task_info['complexity'] ?? 'unknown',
		);

		return apply_filters( 'wp_mcp_ai_reasoning_config', $enhanced_config, $task_info );
	}

	/**
	 * Track reasoning quality
	 *
	 * Evaluates reasoning output quality and stores metrics.
	 *
	 * @param string $task Original task.
	 * @param array  $reasoning_output Reasoning process output.
	 * @param mixed  $final_result Final result.
	 * @param bool   $success Whether task succeeded.
	 * @return array Quality metrics.
	 */
	public function track_reasoning_quality( $task, $reasoning_output, $final_result, $success ) {
		$metrics = array(
			'coherence'           => $this->evaluate_coherence( $reasoning_output ),
			'logical_consistency' => $this->check_logical_consistency( $reasoning_output ),
			'completeness'        => $this->check_completeness( $reasoning_output, $task ),
			'success'             => $success,
			'timestamp'           => time(),
		);

		// Store metrics.
		$this->store_quality_metrics( $task, $metrics );

		return $metrics;
	}

	/**
	 * Get reasoning quality statistics
	 *
	 * @param int $days Number of days to analyze (default 30).
	 * @return array Quality statistics.
	 */
	public function get_quality_statistics( $days = 30 ) {
		$metrics = $this->get_quality_metrics_history( $days );

		if ( empty( $metrics ) ) {
			return array(
				'total_tasks'      => 0,
				'success_rate'     => 0,
				'avg_coherence'    => 0,
				'avg_consistency'  => 0,
				'avg_completeness' => 0,
			);
		}

		$total      = count( $metrics );
		$successful = array_filter(
			$metrics,
			function ( $m ) {
				return ! empty( $m['success'] );
			}
		);

		return array(
			'total_tasks'      => $total,
			'success_rate'     => ( count( $successful ) / $total ) * 100,
			'avg_coherence'    => array_sum( array_column( $metrics, 'coherence' ) ) / $total,
			'avg_consistency'  => array_sum( array_column( $metrics, 'logical_consistency' ) ) / $total,
			'avg_completeness' => array_sum( array_column( $metrics, 'completeness' ) ) / $total,
		);
	}

	/**
	 * Check if task is multi-step
	 *
	 * @param string $task Task description.
	 * @param array  $context Task context.
	 * @return float Score 0-1.
	 */
	protected function is_multi_step_task( $task, $context ) {
		$task_lower = strtolower( $task );

		// Keywords indicating multi-step process.
		$multi_step_keywords = array(
			'then',
			'after',
			'next',
			'finally',
			'first',
			'second',
			'step by step',
			'and then',
			'followed by',
			'subsequently',
		);

		$score = 0;
		foreach ( $multi_step_keywords as $keyword ) {
			if ( false !== strpos( $task_lower, $keyword ) ) {
				$score += 0.2;
			}
		}

		// Check for numbered steps.
		if ( preg_match( '/\d+[\.\)]\s/', $task ) ) {
			$score += 0.3;
		}

		// Check context for explicit multi-step flag.
		if ( isset( $context['multi_step'] ) && $context['multi_step'] ) {
			$score += 0.5;
		}

		return min( 1.0, $score );
	}

	/**
	 * Calculate logical complexity
	 *
	 * @param string $task Task description.
	 * @param array  $context Task context.
	 * @return float Score 0-1.
	 */
	protected function calculate_logical_complexity( $task, $context ) {
		$task_lower = strtolower( $task );
		$score      = 0;

		// Complex logical operators.
		$logical_keywords = array(
			'if',
			'unless',
			'because',
			'therefore',
			'however',
			'although',
			'whereas',
			'given that',
			'assuming',
		);

		foreach ( $logical_keywords as $keyword ) {
			if ( false !== strpos( $task_lower, $keyword ) ) {
				$score += 0.15;
			}
		}

		// Mathematical or analytical terms.
		$analytical_keywords = array(
			'calculate',
			'analyze',
			'compare',
			'evaluate',
			'assess',
			'determine',
			'prove',
			'verify',
		);

		foreach ( $analytical_keywords as $keyword ) {
			if ( false !== strpos( $task_lower, $keyword ) ) {
				$score += 0.2;
			}
		}

		// Check task length (longer tasks often more complex).
		$word_count = str_word_count( $task );
		if ( $word_count > 50 ) {
			$score += 0.2;
		} elseif ( $word_count > 30 ) {
			$score += 0.1;
		}

		return min( 1.0, $score );
	}

	/**
	 * Check if task involves code generation
	 *
	 * @param string $task Task description.
	 * @param array  $context Task context.
	 * @return float Score 0-1.
	 */
	protected function involves_code_generation( $task, $context ) {
		$task_lower = strtolower( $task );
		$score      = 0;

		// Code-related keywords.
		$code_keywords = array(
			'code',
			'function',
			'class',
			'method',
			'script',
			'program',
			'implement',
			'write code',
			'develop',
			'php',
			'javascript',
			'python',
			'css',
			'html',
		);

		foreach ( $code_keywords as $keyword ) {
			if ( false !== strpos( $task_lower, $keyword ) ) {
				$score += 0.2;
			}
		}

		// Check for code blocks in task.
		if ( preg_match( '/```|<code>/', $task ) ) {
			$score += 0.4;
		}

		// Check context for code generation flag.
		if ( isset( $context['task_type'] ) && 'code_generation' === $context['task_type'] ) {
			$score = 1.0;
		}

		// Check for line count requirement (indicates large code task).
		if ( preg_match( '/\d{2,}\s*lines/', $task_lower ) ) {
			$score += 0.3;
		}

		return min( 1.0, $score );
	}

	/**
	 * Check if task requires domain expertise
	 *
	 * @param string $task Task description.
	 * @param array  $context Task context.
	 * @return float Score 0-1.
	 */
	protected function requires_domain_expertise( $task, $context ) {
		$task_lower = strtolower( $task );
		$score      = 0;

		// Domain-specific terms.
		$domain_keywords = array(
			'technical',
			'specialized',
			'expert',
			'professional',
			'industry',
			'compliance',
			'regulatory',
			'best practice',
		);

		foreach ( $domain_keywords as $keyword ) {
			if ( false !== strpos( $task_lower, $keyword ) ) {
				$score += 0.2;
			}
		}

		// Check if profession is specified in context.
		if ( isset( $context['profession_slug'] ) ) {
			$score += 0.4;
		}

		// Check for domain-specific jargon (acronyms, technical terms).
		if ( preg_match( '/\b[A-Z]{3,}\b/', $task ) ) {
			$score += 0.2;
		}

		return min( 1.0, $score );
	}

	/**
	 * Check if task needs verification
	 *
	 * @param string $task Task description.
	 * @param array  $context Task context.
	 * @return float Score 0-1.
	 */
	protected function needs_verification( $task, $context ) {
		$task_lower = strtolower( $task );
		$score      = 0;

		// Verification keywords.
		$verification_keywords = array(
			'verify',
			'validate',
			'check',
			'confirm',
			'ensure',
			'test',
			'review',
			'double-check',
			'accuracy',
		);

		foreach ( $verification_keywords as $keyword ) {
			if ( false !== strpos( $task_lower, $keyword ) ) {
				$score += 0.25;
			}
		}

		// Critical or high-stakes tasks.
		$critical_keywords = array(
			'critical',
			'important',
			'security',
			'production',
			'live',
			'customer-facing',
			'compliance',
		);

		foreach ( $critical_keywords as $keyword ) {
			if ( false !== strpos( $task_lower, $keyword ) ) {
				$score += 0.3;
			}
		}

		return min( 1.0, $score );
	}

	/**
	 * Calculate reasoning score from indicators
	 *
	 * @param array $indicators Indicator scores.
	 * @return float Overall reasoning score 0-1.
	 */
	protected function calculate_reasoning_score( $indicators ) {
		$score = 0;

		foreach ( $indicators as $indicator => $value ) {
			$weight = $this->trigger_weights[ $indicator ] ?? 0;
			$score += $value * $weight;
		}

		return min( 1.0, $score );
	}

	/**
	 * Get reasoning-enhancing system prompt
	 *
	 * @param array $task_info Task information.
	 * @return string Reasoning prompt.
	 */
	protected function get_reasoning_prompt( $task_info ) {
		$prompt  = "Enhanced Reasoning Mode Activated:\n\n";
		$prompt .= "Please approach this task with careful, step-by-step reasoning:\n";
		$prompt .= "1. Break down the problem into clear steps\n";
		$prompt .= "2. State your assumptions explicitly\n";
		$prompt .= "3. Show your reasoning for each step\n";
		$prompt .= "4. Verify your conclusions\n";
		$prompt .= "5. Consider alternative approaches if needed\n\n";

		$task_type = $task_info['type'] ?? 'general';

		if ( 'code_generation' === $task_type ) {
			$prompt .= "For code generation:\n";
			$prompt .= "- Plan the structure before writing code\n";
			$prompt .= "- Consider edge cases and error handling\n";
			$prompt .= "- Follow best practices and coding standards\n";
			$prompt .= "- Add comments explaining complex logic\n\n";
		}

		return apply_filters( 'wp_mcp_ai_reasoning_prompt', $prompt, $task_info );
	}

	/**
	 * Evaluate coherence of reasoning output
	 *
	 * @param array $reasoning_output Reasoning output.
	 * @return float Coherence score 0-1.
	 */
	protected function evaluate_coherence( $reasoning_output ) {
		// Simple heuristic: check if steps are present and connected.
		if ( empty( $reasoning_output ) || ! is_array( $reasoning_output ) ) {
			return 0.5; // Neutral score if no structured output.
		}

		$score = 0.7; // Base score.

		// Check for step-by-step structure.
		if ( isset( $reasoning_output['steps'] ) && is_array( $reasoning_output['steps'] ) ) {
			$score += 0.2;
		}

		// Check for explanations.
		if ( isset( $reasoning_output['explanation'] ) ) {
			$score += 0.1;
		}

		return min( 1.0, $score );
	}

	/**
	 * Check logical consistency
	 *
	 * @param array $reasoning_output Reasoning output.
	 * @return float Consistency score 0-1.
	 */
	protected function check_logical_consistency( $reasoning_output ) {
		// Simple heuristic: assume consistent unless obvious contradictions.
		return 0.8; // Placeholder - real implementation would analyze for contradictions.
	}

	/**
	 * Check completeness
	 *
	 * @param array  $reasoning_output Reasoning output.
	 * @param string $task Original task.
	 * @return float Completeness score 0-1.
	 */
	protected function check_completeness( $reasoning_output, $task ) {
		// Simple heuristic: check if all parts of task appear addressed.
		if ( empty( $reasoning_output ) ) {
			return 0.3;
		}

		return 0.75; // Placeholder - real implementation would match task requirements.
	}

	/**
	 * Record reasoning decision
	 *
	 * @param string $task Task description.
	 * @param array  $indicators Indicator scores.
	 * @param float  $reasoning_score Overall score.
	 * @return void
	 */
	protected function record_reasoning_decision( $task, $indicators, $reasoning_score ) {
		$history = get_option( self::REASONING_HISTORY_KEY, array() );

		if ( ! is_array( $history ) ) {
			$history = array();
		}

		$history[] = array(
			'task_hash'       => md5( $task ),
			'indicators'      => $indicators,
			'reasoning_score' => $reasoning_score,
			'activated'       => $reasoning_score > self::REASONING_THRESHOLD,
			'timestamp'       => time(),
		);

		// Limit history size.
		if ( count( $history ) > self::HISTORY_LIMIT ) {
			$history = array_slice( $history, -self::HISTORY_LIMIT );
		}

		update_option( self::REASONING_HISTORY_KEY, $history, false );
	}

	/**
	 * Store quality metrics
	 *
	 * @param string $task Task description.
	 * @param array  $metrics Quality metrics.
	 * @return void
	 */
	protected function store_quality_metrics( $task, $metrics ) {
		$stored_metrics = get_option( self::QUALITY_METRICS_KEY, array() );

		if ( ! is_array( $stored_metrics ) ) {
			$stored_metrics = array();
		}

		$stored_metrics[] = array_merge(
			$metrics,
			array( 'task_hash' => md5( $task ) )
		);

		// Limit size.
		if ( count( $stored_metrics ) > self::HISTORY_LIMIT ) {
			$stored_metrics = array_slice( $stored_metrics, -self::HISTORY_LIMIT );
		}

		update_option( self::QUALITY_METRICS_KEY, $stored_metrics, false );
	}

	/**
	 * Get quality metrics history
	 *
	 * @param int $days Number of days to retrieve.
	 * @return array Quality metrics.
	 */
	protected function get_quality_metrics_history( $days ) {
		$metrics = get_option( self::QUALITY_METRICS_KEY, array() );

		if ( ! is_array( $metrics ) ) {
			return array();
		}

		$cutoff = time() - ( $days * DAY_IN_SECONDS );

		return array_filter(
			$metrics,
			function ( $m ) use ( $cutoff ) {
				return isset( $m['timestamp'] ) && $m['timestamp'] > $cutoff;
			}
		);
	}

	/**
	 * Clear reasoning history and metrics
	 *
	 * @return bool Success status.
	 */
	public function clear_history() {
		delete_option( self::REASONING_HISTORY_KEY );
		delete_option( self::QUALITY_METRICS_KEY );
		return true;
	}
}
