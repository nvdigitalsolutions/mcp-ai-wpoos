<?php
declare(strict_types=1);

namespace NvoosGraphifyAiPlatform;

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
			( new \NvoosGraphifyAiPlatform\Admin\PlatformSettings() )->register();
		}
	}

	private function registerAgents(): void {
		if ( class_exists( __NAMESPACE__ . '\Agents\Agents' ) ) {
			\NvoosGraphifyAiPlatform\Agents\Agents::instance()->register();
		}
	}

	private function registerSkills(): void {
		if ( class_exists( __NAMESPACE__ . '\Skills\SkillService' ) ) {
			\NvoosGraphifyAiPlatform\Skills\SkillService::instance()->register();
		}
	}

	private function registerSlashCommands(): void {
		if ( class_exists( __NAMESPACE__ . '\SlashCommands\SlashCommandService' ) ) {
			\NvoosGraphifyAiPlatform\SlashCommands\SlashCommandService::instance()->register();
		}
	}

	private function registerHarness(): void {
		if ( class_exists( __NAMESPACE__ . '\Harness\HarnessService' ) ) {
			\NvoosGraphifyAiPlatform\Harness\HarnessService::instance()->register();
		}
	}

	private function registerMeasurement(): void {
		if ( class_exists( __NAMESPACE__ . '\Measurement\MeasurementService' ) ) {
			\NvoosGraphifyAiPlatform\Measurement\MeasurementService::instance()->register();
		}
	}

	private function registerProfessions(): void {
		if ( class_exists( __NAMESPACE__ . '\Professions\ProfessionService' ) ) {
			\NvoosGraphifyAiPlatform\Professions\ProfessionService::instance()->register();
		}
	}

	private function registerA2A(): void {
		if ( class_exists( __NAMESPACE__ . '\A2A\A2AService' ) ) {
			\NvoosGraphifyAiPlatform\A2A\A2AService::instance()->register();
		}
	}

	private function registerACP(): void {
		if ( class_exists( __NAMESPACE__ . '\ACP\ACPService' ) ) {
			\NvoosGraphifyAiPlatform\ACP\ACPService::instance()->register();
		}
	}

	private function registerFederation(): void {
		if ( class_exists( __NAMESPACE__ . '\Federation\FederationService' ) ) {
			\NvoosGraphifyAiPlatform\Federation\FederationService::instance()->register();
		}
	}

	private function registerBlueprints(): void {
		if ( class_exists( __NAMESPACE__ . '\Blueprints\BlueprintService' ) ) {
			\NvoosGraphifyAiPlatform\Blueprints\BlueprintService::instance()->register();
		}
	}

	private function __clone() {}
}
