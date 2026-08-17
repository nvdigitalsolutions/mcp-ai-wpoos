<?php
/**
 * ACP admin — dual registration in Platform + Content Graph.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\ACP\Admin
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\ACP\Admin;

use NvoosContentGraph\Admin\SettingsRegistry as ContentGraphRegistry;

final class ACPAdmin {

	public function register(): void {
		add_action( 'nvoos_content_graph_ai_platform_admin_register_sections', array( $this, 'registerPlatformSections' ) );
		add_action( 'nvoos_content_graph/admin/register_sections', array( $this, 'registerContentGraphSections' ) );
	}

	public function registerPlatformSections(): void {
		\NvoosContentGraphAiPlatform\Admin\PlatformSettingsRegistry::register_tab(
			'acp',
			__( 'ACP', 'nvoos-content-graph-ai-platform' )
		);

		if ( class_exists( 'NvoosContentGraphAiPlatform\ACP\Admin\ACPDashboardSection' ) ) {
			\NvoosContentGraphAiPlatform\Admin\PlatformSettingsRegistry::register_section(
				new ACPDashboardSection()
			);
		}
	}

	public function registerContentGraphSections(): void {
		ContentGraphRegistry::register_tab( 'acp', __( 'ACP', 'nvoos-content-graph-ai-platform' ) );
	}
}
