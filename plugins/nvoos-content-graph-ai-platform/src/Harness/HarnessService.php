<?php
declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness;

final class HarnessService {

	private static ?self $instance = null;

	private function __construct() {}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register(): void {
		if ( is_admin() ) {
			$this->registerAdmin();
		}

		// Monolith mode: the base plugin's harness-init.php owns the runtime
		// wiring — never register twice (extraction plan §3).
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		$this->registerStandalone();
	}

	/**
	 * Wire the ported harness subscribers in standalone mode.
	 *
	 * Mirrors the subscriber wiring in the base plugin's
	 * includes/harness/harness-init.php. The eight harness tools are
	 * registered into the CG-AI core registry via HarnessToolRegistrar
	 * (D8 Cluster 3); the artifact-replay verifier remains base-owned.
	 *
	 * @return void
	 */
	private function registerStandalone(): void {
		EvolutionSettingsBridge::register();
		HarnessPromptInjector::register();
		Guardrails::register();
		NecessityGate::register();
		OutputGuardrail::register();
		CitationVerifier::register();
		HarnessEvalScheduler::register();
		HarnessTraceCapture::register();

		// D8 Cluster 3: surface the eight harness tools through the
		// CG-AI core registry (no-op in monolith installs where the
		// base plugin owns the same slugs).
		HarnessToolRegistrar::register();
	}

	private function registerAdmin(): void {
		if ( class_exists( 'NvoosContentGraphAiPlatform\Harness\Admin\HarnessAdmin' ) ) {
			( new \NvoosContentGraphAiPlatform\Harness\Admin\HarnessAdmin() )->register();
		}
	}

	private function __clone() {}
}
