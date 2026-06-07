<?php
declare(strict_types=1);

namespace NvoosGraphifyAiPlatform\SlashCommands\Admin;

use NvoosGraphify\Admin\SettingsRegistry;

final class SlashCommandsAdmin {

	public function register(): void {
		add_action( 'nvoos_graphify/admin/register_sections', array( $this, 'registerSections' ) );
	}

	public function registerSections(): void {
		SettingsRegistry::register_tab( 'slash_commands', __( 'Slash Commands', 'nvoos-graphify-ai-platform' ) );
	}
}
