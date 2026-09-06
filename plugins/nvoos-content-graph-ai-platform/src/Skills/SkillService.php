<?php
/**
 * Skills subsystem — skill registry, parser, and pack management.
 *
 * Composition root: registers the admin UI in every mode and owns the
 * deferred bundled-skills install in standalone mode only (mirroring the
 * base plugin's bootstrap/activation.php wiring). The ported registry,
 * parser, and pack registry (src/Skills/) are passive singletons.
 *
 * @since 1.0.0
 * @since 2.0.0 Owns the deferred bundled-skills install in standalone mode (extraction).
 * @package NvoosContentGraphAiPlatform\Skills
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Skills;

/**
 * Skills service — wires skill management into the Platform addon.
 */
final class SkillService {

	/** @var self|null */
	private static ?self $instance = null;

	private function __construct() {}

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register skill-related hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( is_admin() ) {
			$this->registerAdmin();
		}

		// Standalone mode only: the base plugin owns this wiring in monolith
		// mode (bootstrap/activation.php) — never wire twice.
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		// Deferred init: install bundled skills when the activation transient
		// is set (mirrors the base plugin's bootstrap/activation.php, init
		// priority 100).
		add_action(
			'init',
			static function (): void {
				if ( get_transient( 'wp_mcp_ai_install_bundled_skills' ) ) {
					delete_transient( 'wp_mcp_ai_install_bundled_skills' );

					$registry = SkillRegistry::instance();
					$result   = $registry->install_bundled_skills();

					// Log any errors for debugging.
					if ( ! empty( $result['errors'] ) && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Development debugging only when WP_DEBUG is enabled.
						error_log( 'nvoos-content-graph-ai-platform: Bundled skills install errors: ' . implode( '; ', $result['errors'] ) );
					}
				}
			},
			100
		);
	}

	private function registerAdmin(): void {
		if ( class_exists( 'NvoosContentGraphAiPlatform\Skills\Admin\SkillsAdmin' ) ) {
			( new \NvoosContentGraphAiPlatform\Skills\Admin\SkillsAdmin() )->register();
		}
	}

	/** Prevent cloning. */
	private function __clone() {}
}
