<?php
/**
 * Settings accessor for the unified `nvoos_graphify_settings` option.
 *
 * @since   1.0.0
 * @package NvoosGraphify
 */

declare(strict_types=1);

namespace NvoosGraphify;

/**
 * Static settings accessor.
 *
 * @since 1.0.0
 */
final class Settings
{
    /**
     * Get all settings.
     *
     * @since 1.0.0
     * @return array<string,mixed>
     */
    public static function all(): array
    {
        $settings = get_option( Schema::OPTION_SETTINGS, array() );

        if ( ! is_array( $settings ) ) {
            $settings = array();
        }

        return array_merge( Schema::defaultSettings(), $settings );
    }

    /**
     * Get a single setting value.
     *
     * @since 1.0.0
     * @param string $key     Setting key.
     * @param mixed  $default Default value if not set.
     * @return mixed
     */
    public static function get( string $key, mixed $default = null ): mixed
    {
        $settings = self::all();
        return $settings[ $key ] ?? $default;
    }

    /**
     * Update the settings.
     *
     * @since 1.0.0
     * @param array<string,mixed> $settings New settings to merge.
     * @return bool True on success.
     */
    public static function update( array $settings ): bool
    {
        $current = self::all();
        $merged  = array_merge( $current, $settings );

        $updated = update_option( Schema::OPTION_SETTINGS, $merged, false );

        if ( $updated ) {
            /**
             * Fires after settings are saved.
             *
             * @since 1.0.0
             * @param array<string,mixed> $merged The merged settings.
             */
            do_action( Schema::ACTION_SETTINGS_SAVED, $merged );
        }

        return $updated;
    }
}
