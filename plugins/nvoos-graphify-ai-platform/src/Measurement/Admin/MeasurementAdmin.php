<?php
declare(strict_types=1);

namespace NvoosGraphifyAiPlatform\Measurement\Admin;

final class MeasurementAdmin {
	public function register(): void {
		add_action( 'nvoos_graphify/admin/register_sections', array( $this, 'registerSections' ) );
	}
	public function registerSections(): void {
		\NvoosGraphify\Admin\SettingsRegistry::register_tab( 'measurement', __( 'Measurement', 'nvoos-graphify-ai-platform' ) );
	}
}
