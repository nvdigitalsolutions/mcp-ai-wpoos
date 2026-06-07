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
		// Register platform agent tabs into the core settings page.
		add_action( 'nvoos_graphify/admin/register_sections', array( $this, 'registerSections' ) );

		// Register standalone admin pages (backward compat with existing CPT).
		add_action( 'admin_menu', array( $this, 'registerMenuPages' ) );
	}

	/**
	 * Register agent tabs and sections into the core's SettingsRegistry.
	 *
	 * Hooked to `nvoos_graphify/admin/register_sections`.
	 *
	 * @return void
	 */
	public function registerSections(): void {
		SettingsRegistry::register_tab( 'agents', __( 'Agents', 'nvoos-graphify-platform' ) );

		// Sections will be registered here as agent admin features
		// are extracted from the base plugin.
		//
		// Example:
		// if ( class_exists( 'NvoosGraphifyPlatform\Agents\Admin\Sections\AgentOverview' ) ) {
		//     SettingsRegistry::register_section( new \NvoosGraphifyPlatform\Agents\Admin\Sections\AgentOverview() );
		// }
	}

	/**
	 * Register standalone admin menu pages for agent management.
	 *
	 * These mirror the existing submenu pages under the
	 * assistant CPT menu (`edit.php?post_type=mcp_ai_assistant`).
	 *
	 * @return void
	 */
	public function registerMenuPages(): void {
		// Backward compatibility: the existing assistant admin pages
		// are loaded by the base plugin. Platform pages will be
		// registered here as extraction progresses.
		//
		// Example extracted pages (future):
		// add_submenu_page(
		//     'edit.php?post_type=mcp_ai_assistant',
		//     __( 'Add Agent', 'nvoos-graphify-platform' ),
		//     __( 'Add Agent', 'nvoos-graphify-platform' ),
		//     'edit_posts',
		//     'nvoos-graphify-platform-add-agent',
		//     array( $this, 'renderAddAgentPage' )
		// );
	}
}
