<?php
/**
 * Harness admin — dual registration in Platform + Graphify.
 *
 * @since 1.0.0
 * @package NvoosGraphifyAiPlatform\Harness\Admin
 */

declare(strict_types=1);

namespace NvoosGraphifyAiPlatform\Harness\Admin;

use NvoosGraphify\Admin\SettingsRegistry as GraphifyRegistry;

final class HarnessAdmin {

	public function register(): void {
		add_action( 'nvoos_graphify_ai_platform_admin_register_sections', array( $this, 'registerPlatformSections' ) );
		add_action( 'nvoos_graphify/admin/register_sections', array( $this, 'registerGraphifySections' ) );
	}

	public function registerPlatformSections(): void {
		\NvoosGraphifyAiPlatform\Admin\PlatformSettingsRegistry::register_tab(
			'harness',
			__( 'Harness', 'nvoos-graphify-ai-platform' )
		);

		if ( class_exists( 'NvoosGraphifyAiPlatform\Harness\Admin\HarnessDashboardSection' ) ) {
			\NvoosGraphifyAiPlatform\Admin\PlatformSettingsRegistry::register_section(
				new HarnessDashboardSection()
			);
		}
	}

	public function registerGraphifySections(): void {
		GraphifyRegistry::register_tab( 'harness', __( 'Harness', 'nvoos-graphify-ai-platform' ) );
	}
}
