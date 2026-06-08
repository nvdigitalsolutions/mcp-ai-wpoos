<?php
/**
 * Slash Commands admin — dual registration in Platform + Graphify.
 *
 * @since 1.0.0
 * @package NvoosGraphifyAiPlatform\SlashCommands\Admin
 */

declare(strict_types=1);

namespace NvoosGraphifyAiPlatform\SlashCommands\Admin;

use NvoosGraphify\Admin\SettingsRegistry as GraphifyRegistry;

final class SlashCommandsAdmin {

	public function register(): void {
		add_action( 'ai_platform/admin/register_sections', array( $this, 'registerPlatformSections' ) );
		add_action( 'nvoos_graphify/admin/register_sections', array( $this, 'registerGraphifySections' ) );
	}

	public function registerPlatformSections(): void {
		\NvoosGraphifyAiPlatform\Admin\PlatformSettingsRegistry::register_tab(
			'slash_commands',
			__( 'Slash Commands', 'nvoos-graphify-ai-platform' )
		);

		if ( class_exists( 'NvoosGraphifyAiPlatform\SlashCommands\Admin\SlashCommandsDashboardSection' ) ) {
			\NvoosGraphifyAiPlatform\Admin\PlatformSettingsRegistry::register_section(
				new SlashCommandsDashboardSection()
			);
		}
	}

	public function registerGraphifySections(): void {
		GraphifyRegistry::register_tab( 'slash_commands', __( 'Slash Commands', 'nvoos-graphify-ai-platform' ) );
	}
}
