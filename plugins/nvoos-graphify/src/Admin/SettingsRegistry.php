<?php
declare(strict_types=1);

namespace NvoosGraphify\Admin;

/**
 * Static registry for settings tabs and sections.
 *
 * Addons register tabs via {@see register_tab()} and sections via
 * {@see register_section()}. The dashboard renderer queries this
 * registry to build the tabbed UI.
 *
 * Pattern mirrored from the NV oOS base plugin's WP_MCP_AI_Settings_Registry.
 *
 * @since 1.0.0
 */
final class SettingsRegistry {

	/**
	 * Registered tabs.
	 *
	 * @var array<string, array{id: string, label: string}>
	 */
	private static array $tabs = array();

	/**
	 * Registered sections, keyed by section ID.
	 *
	 * @var array<string, Section>
	 */
	private static array $sections = array();

	/**
	 * Register a tab.
	 *
	 * @param string $id    Tab slug.
	 * @param string $label Tab label.
	 * @return void
	 */
	public static function register_tab( string $id, string $label ): void {
		self::$tabs[ $id ] = array(
			'id'    => $id,
			'label' => $label,
		);
	}

	/**
	 * Register a section instance.
	 *
	 * @param Section $section The section instance.
	 * @return void
	 */
	public static function register_section( Section $section ): void {
		self::$sections[ $section->get_id() ] = $section;
	}

	/**
	 * Get all registered tabs in registration order.
	 *
	 * @return array<string, array{id: string, label: string}>
	 */
	public static function get_tabs(): array {
		return self::$tabs;
	}

	/**
	 * Get sections for a specific tab, sorted by priority.
	 *
	 * @param string $tab Tab slug.
	 * @return Section[]
	 */
	public static function get_sections( string $tab ): array {
		$result = array();

		foreach ( self::$sections as $section ) {
			if ( $section->get_tab() === $tab ) {
				$result[] = $section;
			}
		}

		\usort(
			$result,
			static function ( Section $a, Section $b ): int {
				return $a->get_priority() <=> $b->get_priority();
			}
		);

		return $result;
	}

	/** Private constructor — not instantiable. */
	private function __construct() {}
}
