<?php
declare(strict_types=1);

namespace NvoosGraphifyPlatform\Admin;

use NvoosGraphify\Admin\SettingsRegistry;

final class PlatformSettings {

	public function register(): void {
		add_action( 'nvoos_graphify/admin/register_sections', array( $this, 'registerSections' ) );
	}

	public function registerSections(): void {
		SettingsRegistry::register_tab( 'agents', __( 'Agents', 'nvoos-graphify-platform' ) );
		SettingsRegistry::register_tab( 'skills', __( 'Skills', 'nvoos-graphify-platform' ) );
		SettingsRegistry::register_tab( 'slash_commands', __( 'Slash Commands', 'nvoos-graphify-platform' ) );
		SettingsRegistry::register_tab( 'harness', __( 'Harness', 'nvoos-graphify-platform' ) );
	}
}
