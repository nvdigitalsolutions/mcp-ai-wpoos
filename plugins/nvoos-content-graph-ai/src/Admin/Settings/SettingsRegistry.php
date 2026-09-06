<?php
/**
 * Settings registry facade for the Content Graph AI addon.
 *
 * Port of the base plugin's `WP_MCP_AI_Settings_Registry` registration
 * surface (Wave D-UI-5). The addon does not own a settings page of its
 * own: registration is forwarded to the parent plugin's
 * `NvoosContentGraph\Admin\SettingsRegistry` (the core registry is
 * consumed, never modified). This facade gives the addon a stable,
 * addon-owned entry point so the platform addon (Wave E-UI) can register
 * its sections through the public
 * `nvoos_content_graph_ai/register_settings_sections` hook without
 * depending on parent-plugin internals.
 *
 * @package NvoosContentGraphAi\Admin\Settings
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Admin\Settings;

use NvoosContentGraph\Admin\Section;
use NvoosContentGraph\Admin\SettingsRegistry as CoreSettingsRegistry;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers tabs and sections into the parent plugin's registry.
 *
 * @since 1.1.0
 */
class SettingsRegistry {

	/**
	 * Whether the parent plugin's registry is available.
	 *
	 * @return bool
	 */
	public static function is_available(): bool {
		return class_exists( CoreSettingsRegistry::class );
	}

	/**
	 * Register a tab (forwarded to the parent plugin's registry).
	 *
	 * @param string $id    Tab slug.
	 * @param string $label Tab label.
	 * @return void
	 */
	public static function register_tab( string $id, string $label ): void {
		if ( ! self::is_available() ) {
			return;
		}

		CoreSettingsRegistry::register_tab( $id, $label );
	}

	/**
	 * Register a section instance (forwarded to the parent registry).
	 *
	 * @param Section $section The section instance.
	 * @return void
	 */
	public static function register_section( Section $section ): void {
		if ( ! self::is_available() ) {
			return;
		}

		CoreSettingsRegistry::register_section( $section );
	}

	/**
	 * Get all registered tabs in registration order.
	 *
	 * @return array<string, array{id: string, label: string}>
	 */
	public static function get_tabs(): array {
		if ( ! self::is_available() ) {
			return array();
		}

		return CoreSettingsRegistry::get_tabs();
	}

	/**
	 * Get sections for a specific tab, sorted by priority.
	 *
	 * @param string $tab Tab slug.
	 * @return Section[]
	 */
	public static function get_sections( string $tab ): array {
		if ( ! self::is_available() ) {
			return array();
		}

		return CoreSettingsRegistry::get_sections( $tab );
	}

	/**
	 * Fire the public section-registration hook.
	 *
	 * The platform addon (and other ecosystem consumers) register their
	 * tabs and sections on this hook; the AI addon's own sections
	 * register on the same hook so ordering stays deterministic by
	 * registration order + section priority.
	 *
	 * @return void
	 */
	public static function register_from_hook(): void {
		/**
		 * Fires when the AI addon registers its settings tabs and sections.
		 *
		 * Sections extend {@see Section} (parent contract) or
		 * {@see AiSection} (validated contract) and register via
		 * `SettingsRegistry::register_tab()` / `register_section()`.
		 *
		 * @since 1.1.0
		 */
		do_action( 'nvoos_content_graph_ai/register_settings_sections' );
	}

	/** Private constructor — static facade, not instantiable. */
	private function __construct() {}
}
