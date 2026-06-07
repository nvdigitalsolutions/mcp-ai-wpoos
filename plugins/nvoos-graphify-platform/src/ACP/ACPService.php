<?php
declare(strict_types=1);

namespace NvoosGraphifyPlatform\ACP;

final class ACPService {
	private static ?self $instance = null;
	private function __construct() {}
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	public function register(): void {
		if ( is_admin() && class_exists( 'NvoosGraphifyPlatform\ACP\Admin\ACPAdmin' ) ) {
			( new \NvoosGraphifyPlatform\ACP\Admin\ACPAdmin() )->register();
		}
	}
	private function __clone() {}
}
