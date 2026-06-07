<?php
declare(strict_types=1);

namespace NvoosGraphifyAiPlatform\Measurement;

final class MeasurementService {
	private static ?self $instance = null;
	private function __construct() {}
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}
	public function register(): void {
		if ( is_admin() && class_exists( 'NvoosGraphifyAiPlatform\Measurement\Admin\MeasurementAdmin' ) ) {
			( new \NvoosGraphifyAiPlatform\Measurement\Admin\MeasurementAdmin() )->register();
		}
	}
	private function __clone() {}
}
