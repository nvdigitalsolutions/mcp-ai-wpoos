<?php
declare(strict_types=1);

namespace NvoosGraphifyPlatform;

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
		$this->registerSlashCommands();
		$this->registerHarness();
		$this->registerMeasurement();
		$this->registerProfessions();
		$this->registerA2A();
		$this->registerACP();
		$this->registerFederation();
		$this->registerBlueprints();
	}

	private function registerAdmin(): void {
		if ( class_exists( __NAMESPACE__ . '\Admin\PlatformSettings' ) ) {
			( new \NvoosGraphifyPlatform\Admin\PlatformSettings() )->register();
		}
	}

	private function registerAgents(): void {
		if ( class_exists( __NAMESPACE__ . '\Agents\Agents' ) ) {
			\NvoosGraphifyPlatform\Agents\Agents::instance()->register();
		}
	}

	private function registerSkills(): void {
		if ( class_exists( __NAMESPACE__ . '\Skills\SkillService' ) ) {
			\NvoosGraphifyPlatform\Skills\SkillService::instance()->register();
		}
	}

	private function registerSlashCommands(): void {
		if ( class_exists( __NAMESPACE__ . '\SlashCommands\SlashCommandService' ) ) {
			\NvoosGraphifyPlatform\SlashCommands\SlashCommandService::instance()->register();
		}
	}

	private function registerHarness(): void {
		if ( class_exists( __NAMESPACE__ . '\Harness\HarnessService' ) ) {
			\NvoosGraphifyPlatform\Harness\HarnessService::instance()->register();
		}
	}

	private function registerMeasurement(): void {
		if ( class_exists( __NAMESPACE__ . '\Measurement\MeasurementService' ) ) {
			\NvoosGraphifyPlatform\Measurement\MeasurementService::instance()->register();
		}
	}

	private function registerProfessions(): void {
		if ( class_exists( __NAMESPACE__ . '\Professions\ProfessionService' ) ) {
			\NvoosGraphifyPlatform\Professions\ProfessionService::instance()->register();
		}
	}

	private function registerA2A(): void {
		if ( class_exists( __NAMESPACE__ . '\A2A\A2AService' ) ) {
			\NvoosGraphifyPlatform\A2A\A2AService::instance()->register();
		}
	}

	private function registerACP(): void {
		if ( class_exists( __NAMESPACE__ . '\ACP\ACPService' ) ) {
			\NvoosGraphifyPlatform\ACP\ACPService::instance()->register();
		}
	}

	private function registerFederation(): void {
		if ( class_exists( __NAMESPACE__ . '\Federation\FederationService' ) ) {
			\NvoosGraphifyPlatform\Federation\FederationService::instance()->register();
		}
	}

	private function registerBlueprints(): void {
		if ( class_exists( __NAMESPACE__ . '\Blueprints\BlueprintService' ) ) {
			\NvoosGraphifyPlatform\Blueprints\BlueprintService::instance()->register();
		}
	}

	private function __clone() {}
}
