<?php
/**
 * Agent admin pages — registers admin menu pages and settings sections.
 *
 * Wires agent management into both the NV Platform dashboard (primary)
 * and the NV Content Graph dashboard (courtesy).  Submenu pages (Add, Build,
 * Test, Create) are registered directly on the `mcp_ai_assistant` CPT
 * menu, which appears as a top-level menu inherited from the base plugin.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\Agents\Admin
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Agents\Admin;

use NvoosContentGraph\Admin\SettingsRegistry as ContentGraphRegistry;

/**
 * Agent management admin UI — wires all extracted admin pages.
 */
final class AgentsAdmin {

	/**
	 * Register all hooks.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register(): void {
		// Primary: NV Platform dashboard section.
		add_action( 'nvoos_content_graph_ai_platform_admin_register_sections', array( $this, 'registerPlatformSections' ) );

		// Courtesy: also appear under NV Content Graph.
		add_action( 'nvoos_content_graph/admin/register_sections', array( $this, 'registerContentGraphSections' ) );

		// Agent submenu pages (Add, Build, Test, Create).
		add_action( 'admin_menu', array( $this, 'registerMenuPages' ) );
	}

	/**
	 * Register the Agents section in the NV Platform dashboard.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerPlatformSections(): void {
		\NvoosContentGraphAiPlatform\Admin\PlatformSettingsRegistry::register_tab(
			'agents',
			__( 'Agents', 'nvoos-content-graph-ai-platform' )
		);

		if ( class_exists( 'NvoosContentGraphAiPlatform\Agents\Admin\AgentsDashboardSection' ) ) {
			\NvoosContentGraphAiPlatform\Admin\PlatformSettingsRegistry::register_section(
				new AgentsDashboardSection()
			);
		}
	}

	/**
	 * Register the Agents tab in the NV Content Graph dashboard (courtesy).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerContentGraphSections(): void {
		ContentGraphRegistry::register_tab( 'agents', __( 'Agents', 'nvoos-content-graph-ai-platform' ) );
	}

	/**
	 * Register agent submenu pages.
	 *
	 * These attach to the `mcp_ai_assistant` CPT menu inherited from
	 * the base plugin, which appears as a top-level "AI Assistants" menu.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerMenuPages(): void {
		if ( class_exists( 'NvoosContentGraphAiPlatform\Agents\Admin\AddAgentPage' ) ) {
			( new AddAgentPage() )->register();
		}
		if ( class_exists( 'NvoosContentGraphAiPlatform\Agents\Admin\CreateAgentButton' ) ) {
			( new CreateAgentButton() )->register();
		}
		if ( class_exists( 'NvoosContentGraphAiPlatform\Agents\Admin\BuildAgentPage' ) ) {
			( new BuildAgentPage() )->register();
		}
		if ( class_exists( 'NvoosContentGraphAiPlatform\Agents\Admin\TestAgentPage' ) ) {
			( new TestAgentPage() )->register();
		}
	}
}
