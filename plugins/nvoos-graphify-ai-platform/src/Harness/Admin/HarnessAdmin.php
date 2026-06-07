<?php
declare(strict_types=1);

namespace NvoosGraphifyAiPlatform\Harness\Admin;

final class HarnessAdmin {

	public function register(): void {
		add_action( 'nvoos_graphify/admin/register_sections', array( $this, 'registerSections' ) );
	}

	public function registerSections(): void {
		\NvoosGraphify\Admin\SettingsRegistry::register_tab( 'harness', __( 'Harness', 'nvoos-graphify-ai-platform' ) );
	}
}
