<?php
/**
 * OOS wave-1 service bridges (Wave E6, sub-cluster 6).
 *
 * Aligned port of the base plugin's wave-1 OOS service bridge
 * factories from `includes/bootstrap/oos-bridge.php`:
 * `wp_mcp_ai_oos_semantic_compressor()`,
 * `wp_mcp_ai_oos_data_budget_tracker()`,
 * `wp_mcp_ai_oos_erlang_c()`, `wp_mcp_ai_oos_error_tracking()`, and
 * `wp_mcp_ai_oos_cost_tracking()` — the framework-agnostic service
 * resolvers that bridge `nvoos/core` domain services with their
 * WordPress implementations (engine enabled) or wrap the legacy base
 * classes in the domain contracts (engine disabled).
 *
 * Documented deviations:
 *  - Class name/namespace — the AI addon's PSR-4 tree (decision D4:
 *    engine pieces fold into `nvoos-content-graph-ai`).
 *  - Global functions → public static methods. Each method defers to
 *    the base's global function when it exists (monolith installs load
 *    `oos-bridge.php`), so monolith behavior is byte-identical —
 *    including the base's quirks (the engine-enabled branch of
 *    `wp_mcp_ai_oos_data_budget_tracker()` constructs the adapter and
 *    then discards it, so the legacy wrapper is returned in BOTH
 *    engine states; the per-factory `static $instance` caches).
 *  - Standalone bodies resolve the real `nvoos/core` /
 *    `nvoos/wordpress-adapter` implementations unconditionally: the
 *    addon bundles those packages and has no legacy engine path, so
 *    the base's engine-gated legacy fallback cannot apply (documented
 *    deviation — the domain contract is identical either way). The
 *    error-tracking resolver is the exception: its framework adapter
 *    delegates to the base legacy singleton (base globals absent
 *    standalone, and the adapter's bare class_exists probe is
 *    unreliable under the monorepo test classmap), so standalone
 *    resolves a native fallback carrying the adapter's own
 *    legacy-absent degradation semantics byte-identically.
 *  - Text domain `nvoos-content-graph-ai` (no translatable strings —
 *    the factories are pure resolution).
 *
 * @since 1.1.0
 * @package NvoosContentGraphAi\Engine
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Engine;

/**
 * Resolves the wave-1 OOS service bridges for the current install mode.
 *
 * @since 1.1.0
 */
final class OosServiceBridges {

	/**
	 * Get the Semantic Compressor — framework-agnostic when engine=oos,
	 * legacy otherwise.
	 *
	 * @return \Nvoos\Core\Domain\Contract\SemanticCompressorInterface
	 */
	public static function semantic_compressor() {
		if ( \function_exists( 'wp_mcp_ai_oos_semantic_compressor' ) ) {
			return \wp_mcp_ai_oos_semantic_compressor();
		}

		// Standalone: the addon bundles the nvoos/core engine — no legacy
		// compressor path exists (see the class docblock).
		static $instance = null;
		if ( null === $instance ) {
			$instance = new \Nvoos\WordPress\Adapter\SemanticCompressor();
		}

		return $instance;
	}

	/**
	 * Get the Data Budget Tracker — framework-agnostic when engine=oos,
	 * legacy otherwise.
	 *
	 * @param string $request_id Optional request identifier.
	 * @return \Nvoos\Core\Domain\Contract\DataBudgetTrackerInterface
	 */
	public static function data_budget_tracker( string $request_id = '' ) {
		if ( \function_exists( 'wp_mcp_ai_oos_data_budget_tracker' ) ) {
			return \wp_mcp_ai_oos_data_budget_tracker( $request_id );
		}

		// Standalone: the base factory's legacy wrapper (and its discarded
		// engine-enabled branch) cannot apply — resolve the real adapter
		// directly (see the class docblock).
		return new \Nvoos\WordPress\Adapter\DataBudgetTracker( $request_id );
	}

	/**
	 * Get the Erlang C calculator.
	 *
	 * @return \Nvoos\Core\Domain\Contract\ErlangCInterface
	 */
	public static function erlang_c() {
		if ( \function_exists( 'wp_mcp_ai_oos_erlang_c' ) ) {
			return \wp_mcp_ai_oos_erlang_c();
		}

		static $instance = null;
		if ( null === $instance ) {
			$instance = new \Nvoos\Core\Domain\Service\Optimization\ErlangC();
		}

		return $instance;
	}

	/**
	 * Get the Error Tracking Service.
	 *
	 * @return \Nvoos\Core\Domain\Contract\ErrorTrackingServiceInterface
	 */
	public static function error_tracking() {
		if ( \function_exists( 'wp_mcp_ai_oos_error_tracking' ) ) {
			return \wp_mcp_ai_oos_error_tracking();
		}

		// Standalone: the framework adapter delegates to the base legacy
		// singleton, which requires base globals that do not exist here —
		// and its bare class_exists probe is unreliable under the monorepo
		// test classmap. Resolve a native fallback carrying the adapter's
		// own legacy-absent degradation semantics byte-identically
		// (err_fallback_ ids, empty recent, 0.0 rate, no-op clear,
		// isEnabled false).
		static $instance = null;
		if ( null === $instance ) {
			$instance = new class() implements \Nvoos\Core\Domain\Contract\ErrorTrackingServiceInterface {
				public function track( string $component, string $message, array $context = array() ): string {
					return 'err_fallback_' . \uniqid( '', true );
				}

				public function getRecent( int $limit = 50 ): array {
					return array();
				}

				public function getRate( string $component = '', int $windowSeconds = 3600 ): float {
					return 0.0;
				}

				public function clear(): void {}

				public function isEnabled(): bool {
					return false;
				}
			};
		}

		return $instance;
	}

	/**
	 * Get the Cost Tracking Service.
	 *
	 * @return \Nvoos\Core\Domain\Contract\CostTrackingServiceInterface
	 */
	public static function cost_tracking() {
		if ( \function_exists( 'wp_mcp_ai_oos_cost_tracking' ) ) {
			return \wp_mcp_ai_oos_cost_tracking();
		}

		static $instance = null;
		if ( null === $instance ) {
			$instance = new \Nvoos\WordPress\Adapter\CostTrackingService();
		}

		return $instance;
	}
}
