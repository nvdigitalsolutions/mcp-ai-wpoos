<?php
declare(strict_types=1);

namespace NvoosGraphifyAiPlatform\Admin;

use NvoosGraphify\Admin\SettingsRegistry;

final class PlatformSettings {

	public function register(): void {
		add_action( 'nvoos_graphify/admin/register_sections', array( $this, 'registerSections' ) );
	}

	public function registerSections(): void {
		$tabs = array(
			'agents'          => __( 'Agents', 'nvoos-graphify-ai-platform' ),
			'skills'          => __( 'Skills', 'nvoos-graphify-ai-platform' ),
			'slash_commands'  => __( 'Slash Commands', 'nvoos-graphify-ai-platform' ),
			'harness'         => __( 'Harness', 'nvoos-graphify-ai-platform' ),
			'measurement'     => __( 'Measurement', 'nvoos-graphify-ai-platform' ),
			'professions'     => __( 'Professions', 'nvoos-graphify-ai-platform' ),
			'a2a'             => __( 'A2A', 'nvoos-graphify-ai-platform' ),
			'acp'             => __( 'ACP', 'nvoos-graphify-ai-platform' ),
			'federation'      => __( 'Federation', 'nvoos-graphify-ai-platform' ),
			'blueprints'      => __( 'Blueprints', 'nvoos-graphify-ai-platform' ),
		);

		foreach ( $tabs as $slug => $label ) {
			SettingsRegistry::register_tab( $slug, $label );
		}
	}
}
