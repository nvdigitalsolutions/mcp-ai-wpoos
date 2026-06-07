<?php
/**
 * Registers Platform tabs and sections into the core's SettingsRegistry.
 *
 * Platform settings appear as additional tabs on the main NV Graphify
 * settings page — no separate menu page. Uses the same
 * Section/Registry pattern as the core and AI addon.
 *
 * @since 1.0.0
 * @package NvoosGraphifyPlatform\Admin
 */

declare(strict_types=1);

namespace NvoosGraphifyPlatform\Admin;

use NvoosGraphify\Admin\SettingsRegistry;

/**
 * Platform settings page — injects tabs and sections.
 */
final class PlatformSettings {

	/**
	 * Register the hook that injects platform sections.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'nvoos_graphify/admin/register_sections', array( $this, 'registerSections' ) );
	}

	/**
	 * Register platform tabs and sections into the core's SettingsRegistry.
	 *
	 * Hooked to `nvoos_graphify/admin/register_sections` — fires
	 * during the core's `admin_init`, so platform sections appear as
	 * additional tabs on the NV Graphify settings page.
	 *
	 * @return void
	 */
	public function registerSections(): void {
		// ✅ Priority 2.2a: Agents
		SettingsRegistry::register_tab( 'agents', __( 'Agents', 'nvoos-graphify-platform' ) );

		// Future tabs as subsystems are extracted:
		// SettingsRegistry::register_tab( 'skills', __( 'Skills', 'nvoos-graphify-platform' ) );
		// SettingsRegistry::register_tab( 'federation', __( 'Federation', 'nvoos-graphify-platform' ) );
	}
}
