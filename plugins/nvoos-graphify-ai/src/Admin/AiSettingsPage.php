<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Admin;

use NvoosGraphify\Admin\SettingsRegistry;

/**
 * Registers AI tabs and sections into the core's SettingsRegistry.
 *
 * The AI addon's settings appear as tabs on the main NV Graphify
 * settings page — no separate menu page. Uses the same
 * Section/Registry pattern as the core.
 *
 * @since 1.0.0
 */
final class AiSettingsPage {

	/**
	 * Register the hook that injects AI sections.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'nvoos_graphify/admin/register_sections', array( $this, 'registerSections' ) );
	}

	/**
	 * Register AI tabs and sections into the core's SettingsRegistry.
	 *
	 * Hooked to `nvoos_graphify/admin/register_sections` — fires
	 * during the core's `admin_init`, so AI sections appear as
	 * additional tabs on the NV Graphify settings page.
	 *
	 * @return void
	 */
	public function registerSections(): void {
		SettingsRegistry::register_tab( 'ai_providers', __( 'AI Providers', 'nvoos-graphify-ai' ) );
		SettingsRegistry::register_tab( 'ai_chat', __( 'Chat Settings', 'nvoos-graphify-ai' ) );
		SettingsRegistry::register_tab( 'ai_chat_ui', __( 'Chat Tester', 'nvoos-graphify-ai' ) );

		if ( class_exists( 'NvoosGraphifyAi\Admin\Sections\ProviderSelection' ) ) {
			SettingsRegistry::register_section( new \NvoosGraphifyAi\Admin\Sections\ProviderSelection() );
		}
		if ( class_exists( 'NvoosGraphifyAi\Admin\Sections\ApiKeys' ) ) {
			SettingsRegistry::register_section( new \NvoosGraphifyAi\Admin\Sections\ApiKeys() );
		}
		if ( class_exists( 'NvoosGraphifyAi\Admin\Sections\ChatSettings' ) ) {
			SettingsRegistry::register_section( new \NvoosGraphifyAi\Admin\Sections\ChatSettings() );
		}
		if ( class_exists( 'NvoosGraphifyAi\Admin\Sections\ChatInterface' ) ) {
			SettingsRegistry::register_section( new \NvoosGraphifyAi\Admin\Sections\ChatInterface() );
		}
	}
}
