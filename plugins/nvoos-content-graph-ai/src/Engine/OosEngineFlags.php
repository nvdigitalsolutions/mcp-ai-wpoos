<?php
/**
 * OOS engine flags (Wave E6, sub-cluster 1).
 *
 * Aligned port of the base plugin's OOS gate helpers from
 * `includes/bootstrap/oos-bridge.php`: byte-identical option keys
 * (`enable_oos_shadow`, `oos_shadow_sample_rate`, `enable_oos_engine`,
 * `enable_oos_session_log`, `enable_oos_session_telemetry`),
 * the `wp_mcp_ai_oos_shadow_enabled` / `wp_mcp_ai_oos_shadow_sample_rate`
 * / `wp_mcp_ai_oos_shadow_timeout_seconds` / `wp_mcp_ai_enable_session_log`
 * / `wp_mcp_ai_enable_session_telemetry` / `wp_mcp_ai_oos_canary` filters,
 * the `WP_MCP_AI_OOS_ENGINE` constant, the `X-WP-MCP-AI-Engine: oos` header
 * and `?engine=oos` query probes, the write-class classifier
 * (`ToolWriteClassInterface` capability-flag semantics; capability
 * heuristic fails safe), and the canary `_wp_mcp_ai_engine` post-meta
 * probe.
 *
 * Documented deviations:
 *  - Class name/namespace — the AI addon's PSR-4 tree (decision D4:
 *    engine pieces fold into `nvoos-content-graph-ai`).
 *  - Global functions → public static methods. Each method defers to the
 *    base's global function when it exists (monolith installs load
 *    `oos-bridge.php`), so monolith behavior is byte-identical; the
 *    static body is the standalone fallback.
 *  - Text domain `nvoos-content-graph-ai`.
 *
 * @since 1.1.0
 * @package NvoosContentGraphAi\Engine
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Engine;

/**
 * Resolves the OOS shadow / engine gates for the current install mode.
 *
 * @since 1.1.0
 */
final class OosEngineFlags {

	/**
	 * Whether OOS shadow mode (Proposal 029, Phase 4.1) is enabled.
	 *
	 * Shadow mode runs the OOS engine in parallel on sampled legacy-path
	 * requests and serves the legacy result — zero user exposure. Off by
	 * default; enable via the admin setting enable_oos_shadow or the
	 * wp_mcp_ai_oos_shadow_enabled filter.
	 *
	 * @return bool
	 */
	public static function shadow_enabled(): bool {
		if ( \function_exists( 'wp_mcp_ai_oos_shadow_enabled' ) ) {
			return \wp_mcp_ai_oos_shadow_enabled();
		}

		$settings = \get_option( 'wp_mcp_ai_settings', array() );
		if ( ! empty( $settings['enable_oos_shadow'] ) ) {
			return true;
		}

		return (bool) \apply_filters( 'wp_mcp_ai_oos_shadow_enabled', false );
	}

	/**
	 * Shadow sampling rate (0.0–1.0).
	 *
	 * @return float
	 */
	public static function shadow_sample_rate(): float {
		if ( \function_exists( 'wp_mcp_ai_oos_shadow_sample_rate' ) ) {
			return \wp_mcp_ai_oos_shadow_sample_rate();
		}

		$settings = \get_option( 'wp_mcp_ai_settings', array() );
		$rate     = isset( $settings['oos_shadow_sample_rate'] ) ? (float) $settings['oos_shadow_sample_rate'] : 0.05;

		return (float) \apply_filters( 'wp_mcp_ai_oos_shadow_sample_rate', \max( 0.0, \min( 1.0, $rate ) ) );
	}

	/**
	 * Shadow-run deadline in seconds (same-request execution is bounded).
	 *
	 * @return int
	 */
	public static function shadow_timeout_seconds(): int {
		if ( \function_exists( 'wp_mcp_ai_oos_shadow_timeout_seconds' ) ) {
			return \wp_mcp_ai_oos_shadow_timeout_seconds();
		}

		return (int) \apply_filters( 'wp_mcp_ai_oos_shadow_timeout_seconds', 30 );
	}

