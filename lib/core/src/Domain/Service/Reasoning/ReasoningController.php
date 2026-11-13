<?php
/**
 * Reasoning controller — scores task complexity and enhances a model config.
 *
 * Ported from `WP_MCP_AI_Reasoning_Controller` under Proposal 090-D cluster 1.
 * Two entry points matter to the tool:
 *
 *  - `requiresEnhancedReasoning()` scores five indicators (multi-step, logical
 *    complexity, code generation, domain expertise, verification) and compares
 *    the weighted total against a 0.7 threshold;
 *  - `activateReasoningMode()` merges chain-of-thought prompting, a lowered
 *    temperature and a verification flag into a model configuration.
 *
 * ## What the port drops, and why
 *
 * The WordPress controller also appends every decision to a
 * `wp_mcp_ai_reasoning_history` option for a learning loop. Nothing reads that
 * history except the controller's own statistics methods, no tool exposes it,
 * and it never affects a response — so the port omits it rather than inventing
 * a storage contract with no consumer. The WordPress controller keeps writing
 * it.
 *
 * The two WordPress filters (`wp_mcp_ai_reasoning_config`,
 * `wp_mcp_ai_reasoning_prompt`) and `current_time()` are platform concerns:
 * the port has no hook seam and reports UTC.
 *
 * @package Nvoos\Core
 * @since   1.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Service\Reasoning;

final class ReasoningController {

	/**
	 * Score needed to activate reasoning mode.
	 */
	public const REASONING_THRESHOLD = 0.7;

	/**
	 * Optional clock, used by tests to pin `activated_at`.
	 *
	 * @var \Closure(): string|null
	 */
	private readonly ?\Closure $clock;

	/**
	 * Indicator weights.
	 *
	 * @var array<string, float>
	 */
	private array $triggerWeights = array(
		'multi_step'          => 0.3,
		'logical_complexity'  => 0.25,
		'code_generation'     => 0.2,
		'domain_expertise'    => 0.15,
		'verification_needed' => 0.1,
	);

	/**
	 * @param \Closure(): string|null $clock Returns a `Y-m-d H:i:s` timestamp.
	 */
	public function __construct( ?\Closure $clock = null ) {
		$this->clock = $clock;
	}

	/**
	 * Should this task run with enhanced reasoning?
	 *
	 * @param string               $task    Task description.
	 * @param array<string, mixed> $context Task context.
	 */
	public function requiresEnhancedReasoning( string $task, array $context = array() ): bool {
		return $this->scoreTask( $task, $context ) > self::REASONING_THRESHOLD;
	}

	/**
	 * Indicator scores behind the decision.
	 *
	 * @param string               $task    Task description.
	 * @param array<string, mixed> $context Task context.
	 * @return array<string, float>
	 */
	public function indicators( string $task, array $context = array() ): array {
		return array(
			'multi_step'          => $this->isMultiStepTask( $task, $context ),
			'logical_complexity'  => $this->calculateLogicalComplexity( $task, $context ),
			'code_generation'     => $this->involvesCodeGeneration( $task, $context ),
			'domain_expertise'    => $this->requiresDomainExpertise( $task, $context ),
			'verification_needed' => $this->needsVerification( $task, $context ),
		);
	}

	/**
	 * Weighted score for a task, 0–1.
	 *
	 * @param string               $task    Task description.
	 * @param array<string, mixed> $context Task context.
	 */
	public function scoreTask( string $task, array $context = array() ): float {
		return $this->calculateReasoningScore( $this->indicators( $task, $context ) );
	}

	/**
	 * Merge reasoning enhancements into a model configuration.
	 *
	 * @param array<string, mixed> $modelConfig Base configuration.
	 * @param array<string, mixed> $taskInfo    Task information (`type`, `complexity`).
	 * @return array<string, mixed> Enhanced configuration.
	 */
	public function activateReasoningMode( array $modelConfig, array $taskInfo = array() ): array {
		$enhanced = $modelConfig;

		$prompt = $this->reasoningPrompt( $taskInfo );

		if ( isset( $enhanced['system_prompt'] ) ) {
			$enhanced['system_prompt'] .= "\n\n" . $prompt;
		} else {
			$enhanced['system_prompt'] = $prompt;
		}

		// Lowered temperature only when unset or still too warm.
		if ( ! isset( $enhanced['temperature'] ) || $enhanced['temperature'] > 0.5 ) {
			$enhanced['temperature'] = 0.3;
		}

		$enhanced['reasoning_enabled'] = true;
		$enhanced['reasoning_type']    = 'chain_of_thought';
		$enhanced['verify_steps']      = true;

		$enhanced['reasoning_metadata'] = array(
			'activated_at' => $this->now(),
			'task_type'    => $taskInfo['type'] ?? 'general',
			'complexity'   => $taskInfo['complexity'] ?? 'unknown',
		);

		return $enhanced;
	}

	/**
	 * The reasoning-enhancing system prompt.
	 *
	 * @param array<string, mixed> $taskInfo Task information.
	 */
	public function reasoningPrompt( array $taskInfo = array() ): string {
		$prompt  = "Enhanced Reasoning Mode Activated:\n\n";
		$prompt .= "Please approach this task with careful, step-by-step reasoning:\n";
		$prompt .= "1. Break down the problem into clear steps\n";
		$prompt .= "2. State your assumptions explicitly\n";
		$prompt .= "3. Show your reasoning for each step\n";
		$prompt .= "4. Verify your conclusions\n";
		$prompt .= "5. Consider alternative approaches if needed\n\n";

		if ( 'code_generation' === ( $taskInfo['type'] ?? 'general' ) ) {
			$prompt .= "For code generation:\n";
			$prompt .= "- Plan the structure before writing code\n";
			$prompt .= "- Consider edge cases and error handling\n";
			$prompt .= "- Follow best practices and coding standards\n";
			$prompt .= "- Add comments explaining complex logic\n\n";
		}

		return $prompt;
	}

	/**
	 * Current timestamp, `Y-m-d H:i:s`.
	 *
	 * WordPress reports site-local time; the port reports UTC.
	 */
	private function now(): string {
		if ( null !== $this->clock ) {
			return ( $this->clock )();
		}

		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * @param array<string, mixed> $context Task context.
	 */
	private function isMultiStepTask( string $task, array $context ): float {
		$taskLower = strtolower( $task );

		$keywords = array(
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

		$score = 0.0;

		foreach ( $keywords as $keyword ) {
			if ( false !== strpos( $taskLower, $keyword ) ) {
				$score += 0.2;
			}
		}

		if ( preg_match( '/\d+[\.\)]\s/', $task ) ) {
			$score += 0.3;
		}

		if ( ! empty( $context['multi_step'] ) ) {
			$score += 0.5;
		}

		return min( 1.0, $score );
	}

	/**
	 * @param array<string, mixed> $context Task context.
	 */
	private function calculateLogicalComplexity( string $task, array $context ): float {
		unset( $context );

		$taskLower = strtolower( $task );
		$score     = 0.0;

		$logical = array(
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

		foreach ( $logical as $keyword ) {
			if ( false !== strpos( $taskLower, $keyword ) ) {
				$score += 0.15;
			}
		}

		$analytical = array(
			'calculate',
			'analyze',
			'compare',
			'evaluate',
			'assess',
			'determine',
			'prove',
			'verify',
		);

		foreach ( $analytical as $keyword ) {
			if ( false !== strpos( $taskLower, $keyword ) ) {
				$score += 0.2;
			}
		}

		$wordCount = str_word_count( $task );

		if ( $wordCount > 50 ) {
			$score += 0.2;
		} elseif ( $wordCount > 30 ) {
			$score += 0.1;
		}

		return min( 1.0, $score );
	}

	/**
	 * @param array<string, mixed> $context Task context.
	 */
	private function involvesCodeGeneration( string $task, array $context ): float {
		$taskLower = strtolower( $task );
		$score     = 0.0;

		$keywords = array(
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

		foreach ( $keywords as $keyword ) {
			if ( false !== strpos( $taskLower, $keyword ) ) {
				$score += 0.2;
			}
		}

		if ( preg_match( '/```|<code>/', $task ) ) {
			$score += 0.4;
		}

		if ( isset( $context['task_type'] ) && 'code_generation' === $context['task_type'] ) {
			$score = 1.0;
		}

		if ( preg_match( '/\d{2,}\s*lines/', $taskLower ) ) {
			$score += 0.3;
		}

		return min( 1.0, $score );
	}

	/**
	 * @param array<string, mixed> $context Task context.
	 */
	private function requiresDomainExpertise( string $task, array $context ): float {
		$taskLower = strtolower( $task );
		$score     = 0.0;

		$keywords = array(
			'technical',
			'specialized',
			'expert',
			'professional',
			'industry',
			'compliance',
			'regulatory',
			'best practice',
		);

		foreach ( $keywords as $keyword ) {
			if ( false !== strpos( $taskLower, $keyword ) ) {
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
	 * @param array<string, mixed> $context Task context.
	 */
	private function needsVerification( string $task, array $context ): float {
		unset( $context );

		$taskLower = strtolower( $task );
		$score     = 0.0;

		$verification = array(
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

		foreach ( $verification as $keyword ) {
			if ( false !== strpos( $taskLower, $keyword ) ) {
				$score += 0.25;
			}
		}

		$critical = array(
			'critical',
			'important',
			'security',
			'production',
			'live',
			'customer-facing',
			'compliance',
		);

		foreach ( $critical as $keyword ) {
			if ( false !== strpos( $taskLower, $keyword ) ) {
				$score += 0.3;
			}
		}

		return min( 1.0, $score );
	}

	/**
	 * @param array<string, float> $indicators Indicator scores.
	 */
	private function calculateReasoningScore( array $indicators ): float {
		$score = 0.0;

		foreach ( $indicators as $indicator => $value ) {
			$score += $value * ( $this->triggerWeights[ $indicator ] ?? 0 );
		}

		return min( 1.0, $score );
	}
}
