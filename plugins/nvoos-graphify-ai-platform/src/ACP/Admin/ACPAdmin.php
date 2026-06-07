<?php
declare(strict_types=1);

namespace NvoosGraphifyAiPlatform\ACP\Admin;

final class ACPAdmin {
	public function register(): void {
		add_action( 'nvoos_graphify/admin/register_sections', array( $this, 'registerSections' ) );
	}
	public function registerSections(): void {
		\NvoosGraphify\Admin\SettingsRegistry::register_tab( 'acp', __( 'ACP', 'nvoos-graphify-ai-platform' ) );
	}
}
