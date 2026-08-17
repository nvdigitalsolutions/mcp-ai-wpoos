<?php
/**
 * Professions admin — dual registration in Platform + Content Graph.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\Professions\Admin
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Professions\Admin;

use NvoosContentGraph\Admin\SettingsRegistry as ContentGraphRegistry;

final class ProfessionAdmin {

	public function register(): void {
		add_action( 'nvoos_content_graph_ai_platform_admin_register_sections', array( $this, 'registerPlatformSections' ) );
		add_action( 'nvoos_content_graph/admin/register_sections', array( $this, 'registerContentGraphSections' ) );
	}

	public function registerPlatformSections(): void {
		\NvoosContentGraphAiPlatform\Admin\PlatformSettingsRegistry::register_tab(
			'professions',
			__( 'Professions', 'nvoos-content-graph-ai-platform' )
		);

		if ( class_exists( 'NvoosContentGraphAiPlatform\Professions\Admin\ProfessionsDashboardSection' ) ) {
			\NvoosContentGraphAiPlatform\Admin\PlatformSettingsRegistry::register_section(
				new ProfessionsDashboardSection()
			);
		}
	}

	public function registerContentGraphSections(): void {
		ContentGraphRegistry::register_tab( 'professions', __( 'Professions', 'nvoos-content-graph-ai-platform' ) );
	}
}
