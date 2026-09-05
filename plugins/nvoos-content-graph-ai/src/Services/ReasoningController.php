<?php
/**
 * Reasoning Controller service (D8 Cluster 2c port of the base plugin's
 * WP_MCP_AI_Reasoning_Controller — byte-identical thresholds, weights,
 * keyword heuristics, filter names, and history option key).
 *
 * The base class is 20 methods; this port carries the surface the
 * enable_reasoning_mode tool consumes plus the decision-history
 * recording (quality tracking / statistics stay base-only).
 *
 * @package NvoosContentGraphAi\Services
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Services;

/**
 * Detects whether a task benefits from enhanced reasoning and builds
 * the enhanced model configuration.
 */
class ReasoningController {

	/**
	 * Storage keys (byte-identical to the base).
	 */
	const QUALITY_METRICS_KEY   = 'wp_mcp_ai_reasoning_quality_metrics';
	const REASONING_HISTORY_KEY = 'wp_mcp_ai_reasoning_history';

	/**
	 * Score needed to activate reasoning mode (byte-identical).
	 */
	const REASONING_THRESHOLD = 0.7;

	/**
	 * Max reasoning tasks to track (byte-identical).
	 */
	const HISTORY_LIMIT = 500;

	/**
	 * Reasoning mode triggers and weights (byte-identical).
	 *
	 * @var array<string,float>
	 */
	protected $trigger_weights = array(
		'multi_step'          => 0.3,
		'logical_complexity'  => 0.25,
		'code_generation'     => 0.2,
		'domain_expertise'    => 0.15,
		'verification_needed' => 0.1,
	);

	/**
	 * Detect if the task requires enhanced reasoning.
	 *
	 * @param string $task    Task description.
	 * @param array  $context Task context.
	 * @return bool
	 */
	public function requires_enhanced_reasoning( $task, $context ) {
		$indicators = array(
			'multi_step'          => $this->is_multi_step_task( $task, $context ),
			'logical_complexity'  => $this->calculate_logical_complexity( $task, $context ),
			'code_generation'     => $this->involves_code_generation( $task, $context ),
			'domain_expertise'    => $this->requires_domain_expertise( $task, $context ),
			'verification_needed' => $this->needs_verification( $task, $context ),
		);

		$reasoning_score = $this->calculate_reasoning_score( $indicators );

		$this->record_reasoning_decision( $task, $indicators, $reasoning_score );

		return $reasoning_score > self::REASONING_THRESHOLD;
	}

	/**
	 * Enhance a model configuration with reasoning settings.
	 *
	 * @param array $model_config Base model configuration.
	 * @param array $task_info    Task information.
	 * @return array
	 */
	public function activate_reasoning_mode( $model_config, $task_info = array() ) {
		$enhanced_config = $model_config;

		$reasoning_prompt = $this->get_reasoning_prompt( $task_info );
		if ( isset( $enhanced_config['system_prompt'] ) ) {
			$enhanced_config['system_prompt'] .= "\n\n" . $reasoning_prompt;
		} else {
			$enhanced_config['system_prompt'] = $reasoning_prompt;
		}

		if ( ! isset( $enhanced_config['temperature'] ) || $enhanced_config['temperature'] > 0.5 ) {
			$enhanced_config['temperature'] = 0.3;
		}

		$enhanced_config['reasoning_enabled']  = true;
		$enhanced_config['reasoning_type']     = 'chain_of_thought';
		$enhanced_config['verify_steps']       = true;
		$enhanced_config['reasoning_metadata'] = array(
			'activated_at' => current_time( 'mysql' ),
			'task_type'    => $task_info['type'] ?? 'general',
			'complexity'   => $task_info['complexity'] ?? 'unknown',
		);

		return apply_filters( 'wp_mcp_ai_reasoning_config', $enhanced_config, $task_info );
	}

	/**
	 * Score multi-step characteristics (byte-identical heuristics).
	 *
	 * @param string $task    Task description.
	 * @param array  $context Task context.
	 * @return float
	 */
	protected function is_multi_step_task( $task, $context ) {
		$task_lower = strtolower( $task );

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

		if ( preg_match( '/\d+[\.\)]\s/', $task ) ) {
			$score += 0.3;
		}

		if ( isset( $context['multi_step'] ) && $context['multi_step'] ) {
			$score += 0.5;
		}

		return min( 1.0, $score );
	}

	/**
	 * Score logical complexity (byte-identical heuristics).
	 *
	 * @param string $task    Task description.
	 * @param array  $context Task context.
	 * @return float
	 */
	protected function calculate_logical_complexity( $task, $context ) {
		unset( $context ); // Reserved for future implementation.

		$task_lower = strtolower( $task );
		$score      = 0;

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

		$word_count = str_word_count( $task );
		if ( $word_count > 50 ) {
			$score += 0.2;
		} elseif ( $word_count > 30 ) {
			$score += 0.1;
		}

		return min( 1.0, $score );
	}

	/**
	 * Score code-generation characteristics (byte-identical heuristics).
	 *
	 * @param string $task    Task description.
	 * @param array  $context Task context.
	 * @return float
	 */
	protected function involves_code_generation( $task, $context ) {
		$task_lower = strtolower( $task );
		$score      = 0;

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

		if ( preg_match( '/```|<code>/', $task ) ) {
			$score += 0.4;
		}

		if ( isset( $context['task_type'] ) && 'code_generation' === $context['task_type'] ) {
			$score = 1.0;
		}

		if ( preg_match( '/\d{2,}\s*lines/', $task_lower ) ) {
			$score += 0.3;
		}

		return min( 1.0, $score );
	}

	/**
	 * Score domain-expertise characteristics (byte-identical heuristics).
	 *
	 * @param string $task    Task description.
	 * @param array  $context Task context.
	 * @return float
	 */
	protected function requires_domain_expertise( $task, $context ) {
		$task_lower = strtolower( $task );
		$score      = 0;

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

		if ( isset( $context['profession_slug'] ) ) {
			$score += 0.4;
		}

		if ( preg_match( '/\b[A-Z]{3,}\b/', $task ) ) {
			$score += 0.2;
		}

		return min( 1.0, $score );
	}

	/**
	 * Score verification characteristics (byte-identical heuristics).
	 *
	 * @param string $task    Task description.
	 * @param array  $context Task context.
	 * @return float
	 */
	protected function needs_verification( $task, $context ) {
		unset( $context ); // Reserved for future implementation.

		$task_lower = strtolower( $task );
		$score      = 0;

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
	 * Calculate the weighted reasoning score (byte-identical).
	 *
	 * @param array $indicators Indicator scores.
	 * @return float
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
	 * Build the reasoning-enhancing system prompt (byte-identical).
	 *
	 * @param array $task_info Task information.
	 * @return string
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
	 * Record the reasoning decision in the shared history option
	 * (byte-identical option key and shape).
	 *
	 * @param string $task            Task description.
	 * @param array  $indicators      Indicator scores.
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

		if ( count( $history ) > self::HISTORY_LIMIT ) {
			$history = array_slice( $history, -self::HISTORY_LIMIT );
		}

		update_option( self::REASONING_HISTORY_KEY, $history, false );
	}
}
