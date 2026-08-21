<?php
/**
 * Skills admin — registers the Skills tab in both the NV Platform
 * dashboard (primary) and the NV Content Graph dashboard (courtesy).
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\Skills\Admin
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Skills\Admin;

use NvoosContentGraph\Admin\SettingsRegistry as ContentGraphRegistry;

/**
 * Skills management admin UI.
 */
final class SkillsAdmin {

	public function register(): void {
		add_action( 'nvoos_content_graph_ai_platform_admin_register_sections', array( $this, 'registerPlatformSections' ) );
		add_action( 'nvoos_content_graph/admin/register_sections', array( $this, 'registerContentGraphSections' ) );
	}

	public function registerPlatformSections(): void {
		\NvoosContentGraphAiPlatform\Admin\PlatformSettingsRegistry::register_tab(
			'skills',
			__( 'Skills', 'nvoos-content-graph-ai-platform' )
		);

		if ( class_exists( 'NvoosContentGraphAiPlatform\Skills\Admin\SkillsDashboardSection' ) ) {
			\NvoosContentGraphAiPlatform\Admin\PlatformSettingsRegistry::register_section(
				new SkillsDashboardSection()
			);
		}
	}

	public function registerContentGraphSections(): void {
		ContentGraphRegistry::register_tab( 'skills', __( 'Skills', 'nvoos-content-graph-ai-platform' ) );
	}
}
