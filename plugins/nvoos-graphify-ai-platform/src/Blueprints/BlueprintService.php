<?php
declare(strict_types=1);

namespace NvoosGraphifyAiPlatform\Blueprints;

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
		if ( is_admin() && class_exists( 'NvoosGraphifyAiPlatform\Blueprints\Admin\BlueprintAdmin' ) ) {
			( new \NvoosGraphifyAiPlatform\Blueprints\Admin\BlueprintAdmin() )->register();
		}
	}
	private function __clone() {}
}
