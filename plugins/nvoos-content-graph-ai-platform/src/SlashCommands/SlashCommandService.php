<?php
declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SlashCommands;

final class SlashCommandService {

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
	}

	private function registerAdmin(): void {
		if ( class_exists( 'NvoosContentGraphAiPlatform\SlashCommands\Admin\SlashCommandsAdmin' ) ) {
			( new \NvoosContentGraphAiPlatform\SlashCommands\Admin\SlashCommandsAdmin() )->register();
		}
	}

	private function __clone() {}
}
