<?php
/**
 * ACP admin — dual registration in Platform + Graphify.
 *
 * @since 1.0.0
 * @package NvoosGraphifyAiPlatform\ACP\Admin
 */

declare(strict_types=1);

namespace NvoosGraphifyAiPlatform\ACP\Admin;

use NvoosGraphify\Admin\SettingsRegistry as GraphifyRegistry;

final class ACPAdmin {

	public function register(): void {
		add_action( 'nvoos_graphify_ai_platform_admin_register_sections', array( $this, 'registerPlatformSections' ) );
		add_action( 'nvoos_graphify/admin/register_sections', array( $this, 'registerGraphifySections' ) );
	}

	public function registerPlatformSections(): void {
		\NvoosGraphifyAiPlatform\Admin\PlatformSettingsRegistry::register_tab(
			'acp',
			__( 'ACP', 'nvoos-graphify-ai-platform' )
		);

		if ( class_exists( 'NvoosGraphifyAiPlatform\ACP\Admin\ACPDashboardSection' ) ) {
			\NvoosGraphifyAiPlatform\Admin\PlatformSettingsRegistry::register_section(
				new ACPDashboardSection()
			);
		}
	}

	public function registerGraphifySections(): void {
		GraphifyRegistry::register_tab( 'acp', __( 'ACP', 'nvoos-graphify-ai-platform' ) );
	}
}
