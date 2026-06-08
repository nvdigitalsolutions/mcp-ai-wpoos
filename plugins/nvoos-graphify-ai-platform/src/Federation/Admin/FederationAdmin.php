<?php
/**
 * Federation admin — dual registration in Platform + Graphify.
 *
 * @since 1.0.0
 * @package NvoosGraphifyAiPlatform\Federation\Admin
 */

declare(strict_types=1);

namespace NvoosGraphifyAiPlatform\Federation\Admin;

use NvoosGraphify\Admin\SettingsRegistry as GraphifyRegistry;

final class FederationAdmin {

	public function register(): void {
		add_action( 'nvoos_graphify_ai_platform_admin_register_sections', array( $this, 'registerPlatformSections' ) );
		add_action( 'nvoos_graphify/admin/register_sections', array( $this, 'registerGraphifySections' ) );
	}

	public function registerPlatformSections(): void {
		\NvoosGraphifyAiPlatform\Admin\PlatformSettingsRegistry::register_tab(
			'federation',
			__( 'Federation', 'nvoos-graphify-ai-platform' )
		);

		if ( class_exists( 'NvoosGraphifyAiPlatform\Federation\Admin\FederationDashboardSection' ) ) {
			\NvoosGraphifyAiPlatform\Admin\PlatformSettingsRegistry::register_section(
				new FederationDashboardSection()
			);
		}
	}

	public function registerGraphifySections(): void {
		GraphifyRegistry::register_tab( 'federation', __( 'Federation', 'nvoos-graphify-ai-platform' ) );
	}
}
