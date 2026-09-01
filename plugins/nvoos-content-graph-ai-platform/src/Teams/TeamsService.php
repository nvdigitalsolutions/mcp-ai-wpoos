<?php
/**
 * Teams subsystem service.
 *
 * Composition root for the Teams subsystem. The base plugin owns team
 * runtime wiring (CPT registration + seeding) in monolith mode; this
 * service wires the ported copies in standalone mode only — preventing
 * double hook registration when both plugins are active.
 *
 * @package NvoosContentGraphAiPlatform
 * @since 2.0.0
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Teams;

final class TeamsService {

	/**
	 * Singleton instance.
	 *
	 * @var TeamsService|null
	 */
	private static ?self $instance = null;

	/**
	 * Private constructor (singleton).
	 */
	private function __construct() {}

	/**
	 * Get the singleton instance.
	 *
	 * @return TeamsService
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register the Teams subsystem.
	 *
	 * Mirrors the base plugin's teams-init.php wiring (init, priority 5):
	 * instantiate the CPT (which hooks its own registration) and arm the
	 * seeder. Standalone mode only — the base plugin owns this in monolith
	 * mode and must never be double-wired.
	 */
	public function register(): void {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return;
		}

		add_action(
			'init',
			static function (): void {
				new TeamCpt();
				TeamSeeder::init();
			},
			5
		);
	}

	/**
	 * Prevent cloning (singleton).
	 */
	private function __clone() {}
}
