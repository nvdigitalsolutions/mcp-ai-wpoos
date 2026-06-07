<?php
declare(strict_types=1);

namespace NvoosGraphifyPlatform\Blueprints\Admin;

final class BlueprintAdmin {
	public function register(): void {
		add_action( 'nvoos_graphify/admin/register_sections', array( $this, 'registerSections' ) );
	}
	public function registerSections(): void {
		\NvoosGraphify\Admin\SettingsRegistry::register_tab( 'blueprints', __( 'Blueprints', 'nvoos-graphify-platform' ) );
	}
}
