<?php
declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Measurement;

final class MeasurementService {
	private static ?self $instance = null;
	private function __construct() {}
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	public function register(): void {
		if ( is_admin() && class_exists( 'NvoosContentGraphAiPlatform\Measurement\Admin\MeasurementAdmin' ) ) {
			( new \NvoosContentGraphAiPlatform\Measurement\Admin\MeasurementAdmin() )->register();
		}

		// Monolith mode: the base plugin owns measurement runtime wiring —
		// never register the standalone shim twice.
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		// Standalone mode: load the ported bootstrap. The shim file carries
		// the base-identical global functions (wp_mcp_ai_measurement_bootstrap
		// etc.) and their plugins_loaded/admin_init/hook wiring.
		require_once __DIR__ . '/shim-functions.php';
	}
	private function __clone() {}
}
