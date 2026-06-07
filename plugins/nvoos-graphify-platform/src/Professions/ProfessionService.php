<?php
declare(strict_types=1);

namespace NvoosGraphifyPlatform\Professions;

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
		if ( is_admin() && class_exists( 'NvoosGraphifyPlatform\Professions\Admin\ProfessionAdmin' ) ) {
			( new \NvoosGraphifyPlatform\Professions\Admin\ProfessionAdmin() )->register();
		}
	}
	private function __clone() {}
}
