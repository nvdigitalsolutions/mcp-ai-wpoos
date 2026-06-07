<?php
/**
 * Registers Platform tabs and sections into the core's SettingsRegistry.
 *
 * @since 1.0.0
 * @package NvoosGraphifyPlatform\Admin
 */

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

		// Future tabs as subsystems are extracted:
		// SettingsRegistry::register_tab( 'federation', __( 'Federation', 'nvoos-graphify-platform' ) );
	}
}
