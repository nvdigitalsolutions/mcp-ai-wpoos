<?php
/**
 * Agent admin pages — registers admin menu pages and settings sections.
 *
 * @since 1.0.0
 * @package NvoosGraphifyPlatform\Agents\Admin
 */

declare(strict_types=1);

namespace NvoosGraphifyPlatform\Agents\Admin;

use NvoosGraphify\Admin\SettingsRegistry;

/**
 * Agent management admin UI — wires all extracted admin pages.
 */
final class AgentsAdmin {

	public function register(): void {
		add_action( 'nvoos_graphify/admin/register_sections', array( $this, 'registerSections' ) );
		add_action( 'admin_menu', array( $this, 'registerMenuPages' ) );
	}

	public function registerSections(): void {
		SettingsRegistry::register_tab( 'agents', __( 'Agents', 'nvoos-graphify-platform' ) );
	}

	public function registerMenuPages(): void {
		if ( class_exists( 'NvoosGraphifyPlatform\Agents\Admin\AddAgentPage' ) ) {
			( new AddAgentPage() )->register();
		}
		if ( class_exists( 'NvoosGraphifyPlatform\Agents\Admin\CreateAgentButton' ) ) {
			( new CreateAgentButton() )->register();
		}
		if ( class_exists( 'NvoosGraphifyPlatform\Agents\Admin\BuildAgentPage' ) ) {
			( new BuildAgentPage() )->register();
		}
		if ( class_exists( 'NvoosGraphifyPlatform\Agents\Admin\TestAgentPage' ) ) {
			( new TestAgentPage() )->register();
		}
	}
}
