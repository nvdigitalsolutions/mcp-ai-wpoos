<?php
/**
 * Context Slash Command
 *
 * Shows the current context budget status — token count, message count,
 * and how much capacity remains. Gives users visibility into context
 * pressure before it becomes a problem.
 *
 * Inspired by the concept that proactive context awareness is better
 * than hitting limits unexpectedly.
 *
 * @package WP_MCP_AI
 * @subpackage Slash_Commands
 * @since 1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SlashCommands\Commands;

use NvoosContentGraphAiPlatform\SlashCommands\SlashCommandHandler;
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Context Command Class
 *
 * Reports on the current conversation's context budget: token usage,
 * message count, model limits, and compaction recommendations.
 *
 * @since 1.5.0
 */
class SlashCommandContext {
	/**
	 * Log an event through the base plugin's logger when present
	 * (monolith mode), falling back to error_log in standalone mode.
	 *
	 * @since 2.0.0
	 *
	 * @param string $level   Log level.
	 * @param string $message Log message.
	 */
	private static function log_event( $level, $message ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $level, $message );
			return;
		}
		error_log( sprintf( '[nvoos-content-graph-ai-platform] %s: %s', $level, $message ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Standalone fallback when the base logger is absent.
	}

	/**
	 * Approximate characters per token for estimation.
	 *
	 * @var int
	 */
	const CHARS_PER_TOKEN = 4;

	/**
	 * Default max tokens when model limit is unknown.
	 *
	 * @var int
	 */
	const DEFAULT_MAX_TOKENS = 128000;

	/**
	 * Threshold percentage for warning.
	 *
	 * @var float
	 */
	const WARNING_THRESHOLD = 0.7;

	/**
	 * Threshold percentage for critical.
	 *
	 * @var float
	 */
	const CRITICAL_THRESHOLD = 0.9;

	/**
	 * Execute context command
	 *
	 * @param array $args    Positional arguments.
	 * @param array $flags   Command flags.
	 * @param array $context Execution context.
	 * @return array|string Context report.
	 */
	public function execute( $args, $flags, $context ) {
		$messages     = isset( $context['messages'] ) ? $context['messages'] : array();
		$assistant_id = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;
		$model        = isset( $context['model'] ) ? sanitize_text_field( $context['model'] ) : '';
		$verbose      = isset( $flags['verbose'] ) || isset( $flags['v'] );

		// Count messages by role.
		$role_counts = array(
			'system'    => 0,
			'user'      => 0,
			'assistant' => 0,
			'tool'      => 0,
		);

		$total_chars       = 0;
		$tool_chars        = 0;
		$tool_call_count   = 0;
		$system_prompt_len = 0;

		foreach ( $messages as $msg ) {
			$role = isset( $msg['role'] ) ? $msg['role'] : 'unknown';
			if ( isset( $role_counts[ $role ] ) ) {
				++$role_counts[ $role ];
			}

			$content_len = 0;
			if ( isset( $msg['content'] ) ) {
				$content_len = strlen( is_string( $msg['content'] ) ? $msg['content'] : wp_json_encode( $msg['content'] ) );
			}

			$total_chars += $content_len;

			if ( 'tool' === $role ) {
				$tool_chars += $content_len;
			}

			if ( 'system' === $role ) {
				$system_prompt_len += $content_len;
			}

			if ( isset( $msg['tool_calls'] ) ) {
				$tc_len           = strlen( wp_json_encode( $msg['tool_calls'] ) );
				$total_chars     += $tc_len;
				$tool_chars      += $tc_len;
				$tool_call_count += count( $msg['tool_calls'] );
			}
		}

		// Estimate tokens.
		$estimated_tokens = intval( $total_chars / self::CHARS_PER_TOKEN );
		$tool_tokens      = intval( $tool_chars / self::CHARS_PER_TOKEN );
		$system_tokens    = intval( $system_prompt_len / self::CHARS_PER_TOKEN );

		// Get model limit.
		$max_tokens = $this->get_model_limit( $model );

		/**
		 * Filters the token limit used for context budget display.
		 *
		 * @since 1.5.0
		 *
		 * @param int    $max_tokens   Maximum token limit.
		 * @param int    $assistant_id Assistant post ID.
		 * @param string $model        Model name.
		 */
		$max_tokens = (int) apply_filters( 'wp_mcp_ai_chat_request_token_limit', $max_tokens, $assistant_id, $model );

		// Calculate usage percentage.
		$usage_pct = $max_tokens > 0 ? ( $estimated_tokens / $max_tokens ) : 0;

		// Determine status.
		if ( $usage_pct >= self::CRITICAL_THRESHOLD ) {
			$status      = '🔴';
			$status_text = __( 'CRITICAL', 'nvoos-content-graph-ai-platform' );
		} elseif ( $usage_pct >= self::WARNING_THRESHOLD ) {
			$status      = '🟡';
			$status_text = __( 'WARNING', 'nvoos-content-graph-ai-platform' );
		} else {
			$status      = '🟢';
			$status_text = __( 'OK', 'nvoos-content-graph-ai-platform' );
		}

		$message_count = count( $messages );

		// Build report.
		$report  = "## Context Budget Report\n\n";
		$report .= sprintf(
			"**Status:** %s %s (%.0f%% used)\n\n",
			$status,
			$status_text,
			$usage_pct * 100
		);

		$report .= "| Metric | Value |\n";
		$report .= "|--------|-------|\n";
		$report .= sprintf(
			"| Messages | %s |\n",
			number_format_i18n( $message_count )
		);
		$report .= sprintf(
			"| Est. tokens | ~%s / %s |\n",
			number_format_i18n( $estimated_tokens ),
			number_format_i18n( $max_tokens )
		);
		$report .= sprintf(
			"| Remaining | ~%s tokens |\n",
			number_format_i18n( max( 0, $max_tokens - $estimated_tokens ) )
		);

		if ( ! empty( $model ) ) {
			$report .= sprintf( "| Model | %s |\n", esc_html( $model ) );
		}

		$report .= "\n";

		// Message breakdown.
		$report .= "**Message Breakdown:**\n";
		$report .= sprintf(
			"- User: %d | Assistant: %d | Tool: %d | System: %d\n",
			$role_counts['user'],
			$role_counts['assistant'],
			$role_counts['tool'],
			$role_counts['system']
		);

		if ( $tool_call_count > 0 ) {
			$report .= sprintf(
				/* translators: 1: tool call count, 2: tool token count */
				__( "- Tool calls: %1\$d (consuming ~%2\$s tokens)\n", 'nvoos-content-graph-ai-platform' ),
				$tool_call_count,
				number_format_i18n( $tool_tokens )
			);
		}

		// Recommendations.
		if ( $usage_pct >= self::CRITICAL_THRESHOLD ) {
			$report .= "\n**⚠️ Recommendation:** Context is nearly full. Run `/compact --strategy=full` immediately to free space.\n";
		} elseif ( $usage_pct >= self::WARNING_THRESHOLD ) {
			$report .= "\n**💡 Recommendation:** Context is filling up. Consider running `/compact` to summarize older messages.\n";

			// Suggest trim-tools if tool tokens are dominant.
			if ( $tool_tokens > $estimated_tokens * 0.5 ) {
				$report .= sprintf(
					/* translators: %d: percentage of tokens consumed by tools */
					__( "Tool results consume %d%% of context. Try `/compact --strategy=trim-tools` to reclaim space.\n", 'nvoos-content-graph-ai-platform' ),
					intval( ( $tool_tokens / max( 1, $estimated_tokens ) ) * 100 )
				);
			}
		}

		if ( $verbose ) {
			$report .= "\n**Detailed Breakdown:**\n";
			$report .= sprintf(
				"- Total characters: %s\n",
				number_format_i18n( $total_chars )
			);
			$report .= sprintf(
				"- System prompt: ~%s tokens\n",
				number_format_i18n( $system_tokens )
			);
			$report .= sprintf(
				"- Tool content: ~%s tokens (%.0f%%)\n",
				number_format_i18n( $tool_tokens ),
				$estimated_tokens > 0 ? ( $tool_tokens / $estimated_tokens ) * 100 : 0
			);
			$report .= sprintf(
				"- Conversation: ~%s tokens (%.0f%%)\n",
				number_format_i18n( $estimated_tokens - $tool_tokens - $system_tokens ),
				$estimated_tokens > 0 ? ( ( $estimated_tokens - $tool_tokens - $system_tokens ) / $estimated_tokens ) * 100 : 0
			);
		}

		return array(
			'success' => true,
			'message' => $report,
			'data'    => array(
				'status'           => $status_text,
				'usage_percentage' => round( $usage_pct * 100, 1 ),
				'estimated_tokens' => $estimated_tokens,
				'max_tokens'       => $max_tokens,
				'remaining_tokens' => max( 0, $max_tokens - $estimated_tokens ),
				'message_count'    => $message_count,
				'role_counts'      => $role_counts,
				'tool_tokens'      => $tool_tokens,
				'tool_call_count'  => $tool_call_count,
				'model'            => $model,
			),
		);
	}

	/**
	 * Get model token limit.
	 *
	 * @param string $model Model name.
	 * @return int Token limit.
	 */
	private function get_model_limit( $model ) {
		// Check if the Token Budget Manager is available for accurate limits.
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Token_Budget_Manager' ) ) {
			$limit = \WP_MCP_AI_Token_Budget_Manager::get_model_limit( $model );
			if ( $limit > 0 ) {
				return $limit;
			}
		}

		// Fallback known limits.
		// Note: the base copy carries duplicate keys here ('gpt-4.1' twice,
		// 'gemini-2.5-flash' three times); PHP keeps the LAST value, so this
		// deduped map is behaviour-identical (gpt-4.1 → 1M, gemini-2.5-flash
		// → 2M).
		$known_limits = array(
			'gpt-4o-mini'       => 128000,
			'gpt-4.1'           => 1000000,
			'gpt-4.1-mini'      => 1000000,
			'gpt-4.1-nano'      => 1000000,
			'gpt-4-turbo'       => 128000,
			'gpt-3.5-turbo'     => 16385,
			'gemini-2.5-flash'  => 2097152,
			'claude-3.5-sonnet' => 200000,
			'claude-3-opus'     => 200000,
		);

		if ( ! empty( $model ) ) {
			foreach ( $known_limits as $model_key => $limit ) {
				if ( false !== strpos( $model, $model_key ) ) {
					return $limit;
				}
			}
		}

		return self::DEFAULT_MAX_TOKENS;
	}
}
