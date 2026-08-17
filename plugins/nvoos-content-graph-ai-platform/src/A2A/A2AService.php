<?php
declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\A2A;

final class A2AService {
	private static ?self $instance = null;
	private function __construct() {}
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	public function register(): void {
		if ( is_admin() && class_exists( 'NvoosContentGraphAiPlatform\A2A\Admin\A2AAdmin' ) ) {
			( new \NvoosContentGraphAiPlatform\A2A\Admin\A2AAdmin() )->register();
		}
	}
	private function __clone() {}
}
