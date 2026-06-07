<?php
declare(strict_types=1);

namespace NvoosGraphifyPlatform;

/**
 * Plugin bootstrap — singleton composition root.
 *
 * @since 1.0.0
 */
final class Plugin {

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

		$this->registerAgents();
		$this->registerSkills();

		// Future subsystems:
		// $this->registerSlashCommands();
		// $this->registerHarness();
		// $this->registerMeasurement();
		// $this->registerProfessions();
		// $this->registerA2A();
		// $this->registerACP();
		// $this->registerFederation();
		// $this->registerBlueprints();
	}

	private function registerAdmin(): void {
		if ( class_exists( 'NvoosGraphifyPlatform\Admin\PlatformSettings' ) ) {
			( new \NvoosGraphifyPlatform\Admin\PlatformSettings() )->register();
		}
	}

	private function registerAgents(): void {
		if ( class_exists( 'NvoosGraphifyPlatform\Agents\Agents' ) ) {
			\NvoosGraphifyPlatform\Agents\Agents::instance()->register();
		}
	}

	private function registerSkills(): void {
		if ( class_exists( 'NvoosGraphifyPlatform\Skills\SkillService' ) ) {
			\NvoosGraphifyPlatform\Skills\SkillService::instance()->register();
		}
	}

	private function __clone() {}
}
