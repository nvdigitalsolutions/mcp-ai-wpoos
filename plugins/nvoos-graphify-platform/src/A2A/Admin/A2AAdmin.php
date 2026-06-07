<?php
declare(strict_types=1);

namespace NvoosGraphifyPlatform\A2A\Admin;

final class A2AAdmin {
	public function register(): void {
		add_action( 'nvoos_graphify/admin/register_sections', array( $this, 'registerSections' ) );
	}
	public function registerSections(): void {
		\NvoosGraphify\Admin\SettingsRegistry::register_tab( 'a2a', __( 'A2A', 'nvoos-graphify-platform' ) );
	}
}
