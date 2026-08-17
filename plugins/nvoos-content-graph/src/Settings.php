<?php
declare(strict_types=1);

namespace NvoosContentGraph;

use function apply_filters;
use function get_option;
use function update_option;
use function wp_parse_args;

/**
 * Static accessor for the unified `nvoos_content_graph_settings` option.
 *
 * All plugin settings are stored in a single WordPress option
 * merged with the defaults from {@see Schema::defaultSettings()}.
 * This keeps the wp_options table clean and avoids autoload bloat.
 *
 * @since 1.0.0
 */
final class Settings {

	/**
	 * Retrieve a single setting value.
	 *
	 * @since 1.0.0
	 *
	 * @param string $key     The setting key.
	 * @param mixed  $default Value to return when the key is absent.
	 * @return mixed
	 */
	public static function get( string $key, $default = null ) {
		$all = self::all();
		return $all[ $key ] ?? $default;
	}

	/**
	 * Retrieve the full settings array merged with defaults.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		$stored = get_option( Schema::OPTION_SETTINGS, array() );
		return wp_parse_args( (array) $stored, Schema::defaultSettings() );
	}

	/**
	 * Persist updated settings.
	 *
	 * Accepts a complete replacement array or an associative delta.
	 * When `$complete` is false, the provided values are merged
	 * into the existing stored settings before saving.
	 *
	 * @since 1.0.0
	 *
	 * @param array<string,mixed> $values   The settings values to persist.
	 * @param bool                $complete Whether to replace all settings (true) or merge (false).
	 * @return bool True on success.
	 */
	public static function update( array $values, bool $complete = false ): bool {
		if ( ! $complete ) {
			$stored = get_option( Schema::OPTION_SETTINGS, array() );
			$values = array_merge( (array) $stored, $values );
		}

		$saved = update_option( Schema::OPTION_SETTINGS, $values, false );

		/**
		 * Fires after settings have been saved.
		 *
		 * @since 1.0.0
		 * @param array<string,mixed> $values The settings values that were saved.
		 */
		if ( $saved ) {
			do_action( Schema::ACTION_SETTINGS_SAVED, $values );
		}

		return $saved;
	}

	/** Private constructor — not instantiable. */
	private function __construct() {}
}
