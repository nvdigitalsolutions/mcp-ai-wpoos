<?php
/**
 * ACP subsystem service.
 *
 * Composition root for the ACP subsystem: registers admin UI and, when the
 * base plugin is absent (standalone mode), owns the ACP runtime wiring
 * (JSON-RPC dispatcher + HTTP transport mounted on rest_api_init). In
 * monolith mode the base plugin's REST layer owns ACP routing — never
 * register twice.
 *
 * @package NvoosContentGraphAiPlatform
 * @since 1.0.0
 * @since 2.0.0 Owns ACP runtime wiring in standalone mode (extraction).
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\ACP;

final class ACPService {
	private static ?self $instance = null;
	private function __construct() {}
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	public function register(): void {
		if ( is_admin() && class_exists( 'NvoosContentGraphAiPlatform\ACP\Admin\ACPAdmin' ) ) {
			( new \NvoosContentGraphAiPlatform\ACP\Admin\ACPAdmin() )->register();
		}

		// Standalone mode: mount the ACP transport when enabled in the
		// platform settings. Monolith mode: base plugin owns ACP routing.
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		$settings = get_option( 'ai_platform_settings', array() );
		if ( empty( $settings['acp_enabled'] ) ) {
			return;
		}

		add_action(
			'rest_api_init',
			static function (): void {
				if ( ! class_exists( __NAMESPACE__ . '\TransportHttp' ) ) {
					return;
				}
				$transport = new TransportHttp(
					new JsonRpcDispatcher( new SessionManager(), new SessionBridge() )
				);
				$transport->register_routes();
			}
		);
	}
	private function __clone() {}
}
