<?php
declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Professions;

final class ProfessionService {
	private static ?self $instance = null;
	private function __construct() {}
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	public function register(): void {
		if ( is_admin() && class_exists( 'NvoosContentGraphAiPlatform\Professions\Admin\ProfessionAdmin' ) ) {
			( new \NvoosContentGraphAiPlatform\Professions\Admin\ProfessionAdmin() )->register();
		}
	}
	private function __clone() {}
}
