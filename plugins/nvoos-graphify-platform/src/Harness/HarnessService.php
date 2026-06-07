<?php
declare(strict_types=1);

namespace NvoosGraphifyPlatform\Harness;

final class HarnessService {

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
		if ( class_exists( 'NvoosGraphifyPlatform\Harness\Admin\HarnessAdmin' ) ) {
			( new \NvoosGraphifyPlatform\Harness\Admin\HarnessAdmin() )->register();
		}
	}

	private function __clone() {}
}
