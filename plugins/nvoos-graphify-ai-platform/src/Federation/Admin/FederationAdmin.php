<?php
declare(strict_types=1);

namespace NvoosGraphifyAiPlatform\Federation\Admin;

final class FederationAdmin {
	public function register(): void {
		add_action( 'nvoos_graphify/admin/register_sections', array( $this, 'registerSections' ) );
	}
	public function registerSections(): void {
		\NvoosGraphify\Admin\SettingsRegistry::register_tab( 'federation', __( 'Federation', 'nvoos-graphify-ai-platform' ) );
	}
}
