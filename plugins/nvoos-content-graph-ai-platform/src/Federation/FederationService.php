<?php
declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Federation;

final class FederationService {
	private static ?self $instance = null;
	private function __construct() {}
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	public function register(): void {
		if ( is_admin() && class_exists( 'NvoosContentGraphAiPlatform\Federation\Admin\FederationAdmin' ) ) {
			( new \NvoosContentGraphAiPlatform\Federation\Admin\FederationAdmin() )->register();
		}
	}
	private function __clone() {}
}
