<?php
/**
 * Measurement subsystem service.
 *
 * Composition root for the Measurement subsystem: registers the admin UI in
 * every mode and owns the runtime wiring in standalone mode only (the base
 * plugin's measurement bootstrap owns it in monolith mode). The wiring is
 * added here — not at file scope in the shim — so every register() call
 * re-adds the hooks idempotently (add_action dedupes by tuple), which keeps
 * production behaviour identical and the wiring robust under test-framework
 * hook-global resets.
 *
 * @package NvoosContentGraphAiPlatform\Measurement
 * @since 2.0.0
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Measurement;

final class MeasurementService {

	/**
	 * Singleton instance.
	 *
	 * @var MeasurementService|null
	 */
	private static ?self $instance = null;

	/**
	 * Private constructor (singleton).
	 */
	private function __construct() {}

	/**
	 * Get the singleton instance.
	 *
	 * @return MeasurementService
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register the Measurement subsystem.
	 */
	public function register(): void {
		if ( is_admin() && class_exists( 'NvoosContentGraphAiPlatform\Measurement\Admin\MeasurementAdmin' ) ) {
			( new \NvoosContentGraphAiPlatform\Measurement\Admin\MeasurementAdmin() )->register();
		}

		// Monolith mode: the base plugin owns measurement runtime wiring —
		// never register the standalone shim twice.
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		// Standalone mode: load the ported bootstrap function surface first,
		// then wire the lifecycle hooks (mirroring the base bootstrap file).
		require_once __DIR__ . '/shim-functions.php';

		// `plugins_loaded` at a late priority ensures other plugins (and the
		// Pro addon) have had a chance to register their own measurement
		// hooks before the registries freeze.
		add_action( 'plugins_loaded', 'wp_mcp_ai_measurement_bootstrap', 50 );
		add_action( 'admin_init', 'wp_mcp_ai_measurement_ensure_capabilities', 5 );
		add_action( 'wp_mcp_ai_register_verifiers', 'wp_mcp_ai_register_reference_verifiers', 20 );
		add_action( 'wp_mcp_ai_register_reward_functions', 'wp_mcp_ai_register_reference_rewards', 20 );

		// Register chat-turn and SSE stock metric definitions at priority 20
		// so site overrides (priority 10) can pre-empt by id. The base
		// plugin's WP_MCP_AI_Stock_Metrics registrar is monolith-only and
		// intentionally not wired here.
		add_action(
			'wp_mcp_ai_register_metrics',
			array( ChatTurnMetrics::class, 'register' ),
			20
		);
		add_action(
			'wp_mcp_ai_register_metrics',
			array( SseMetrics::class, 'register' ),
			20
		);

		// Belt-and-braces retention-cron scheduling. The primary scheduling
		// path is the activation hook, but upgrades via `wp plugin update`
		// or `composer update` skip it; running this on `init` at a late
		// priority ensures the cron is scheduled without a reactivation.
		add_action(
			'init',
			static function (): void {
				MetricRetention::schedule();
			},
			50
		);
	}

	/**
	 * Prevent cloning (singleton).
	 */
	private function __clone() {}
}
