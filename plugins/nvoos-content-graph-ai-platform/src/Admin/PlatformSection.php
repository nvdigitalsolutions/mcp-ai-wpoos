<?php
/**
 * Abstract base class for NV Platform settings sections.
 *
 * Extends the Content Graph core Section to reuse its field rendering,
 * sanitization, and render_wrapper() logic, while targeting the
 * Platform's own option name (`ai_platform_settings`).
 *
 * Every tab on the "NV Platform" dashboard is composed of one or
 * more PlatformSection instances. Subsystem admin classes extend
 * this and register via {@see PlatformSettingsRegistry::register_section()}.
 *
 * @since 1.0.0
 * @package NvoosContentGraphAiPlatform\Admin
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Admin;

/**
 * Abstract section base for the NV Platform dashboard.
 *
 * Inherits: get_id(), get_title(), get_tab(), get_fields(),
 * render(), render_field(), render_wrapper(), sanitize(),
 * sanitize_field_value(), get_description(), get_priority().
 */
abstract class PlatformSection extends \NvoosContentGraph\Admin\Section {

	/**
	 * Get the Platform's option name.
	 *
	 * Override in subclasses that need a different storage key.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	protected function get_option_name(): string {
		return PlatformDashboard::OPTION_NAME;
	}

	/**
	 * Get all Platform settings (merged with defaults).
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,mixed>
	 */
	protected function get_settings(): array {
		$option   = get_option( $this->get_option_name(), array() );
		$defaults = \NvoosContentGraphAiPlatform\Schema\Defaults::platformSettings();

		if ( ! is_array( $option ) ) {
			$option = array();
		}

		return array_merge( $defaults, $option );
	}

	/**
	 * Get a single setting value with fallback to default.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $fallback Fallback value.
	 * @return mixed
	 */
	protected function get_setting( string $key, $fallback = null ) {
		$settings = $this->get_settings();
		return $settings[ $key ] ?? $fallback;
	}
}
