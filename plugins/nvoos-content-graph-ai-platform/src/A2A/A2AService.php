<?php
/**
 * A2A subsystem service.
 *
 * Composition root for the A2A subsystem: registers admin UI and, when the
 * base plugin is absent (standalone mode), owns the A2A runtime wiring
 * (well-known discovery endpoint). When the base plugin is present, the base
 * plugin's own A2A implementation owns the runtime wiring and this service
 * only contributes admin UI — preventing double hook registration.
 *
 * @package NvoosContentGraphAiPlatform
 * @since 1.0.0
 * @since 2.0.0 Owns A2A runtime wiring in standalone mode (extraction).
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\A2A;

final class A2AService {
	private static ?self $instance = null;
	private function __construct() {}
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	public function register(): void {
		if ( is_admin() && class_exists( 'NvoosContentGraphAiPlatform\A2A\Admin\A2AAdmin' ) ) {
			( new \NvoosContentGraphAiPlatform\A2A\Admin\A2AAdmin() )->register();
		}

		// Standalone mode: the base plugin is absent, so this addon owns the
		// A2A runtime wiring. In monolith mode the base plugin wires its own
		// copy — never register twice.
		if ( ! defined( 'WP_MCP_AI_PATH' ) && class_exists( __NAMESPACE__ . '\WellKnown' ) ) {
			new WellKnown();
		}
	}
	private function __clone() {}
}
