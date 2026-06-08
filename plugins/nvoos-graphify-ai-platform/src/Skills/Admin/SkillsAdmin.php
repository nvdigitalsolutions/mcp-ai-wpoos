<?php
/**
 * Skills admin — registers the Skills tab in both the NV Platform
 * dashboard (primary) and the NV Graphify dashboard (courtesy).
 *
 * @since 1.0.0
 * @package NvoosGraphifyAiPlatform\Skills\Admin
 */

declare(strict_types=1);

namespace NvoosGraphifyAiPlatform\Skills\Admin;

use NvoosGraphify\Admin\SettingsRegistry as GraphifyRegistry;

/**
 * Skills management admin UI.
 */
final class SkillsAdmin {

	public function register(): void {
		add_action( 'ai_platform/admin/register_sections', array( $this, 'registerPlatformSections' ) );
		add_action( 'nvoos_graphify/admin/register_sections', array( $this, 'registerGraphifySections' ) );
	}

	public function registerPlatformSections(): void {
		\NvoosGraphifyAiPlatform\Admin\PlatformSettingsRegistry::register_tab(
			'skills',
			__( 'Skills', 'nvoos-graphify-ai-platform' )
		);

		if ( class_exists( 'NvoosGraphifyAiPlatform\Skills\Admin\SkillsDashboardSection' ) ) {
			\NvoosGraphifyAiPlatform\Admin\PlatformSettingsRegistry::register_section(
				new SkillsDashboardSection()
			);
		}
	}

	public function registerGraphifySections(): void {
		GraphifyRegistry::register_tab( 'skills', __( 'Skills', 'nvoos-graphify-ai-platform' ) );
	}
}
