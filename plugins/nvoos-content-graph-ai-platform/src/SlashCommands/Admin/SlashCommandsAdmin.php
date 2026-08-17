<?php
/**
 * Slash Commands admin — dual registration in Platform + Content Graph.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\SlashCommands\Admin
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SlashCommands\Admin;

use NvoosContentGraph\Admin\SettingsRegistry as ContentGraphRegistry;

final class SlashCommandsAdmin {

	public function register(): void {
		add_action( 'nvoos_content_graph_ai_platform_admin_register_sections', array( $this, 'registerPlatformSections' ) );
		add_action( 'nvoos_content_graph/admin/register_sections', array( $this, 'registerContentGraphSections' ) );
	}

	public function registerPlatformSections(): void {
		\NvoosContentGraphAiPlatform\Admin\PlatformSettingsRegistry::register_tab(
			'slash_commands',
			__( 'Slash Commands', 'nvoos-content-graph-ai-platform' )
		);

		if ( class_exists( 'NvoosContentGraphAiPlatform\SlashCommands\Admin\SlashCommandsDashboardSection' ) ) {
			\NvoosContentGraphAiPlatform\Admin\PlatformSettingsRegistry::register_section(
				new SlashCommandsDashboardSection()
			);
		}
	}

	public function registerContentGraphSections(): void {
		ContentGraphRegistry::register_tab( 'slash_commands', __( 'Slash Commands', 'nvoos-content-graph-ai-platform' ) );
	}
}
