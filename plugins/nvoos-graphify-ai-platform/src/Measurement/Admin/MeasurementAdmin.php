<?php
/**
 * Measurement admin — dual registration in Platform + Graphify.
 *
 * @since 1.0.0
 * @package NvoosGraphifyAiPlatform\Measurement\Admin
 */

declare(strict_types=1);

namespace NvoosGraphifyAiPlatform\Measurement\Admin;

use NvoosGraphify\Admin\SettingsRegistry as GraphifyRegistry;

final class MeasurementAdmin {

	public function register(): void {
		add_action( 'ai_platform/admin/register_sections', array( $this, 'registerPlatformSections' ) );
		add_action( 'nvoos_graphify/admin/register_sections', array( $this, 'registerGraphifySections' ) );
	}

	public function registerPlatformSections(): void {
		\NvoosGraphifyAiPlatform\Admin\PlatformSettingsRegistry::register_tab(
			'measurement',
			__( 'Measurement', 'nvoos-graphify-ai-platform' )
		);

		if ( class_exists( 'NvoosGraphifyAiPlatform\Measurement\Admin\MeasurementDashboardSection' ) ) {
			\NvoosGraphifyAiPlatform\Admin\PlatformSettingsRegistry::register_section(
				new MeasurementDashboardSection()
			);
		}
	}

	public function registerGraphifySections(): void {
		GraphifyRegistry::register_tab( 'measurement', __( 'Measurement', 'nvoos-graphify-ai-platform' ) );
	}
}
