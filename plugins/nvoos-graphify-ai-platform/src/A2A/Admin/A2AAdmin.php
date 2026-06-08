<?php
/**
 * A2A admin — dual registration in Platform + Graphify.
 *
 * @since 1.0.0
 * @package NvoosGraphifyAiPlatform\A2A\Admin
 */

declare(strict_types=1);

namespace NvoosGraphifyAiPlatform\A2A\Admin;

use NvoosGraphify\Admin\SettingsRegistry as GraphifyRegistry;

final class A2AAdmin {

	public function register(): void {
		add_action( 'ai_platform/admin/register_sections', array( $this, 'registerPlatformSections' ) );
		add_action( 'nvoos_graphify/admin/register_sections', array( $this, 'registerGraphifySections' ) );
	}

	public function registerPlatformSections(): void {
		\NvoosGraphifyAiPlatform\Admin\PlatformSettingsRegistry::register_tab(
			'a2a',
			__( 'A2A', 'nvoos-graphify-ai-platform' )
		);

		if ( class_exists( 'NvoosGraphifyAiPlatform\A2A\Admin\A2ADashboardSection' ) ) {
			\NvoosGraphifyAiPlatform\Admin\PlatformSettingsRegistry::register_section(
				new A2ADashboardSection()
			);
		}
	}

	public function registerGraphifySections(): void {
		GraphifyRegistry::register_tab( 'a2a', __( 'A2A', 'nvoos-graphify-ai-platform' ) );
	}
}
