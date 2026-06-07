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

	private function registerSlashCommands(): void {
		if ( class_exists( 'NvoosGraphifyPlatform\SlashCommands\SlashCommandService' ) ) {
			\NvoosGraphifyPlatform\SlashCommands\SlashCommandService::instance()->register();
		}
	}

	private function __clone() {}
}
