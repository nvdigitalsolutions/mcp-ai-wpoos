<?php
/**
 * Professions admin — dual registration in Platform + Graphify.
 *
 * @since 1.0.0
 * @package NvoosGraphifyAiPlatform\Professions\Admin
 */

declare(strict_types=1);

namespace NvoosGraphifyAiPlatform\Professions\Admin;

use NvoosGraphify\Admin\SettingsRegistry as GraphifyRegistry;

final class ProfessionAdmin {

	public function register(): void {
		add_action( 'nvoos_graphify_ai_platform_admin_register_sections', array( $this, 'registerPlatformSections' ) );
		add_action( 'nvoos_graphify/admin/register_sections', array( $this, 'registerGraphifySections' ) );
	}

	public function registerPlatformSections(): void {
		\NvoosGraphifyAiPlatform\Admin\PlatformSettingsRegistry::register_tab(
			'professions',
			__( 'Professions', 'nvoos-graphify-ai-platform' )
		);

		if ( class_exists( 'NvoosGraphifyAiPlatform\Professions\Admin\ProfessionsDashboardSection' ) ) {
			\NvoosGraphifyAiPlatform\Admin\PlatformSettingsRegistry::register_section(
				new ProfessionsDashboardSection()
			);
		}
	}

	public function registerGraphifySections(): void {
		GraphifyRegistry::register_tab( 'professions', __( 'Professions', 'nvoos-graphify-ai-platform' ) );
	}
}
