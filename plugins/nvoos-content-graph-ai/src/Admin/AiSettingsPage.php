<?php
declare(strict_types=1);

namespace NvoosContentGraphAi\Admin;

use NvoosContentGraphAi\Admin\Settings\SettingsRegistry;

/**
 * Registers AI tabs and sections into the core's SettingsRegistry.
 *
 * The AI addon's settings appear as tabs on the main NV Content Graph
 * settings page — no separate menu page. Registration routes through the
 * addon's `SettingsRegistry` facade, which forwards to the parent
 * plugin's registry (consumed, never modified). The public
 * `nvoos_content_graph_ai/register_settings_sections` hook fires first so
 * the platform addon (Wave E-UI) and other ecosystem consumers can
 * register their sections alongside the addon's own.
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
		add_action( 'nvoos_content_graph/admin/register_sections', array( $this, 'registerSections' ) );
	}

	/**
	 * Register AI tabs and sections into the core's SettingsRegistry.
	 *
	 * Hooked to `nvoos_content_graph/admin/register_sections` — fires
	 * during the core's `admin_init`, so AI sections appear as
	 * additional tabs on the NV Content Graph settings page.
	 *
	 * @return void
	 */
	public function registerSections(): void {
		// Platform hook first — ecosystem consumers (the platform addon's
		// Wave E-UI sections) register before the addon's own sections.
		SettingsRegistry::register_from_hook();

		SettingsRegistry::register_tab( 'ai_providers', __( 'AI Providers', 'nvoos-content-graph-ai' ) );
		SettingsRegistry::register_tab( 'ai_chat', __( 'Chat Settings', 'nvoos-content-graph-ai' ) );
		SettingsRegistry::register_tab( 'ai_chat_ui', __( 'Chat Tester', 'nvoos-content-graph-ai' ) );

		if ( class_exists( 'NvoosContentGraphAi\Admin\Sections\ProviderSelection' ) ) {
			SettingsRegistry::register_section( new \NvoosContentGraphAi\Admin\Sections\ProviderSelection() );
		}
		if ( class_exists( 'NvoosContentGraphAi\Admin\Sections\ApiKeys' ) ) {
			SettingsRegistry::register_section( new \NvoosContentGraphAi\Admin\Sections\ApiKeys() );
		}
		if ( class_exists( 'NvoosContentGraphAi\Admin\Sections\ChatSettings' ) ) {
			SettingsRegistry::register_section( new \NvoosContentGraphAi\Admin\Sections\ChatSettings() );
		}
		if ( class_exists( 'NvoosContentGraphAi\Admin\Sections\ChatInterface' ) ) {
			SettingsRegistry::register_section( new \NvoosContentGraphAi\Admin\Sections\ChatInterface() );
		}
	}
}
