<?php
declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Admin;

use NvoosContentGraph\Admin\SettingsRegistry;

final class PlatformSettings {

	public function register(): void {
		add_action( 'nvoos_content_graph/admin/register_sections', array( $this, 'registerSections' ) );
	}

	public function registerSections(): void {
		$tabs = array(
			'agents'         => __( 'Agents', 'nvoos-content-graph-ai-platform' ),
			'skills'         => __( 'Skills', 'nvoos-content-graph-ai-platform' ),
			'slash_commands' => __( 'Slash Commands', 'nvoos-content-graph-ai-platform' ),
			'harness'        => __( 'Harness', 'nvoos-content-graph-ai-platform' ),
			'measurement'    => __( 'Measurement', 'nvoos-content-graph-ai-platform' ),
			'professions'    => __( 'Professions', 'nvoos-content-graph-ai-platform' ),
			'a2a'            => __( 'A2A', 'nvoos-content-graph-ai-platform' ),
			'acp'            => __( 'ACP', 'nvoos-content-graph-ai-platform' ),
			'federation'     => __( 'Federation', 'nvoos-content-graph-ai-platform' ),
			'blueprints'     => __( 'Blueprints', 'nvoos-content-graph-ai-platform' ),
		);

		foreach ( $tabs as $slug => $label ) {
			SettingsRegistry::register_tab( $slug, $label );
		}
	}
}
