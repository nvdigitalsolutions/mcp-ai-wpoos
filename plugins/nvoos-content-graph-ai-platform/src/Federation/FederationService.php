<?php
/**
 * Federation subsystem service.
 *
 * Composition root for the Federation subsystem: registers admin UI and, when
 * the base plugin is absent (standalone mode), owns the federation server
 * surface (well-known discovery endpoints) when federation or the directory
 * service is enabled. In monolith mode the base plugin's federation bootstrap
 * owns all federation wiring — never register twice.
 *
 * @package NvoosContentGraphAiPlatform
 * @since 1.0.0
 * @since 2.0.0 Owns the federation server surface in standalone mode (extraction Wave A).
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
		// server surface. Monolith mode: base plugin owns federation wiring.
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_federation'] ) && empty( $settings['enable_federation_directory'] ) ) {
			return;
		}

		$registry = function_exists( 'nvoos_content_graph_get_tool_registry' )
			? nvoos_content_graph_get_tool_registry()
			: null;

		new WellKnown( $registry );
	}
	private function __clone() {}
}