	/**
	 * Determine whether the OOS engine should handle the current request.
	 *
	 * Checks in order:
	 *  1. the `enable_oos_engine` admin setting
	 *  2. the `WP_MCP_AI_OOS_ENGINE` constant
	 *  3. the `X-WP-MCP-AI-Engine: oos` header
	 *  4. the `?engine=oos` query parameter
	 *
	 * @return bool
	 */
	public static function engine_enabled(): bool {
		if ( \function_exists( 'wp_mcp_ai_oos_engine_enabled' ) ) {
			return \wp_mcp_ai_oos_engine_enabled();
		}

		// Check the admin setting first (Chat Client > Behavior > OOS Engine).
		$settings = \get_option( 'wp_mcp_ai_settings', array() );
		if ( ! empty( $settings['enable_oos_engine'] ) ) {
			return true;
		}

		if ( defined( 'WP_MCP_AI_OOS_ENGINE' ) && WP_MCP_AI_OOS_ENGINE ) {
			return true;
		}

		if ( isset( $_SERVER['HTTP_X_WP_MCP_AI_ENGINE'] )
			&& 'oos' === $_SERVER['HTTP_X_WP_MCP_AI_ENGINE']
		) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Feature-flag probe; no state change.
		if ( isset( $_GET['engine'] ) && 'oos' === $_GET['engine'] ) {
			return true;
		}

		return false;
	}

	/**
	 * Classify an OOS tool as write-class for shadow suppression.
	 *
	 * @param \Nvoos\Core\Domain\Contract\ToolInterface $tool Resolved OOS tool.
	 * @return bool True when the tool mutates state.
	 */
	public static function tool_is_write_class( $tool ): bool {
		if ( $tool instanceof \Nvoos\Core\Domain\Contract\ToolWriteClassInterface ) {
			return $tool->isWriteClass();
		}

		$capability = '';
		if ( \method_exists( $tool, 'getRequiredCapability' ) ) {
			$capability = (string) $tool->getRequiredCapability();
		}

		// Fail safe: any capability beyond read/public implies mutation.
		return '' !== $capability && 'read' !== $capability && 'public' !== $capability;
	}

	/**
	 * Whether OOS session logging (Proposal 029, Phase 3) is enabled.
	 *
	 * Off by default until replay tests prove log-derived history parity.
	 * Enable via the admin setting enable_oos_session_log or the
	 * wp_mcp_ai_enable_session_log filter.
	 *
	 * @return bool
	 */
	public static function session_log_enabled(): bool {
		if ( \function_exists( 'wp_mcp_ai_enable_session_log' ) ) {
			return \wp_mcp_ai_enable_session_log();
		}

		$settings = \get_option( 'wp_mcp_ai_settings', array() );
		if ( ! empty( $settings['enable_oos_session_log'] ) ) {
			return true;
		}

		return (bool) \apply_filters( 'wp_mcp_ai_enable_session_log', false );
	}

	/**
	 * Whether OOS session-log telemetry (Proposal 029, Phase 5.8) is enabled.
	 *
	 * Off by default: when enabled, every appended session-log entry fans out
	 * to WordPress consumers via the wp_mcp_ai_session_log_event action —
	 * the single path audit/telemetry subscribers consume instead of
	 * re-wrapping the loop. Requires session logging (above) to be enabled.
	 *
	 * Enable via the admin setting enable_oos_session_telemetry or the
	 * wp_mcp_ai_enable_session_telemetry filter.
	 *
	 * @return bool
	 */
	public static function session_telemetry_enabled(): bool {
		if ( \function_exists( 'wp_mcp_ai_enable_session_telemetry' ) ) {
			return \wp_mcp_ai_enable_session_telemetry();
		}

		$settings = \get_option( 'wp_mcp_ai_settings', array() );
		if ( ! empty( $settings['enable_oos_session_telemetry'] ) ) {
			return true;
		}

		return (bool) \apply_filters( 'wp_mcp_ai_enable_session_telemetry', false );
	}

	/**
	 * Whether the request's assistant is canary-opted into the OOS engine
	 * via the _wp_mcp_ai_engine post meta (Proposal 029, Phase 4.2).
	 *
	 * @param \WP_REST_Request|null $request Optional request to read the assistant from.
	 * @return bool
	 */
	public static function canary_enabled( $request = null ): bool {
		if ( \function_exists( 'wp_mcp_ai_oos_canary_enabled' ) ) {
			return \wp_mcp_ai_oos_canary_enabled( $request );
		}

		if ( ! (bool) \apply_filters( 'wp_mcp_ai_oos_canary', false ) ) {
			return false;
		}

		$assistant_id = 0;
		if ( $request instanceof \WP_REST_Request ) {
			$assistant_id = (int) $request->get_param( 'assistant_id' );
		}

		if ( $assistant_id > 0 ) {
			return 'oos' === \get_post_meta( $assistant_id, '_wp_mcp_ai_engine', true );
		}

		return false;
	}
}
