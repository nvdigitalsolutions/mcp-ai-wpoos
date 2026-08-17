<?php
/**
 * A2A admin — dual registration in Platform + Content Graph.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\A2A\Admin
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\A2A\Admin;

use NvoosContentGraph\Admin\SettingsRegistry as ContentGraphRegistry;

final class A2AAdmin {

	public function register(): void {
		add_action( 'nvoos_content_graph_ai_platform_admin_register_sections', array( $this, 'registerPlatformSections' ) );
		add_action( 'nvoos_content_graph/admin/register_sections', array( $this, 'registerContentGraphSections' ) );
	}

	public function registerPlatformSections(): void {
		\NvoosContentGraphAiPlatform\Admin\PlatformSettingsRegistry::register_tab(
			'a2a',
			__( 'A2A', 'nvoos-content-graph-ai-platform' )
		);

		if ( class_exists( 'NvoosContentGraphAiPlatform\A2A\Admin\A2ADashboardSection' ) ) {
			\NvoosContentGraphAiPlatform\Admin\PlatformSettingsRegistry::register_section(
				new A2ADashboardSection()
			);
		}
	}

	public function registerContentGraphSections(): void {
		ContentGraphRegistry::register_tab( 'a2a', __( 'A2A', 'nvoos-content-graph-ai-platform' ) );
	}
}
