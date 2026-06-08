<?php
/**
 * Agent admin pages — registers admin menu pages and settings sections.
 *
 * Wires agent management into both the NV Platform dashboard (primary)
 * and the NV Graphify dashboard (courtesy).  Submenu pages (Add, Build,
 * Test, Create) are registered directly on the `mcp_ai_assistant` CPT
 * menu, which appears as a top-level menu inherited from the base plugin.
 *
 * @since 1.0.0
 * @package NvoosGraphifyAiPlatform\Agents\Admin
 */

declare(strict_types=1);

namespace NvoosGraphifyAiPlatform\Agents\Admin;

use NvoosGraphify\Admin\SettingsRegistry as GraphifyRegistry;

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
		add_action( 'ai_platform/admin/register_sections', array( $this, 'registerPlatformSections' ) );

		// Courtesy: also appear under NV Graphify.
		add_action( 'nvoos_graphify/admin/register_sections', array( $this, 'registerGraphifySections' ) );

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
		\NvoosGraphifyAiPlatform\Admin\PlatformSettingsRegistry::register_tab(
			'agents',
			__( 'Agents', 'nvoos-graphify-ai-platform' )
		);

		if ( class_exists( 'NvoosGraphifyAiPlatform\Agents\Admin\AgentsDashboardSection' ) ) {
			\NvoosGraphifyAiPlatform\Admin\PlatformSettingsRegistry::register_section(
				new AgentsDashboardSection()
			);
		}
	}

	/**
	 * Register the Agents tab in the NV Graphify dashboard (courtesy).
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function registerGraphifySections(): void {
		GraphifyRegistry::register_tab( 'agents', __( 'Agents', 'nvoos-graphify-ai-platform' ) );
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
		if ( class_exists( 'NvoosGraphifyAiPlatform\Agents\Admin\AddAgentPage' ) ) {
			( new AddAgentPage() )->register();
		}
		if ( class_exists( 'NvoosGraphifyAiPlatform\Agents\Admin\CreateAgentButton' ) ) {
			( new CreateAgentButton() )->register();
		}
		if ( class_exists( 'NvoosGraphifyAiPlatform\Agents\Admin\BuildAgentPage' ) ) {
			( new BuildAgentPage() )->register();
		}
		if ( class_exists( 'NvoosGraphifyAiPlatform\Agents\Admin\TestAgentPage' ) ) {
			( new TestAgentPage() )->register();
		}
	}
}
