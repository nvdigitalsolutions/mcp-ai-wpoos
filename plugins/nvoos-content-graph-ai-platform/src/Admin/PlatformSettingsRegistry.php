<?php
/**
 * Platform Settings Registry — static registry for tabs and sections.
 *
 * Owned by the NV Platform addon (NOT the Content Graph core registry).
 * Subsystem sections register themselves here so they render under
 * the "NV Platform" top-level menu rather than under "NV Content Graph".
 *
 * Pattern mirrored from NvoosContentGraph\Admin\SettingsRegistry.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\Admin
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Admin;

/**
 * Static registry for the NV Platform tabbed dashboard.
 */
final class PlatformSettingsRegistry {

	/**
	 * Registered tabs.
	 *
	 * @var array<string, array{id: string, label: string}>
	 */
	private static array $tabs = array();

	/**
	 * Registered sections, keyed by section ID.
	 *
	 * @var array<string, PlatformSection>
	 */
	private static array $sections = array();

	/**
	 * Register a tab.
	 *
	 * @param string $id    Tab slug.
	 * @param string $label Tab label (already translated).
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
	 * @param PlatformSection $section The section instance.
	 * @return void
	 */
	public static function register_section( PlatformSection $section ): void {
		self::$sections[ $section->get_id() ] = $section;

		// Ensure the section's tab is also registered.
		$tab_id = $section->get_tab();
		if ( ! isset( self::$tabs[ $tab_id ] ) ) {
			self::$tabs[ $tab_id ] = array(
				'id'    => $tab_id,
				'label' => ucfirst( $tab_id ),
			);
		}
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
	 * @return PlatformSection[]
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
			static function ( PlatformSection $a, PlatformSection $b ): int {
				return $a->get_priority() <=> $b->get_priority();
			}
		);

		return $result;
	}

	/**
	 * Get all registered sections (unordered).
	 *
	 * @return PlatformSection[]
	 */
	public static function get_all_sections(): array {
		return array_values( self::$sections );
	}

	/** Private constructor — not instantiable. */
	private function __construct() {}
}
