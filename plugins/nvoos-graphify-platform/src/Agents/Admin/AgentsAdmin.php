<?php
/**
 * Agent admin pages — registers admin menu pages and settings sections.
 *
 * Extracted from the base plugin's:
 * - `includes/admin/class-wp-mcp-ai-add-assistant-page.php`
 * - `includes/admin/class-wp-mcp-ai-build-assistant-page.php`
 * - `includes/admin/class-wp-mcp-ai-admin-test-assistant.php`
 * - `includes/admin/class-wp-mcp-ai-admin-create-assistant-button.php`
 *
 * Uses the core's SettingsRegistry pattern for tab/section injection.
 *
 * @since 1.0.0
 * @package NvoosGraphifyPlatform\Agents\Admin
 */

declare(strict_types=1);

namespace NvoosGraphifyPlatform\Agents\Admin;

use NvoosGraphify\Admin\SettingsRegistry;

/**
 * Agent management admin UI.
 */
final class AgentsAdmin {

	/**
	 * Register hooks for agent admin pages.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'nvoos_graphify/admin/register_sections', array( $this, 'registerSections' ) );
		add_action( 'admin_menu', array( $this, 'registerMenuPages' ) );
	}

	/**
	 * Register agent tabs and sections into the core's SettingsRegistry.
	 *
	 * @return void
	 */
	public function registerSections(): void {
		SettingsRegistry::register_tab( 'agents', __( 'Agents', 'nvoos-graphify-platform' ) );
	}

	/**
	 * Register standalone admin menu pages for agent management.
	 *
	 * @return void
	 */
	public function registerMenuPages(): void {
		if ( class_exists( 'NvoosGraphifyPlatform\Agents\Admin\AddAgentPage' ) ) {
			( new AddAgentPage() )->register();
		}
		if ( class_exists( 'NvoosGraphifyPlatform\Agents\Admin\CreateAgentButton' ) ) {
			( new CreateAgentButton() )->register();
		}
	}
}
