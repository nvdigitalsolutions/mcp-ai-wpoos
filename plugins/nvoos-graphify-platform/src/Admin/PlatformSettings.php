<?php
declare(strict_types=1);

namespace NvoosGraphifyPlatform\Admin;

use NvoosGraphify\Admin\SettingsRegistry;

final class PlatformSettings {

	public function register(): void {
		add_action( 'nvoos_graphify/admin/register_sections', array( $this, 'registerSections' ) );
	}

	public function registerSections(): void {
		$tabs = array(
			'agents'          => __( 'Agents', 'nvoos-graphify-platform' ),
			'skills'          => __( 'Skills', 'nvoos-graphify-platform' ),
			'slash_commands'  => __( 'Slash Commands', 'nvoos-graphify-platform' ),
			'harness'         => __( 'Harness', 'nvoos-graphify-platform' ),
			'measurement'     => __( 'Measurement', 'nvoos-graphify-platform' ),
			'professions'     => __( 'Professions', 'nvoos-graphify-platform' ),
			'a2a'             => __( 'A2A', 'nvoos-graphify-platform' ),
			'acp'             => __( 'ACP', 'nvoos-graphify-platform' ),
			'federation'      => __( 'Federation', 'nvoos-graphify-platform' ),
			'blueprints'      => __( 'Blueprints', 'nvoos-graphify-platform' ),
		);

		foreach ( $tabs as $slug => $label ) {
			SettingsRegistry::register_tab( $slug, $label );
		}
	}
}
