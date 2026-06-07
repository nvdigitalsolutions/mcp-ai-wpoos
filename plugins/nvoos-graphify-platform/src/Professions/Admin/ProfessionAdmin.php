<?php
declare(strict_types=1);

namespace NvoosGraphifyPlatform\Professions\Admin;

final class ProfessionAdmin {
	public function register(): void {
		add_action( 'nvoos_graphify/admin/register_sections', array( $this, 'registerSections' ) );
	}
	public function registerSections(): void {
		\NvoosGraphify\Admin\SettingsRegistry::register_tab( 'professions', __( 'Professions', 'nvoos-graphify-platform' ) );
	}
}
