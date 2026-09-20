<?php
/**
 * Tool Payload Advisor — model-aware tool-cap recommendations.
 *
 * Stateless helper that translates a model's context window into a
 * recommended maximum number of tool definitions per chat payload.
 *
 * Rationale (see docs/project/proposals/tool-description-engineering-proposal.md):
 * industry guidance puts reliable tool selection at roughly 40 tools per
 * request for models around 128K context, while larger-context models can
 * carry more without degrading selection. The chat payload cap itself
 * (wp_mcp_ai_max_chat_tools, default 100) remains backward-compatible —
 * this advisor only lowers the effective cap when explicitly enabled via
 * the wp_mcp_ai_adaptive_tool_cap option (or its filter of the same name).
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Model-aware tool payload recommendations.
 *
 * Pure static helper: no instance state, no persistence of its own. The
 * opt-in flag is read from a single WordPress option that site owners can
 * toggle with wp-cli (`wp option update wp_mcp_ai_adaptive_tool_cap 1`)
 * or the wp_mcp_ai_adaptive_tool_cap filter.
 */
class WP_MCP_AI_Tool_Payload_Advisor {

	/**
	 * Recommended tool cap for models up to 128K context.
	 *
	 * Matches the industry ~40-tool rule of thumb (MCP Toolbox style guide).
	 */
	const SMALL_CONTEXT_CAP = 40;

	/**
	 * Recommended tool cap for models up to 256K context.
	 */
	const MEDIUM_CONTEXT_CAP = 64;

	/**
	 * Recommended tool cap for models beyond 256K context.
	 */
	const LARGE_CONTEXT_CAP = 100;

	/**
	 * Whether the adaptive (model-aware) tool cap is enabled.
	 *
	 * Resolution order:
	 *  1. Per-assistant override — an assistant config with
	 *     `adaptive_tool_cap` set to 'on' or 'off' wins outright
	 *     (stored on the Assistant edit screen).
	 *  2. Site default — the wp_mcp_ai_adaptive_tool_cap option
	 *     (default off, so existing sites keep today's behavior).
	 *
	 * The filter allows code-level control for both layers.
	 *
	 * @since 1.1.83
	 *
	 * @param array $assistant_config Optional assistant configuration
	 *                                (as returned by WP_MCP_AI_Assistant_CPT::get_assistant_configuration()).
	 * @return bool True when the model-aware cap should be applied.
	 */
	public static function is_adaptive_cap_enabled( $assistant_config = array() ) {
		$assistant_value = ( is_array( $assistant_config ) && isset( $assistant_config['adaptive_tool_cap'] ) && is_string( $assistant_config['adaptive_tool_cap'] ) )
			? strtolower( trim( $assistant_config['adaptive_tool_cap'] ) )
			: '';

		if ( 'on' === $assistant_value ) {
			return true;
		}

		if ( 'off' === $assistant_value ) {
			return false;
		}

		/**
		 * Filter whether the model-aware tool payload cap is applied.
		 *
		 * Only consulted when the assistant has no explicit override.
		 *
		 * @since 1.1.83
		 *
		 * @param bool $enabled Default: option wp_mcp_ai_adaptive_tool_cap.
		 */
		return (bool) apply_filters(
			'wp_mcp_ai_adaptive_tool_cap',
			(bool) get_option( 'wp_mcp_ai_adaptive_tool_cap', false )
		);
	}

	/**
	 * Recommend a maximum tool count for a given model.
	 *
	 * Uses the shared context-window catalog (CCT-first, bundled fallback)
	 * from WP_MCP_AI_Token_Budget_Manager so recommendations stay in sync
	 * with the pre-flight context validation used by the provider clients.
	 *
	 * @since 1.1.83
	 *
	 * @param string $model Model slug (e.g. 'gpt-4o', 'claude-sonnet-4-5'). Empty string = unknown.
	 * @return int Recommended cap, or 0 when the model is unknown (keep the configured cap).
	 */
	public static function recommended_cap_for_model( $model = '' ) {
		$model         = is_string( $model ) ? trim( $model ) : '';
		$context_limit = 0;

		if ( '' !== $model && class_exists( 'WP_MCP_AI_Token_Budget_Manager' ) ) {
			$context_limit = (int) WP_MCP_AI_Token_Budget_Manager::get_model_limit( $model );
		}

		if ( $context_limit <= 0 ) {
			return 0;
		}

		if ( $context_limit <= 128000 ) {
			return self::SMALL_CONTEXT_CAP;
		}

		if ( $context_limit <= 256000 ) {
			return self::MEDIUM_CONTEXT_CAP;
		}

		return self::LARGE_CONTEXT_CAP;
	}

	/**
	 * Build a human-readable recommendation summary for admin surfaces.
	 *
	 * @since 1.1.83
	 *
	 * @param string $model            Model slug. Empty string = unknown model.
	 * @param array  $assistant_config Optional assistant configuration (per-assistant override).
	 * @return array{enabled: bool, model: string, context_limit: int, recommended_cap: int, effective_cap: int}
	 */
	public static function get_recommendation_summary( $model = '', $assistant_config = array() ) {
		$model         = is_string( $model ) ? trim( $model ) : '';
		$context_limit = 0;

		if ( '' !== $model && class_exists( 'WP_MCP_AI_Token_Budget_Manager' ) ) {
			$context_limit = (int) WP_MCP_AI_Token_Budget_Manager::get_model_limit( $model );
		}

		$recommended = self::recommended_cap_for_model( $model );
		$configured  = (int) apply_filters( 'wp_mcp_ai_max_chat_tools', 100 );
		$configured  = max( 1, min( 128, $configured ) );
		$enabled     = self::is_adaptive_cap_enabled( $assistant_config );

		return array(
			'enabled'         => $enabled,
			'model'           => $model,
			'context_limit'   => $context_limit,
			'recommended_cap' => $recommended,
			'effective_cap'   => ( $enabled && $recommended > 0 ) ? min( $configured, $recommended ) : $configured,
		);
	}
}
