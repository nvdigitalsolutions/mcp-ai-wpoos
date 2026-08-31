<?php
/**
 * Federation subsystem service.
 *
 * Composition root for the Federation subsystem: registers admin UI and, when
 * the base plugin is absent (standalone mode), boots the ported federation
 * system (well-known discovery, peer CPT, directory REST). In monolith mode
 * the base plugin's federation bootstrap owns all federation wiring — never
 * register twice.
 *
 * @package NvoosContentGraphAiPlatform
 * @since 1.0.0
 * @since 2.0.0 Boots the ported federation system in standalone mode (extraction Wave A).
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Federation;

final class FederationService {
	private static ?self $instance = null;
	private function __construct() {}
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	public function register(): void {
		if ( is_admin() && class_exists( 'NvoosContentGraphAiPlatform\Federation\Admin\FederationAdmin' ) ) {
			( new \NvoosContentGraphAiPlatform\Federation\Admin\FederationAdmin() )->register();
		}

		// Standalone mode: base plugin absent — this addon owns the federation
		// system. Monolith mode: base plugin owns federation wiring.
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		$registry = function_exists( 'nvoos_content_graph_get_tool_registry' )
			? nvoos_content_graph_get_tool_registry()
			: null;

		new Federation( $registry );
	}
	private function __clone() {}
}
