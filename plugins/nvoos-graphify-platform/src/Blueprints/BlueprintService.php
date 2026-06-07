<?php
declare(strict_types=1);

namespace NvoosGraphifyPlatform\Blueprints;

final class BlueprintService {
	private static ?self $instance = null;
	private function __construct() {}
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	public function register(): void {
		if ( is_admin() && class_exists( 'NvoosGraphifyPlatform\Blueprints\Admin\BlueprintAdmin' ) ) {
			( new \NvoosGraphifyPlatform\Blueprints\Admin\BlueprintAdmin() )->register();
		}
	}
	private function __clone() {}
}
