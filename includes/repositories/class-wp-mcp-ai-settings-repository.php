<?php
/**
 * Settings Repository
 *
 * Handles database operations for plugin settings.
 * Part of Phase 4 refactoring (Milestone 9 - Repository Pattern).
 *
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings Repository class
 *
 * Responsible for:
 * - Plugin settings storage and retrieval
 * - Settings validation
 * - Settings caching
 * - Settings migration
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Settings_Repository {

	/**
	 * Settings prefix
	 *
	 * @var string
	 */
	private $prefix = 'wp_mcp_ai_';

	/**
	 * Settings cache
	 *
	 * @var array
	 */
	private $cache = array();

	/**
	 * Get setting value
	 *
	 * @param string $key     Setting key (without prefix).
	 * @param mixed  $default Default value if setting doesn't exist.
	 * @return mixed Setting value or default.
	 */
	public function get( $key, $default = false ) {
		$option_name = $this->prefix_key( $key );

		// Check cache first.
		if ( isset( $this->cache[ $option_name ] ) ) {
			return $this->cache[ $option_name ];
		}

		$value = get_option( $option_name, $default );

		// Cache the value.
		$this->cache[ $option_name ] = $value;

		return $value;
	}

	/**
	 * Update setting value
	 *
	 * @param string $key   Setting key (without prefix).
	 * @param mixed  $value Setting value.
	 * @return bool True on success, false on failure.
	 */
	public function update( $key, $value ) {
		$option_name = $this->prefix_key( $key );

		$updated = update_option( $option_name, $value );

		// Update cache.
		if ( $updated ) {
			$this->cache[ $option_name ] = $value;
		}

		return $updated;
	}

	/**
	 * Delete setting
	 *
	 * @param string $key Setting key (without prefix).
	 * @return bool True on success, false on failure.
	 */
	public function delete( $key ) {
		$option_name = $this->prefix_key( $key );

		$deleted = delete_option( $option_name );

		// Remove from cache.
		if ( $deleted ) {
			unset( $this->cache[ $option_name ] );
		}

		return $deleted;
	}

	/**
	 * Get all plugin settings
	 *
	 * @return array Associative array of settings (with prefixes stripped).
	 */
	public function get_all() {
		global $wpdb;

		$query = $wpdb->prepare(
			"SELECT option_name, option_value 
			FROM {$wpdb->options} 
			WHERE option_name LIKE %s",
			$wpdb->esc_like( $this->prefix ) . '%'
		);

		$results  = $wpdb->get_results( $query );
		$settings = array();

		foreach ( $results as $result ) {
			$key              = str_replace( $this->prefix, '', $result->option_name );
			$settings[ $key ] = maybe_unserialize( $result->option_value );
		}

		return $settings;
	}

	/**
	 * Update multiple settings at once
	 *
	 * @param array $settings Associative array of settings (keys without prefix).
	 * @return bool True on success, false on failure.
	 */
	public function update_many( $settings ) {
		if ( ! is_array( $settings ) ) {
			return false;
		}

		$success = true;

		foreach ( $settings as $key => $value ) {
			if ( ! $this->update( $key, $value ) ) {
				$success = false;
			}
		}

		return $success;
	}

	/**
	 * Check if setting exists
	 *
	 * @param string $key Setting key (without prefix).
	 * @return bool True if exists, false otherwise.
	 */
	public function exists( $key ) {
		$option_name = $this->prefix_key( $key );
		return false !== get_option( $option_name );
	}

	/**
	 * Get setting with type casting
	 *
	 * @param string $key     Setting key.
	 * @param string $type    Type to cast to (string, int, bool, array).
	 * @param mixed  $default Default value.
	 * @return mixed Casted value.
	 */
	public function get_typed( $key, $type, $default = null ) {
		$value = $this->get( $key, $default );

		switch ( $type ) {
			case 'string':
				return (string) $value;

			case 'int':
			case 'integer':
				return (int) $value;

			case 'bool':
			case 'boolean':
				return (bool) $value;

			case 'array':
				return is_array( $value ) ? $value : array();

			case 'float':
			case 'double':
				return (float) $value;

			default:
				return $value;
		}
	}

	/**
	 * Get provider settings
	 *
	 * @param string $provider Provider name (openai, gemini, ollama, etc.).
	 * @return array Provider settings.
	 */
	public function get_provider_settings( $provider ) {
		$all_settings      = $this->get_all();
		$provider_settings = array();

		$provider_prefix = $provider . '_';

		foreach ( $all_settings as $key => $value ) {
			if ( 0 === strpos( $key, $provider_prefix ) ) {
				$provider_key                       = str_replace( $provider_prefix, '', $key );
				$provider_settings[ $provider_key ] = $value;
			}
		}

		return $provider_settings;
	}

	/**
	 * Update provider settings
	 *
	 * @param string $provider Provider name.
	 * @param array  $settings Provider settings.
	 * @return bool True on success, false on failure.
	 */
	public function update_provider_settings( $provider, $settings ) {
		if ( ! is_array( $settings ) ) {
			return false;
		}

		$prefixed_settings = array();

		foreach ( $settings as $key => $value ) {
			$prefixed_settings[ $provider . '_' . $key ] = $value;
		}

		return $this->update_many( $prefixed_settings );
	}

	/**
	 * Export settings to array
	 *
	 * @param array $keys Optional array of specific keys to export.
	 * @return array Exported settings.
	 */
	public function export( $keys = array() ) {
		if ( empty( $keys ) ) {
			return $this->get_all();
		}

		$exported = array();

		foreach ( $keys as $key ) {
			if ( $this->exists( $key ) ) {
				$exported[ $key ] = $this->get( $key );
			}
		}

		return $exported;
	}

	/**
	 * Import settings from array
	 *
	 * @param array $settings Settings to import.
	 * @param bool  $overwrite Whether to overwrite existing settings.
	 * @return int Number of settings imported.
	 */
	public function import( $settings, $overwrite = false ) {
		if ( ! is_array( $settings ) ) {
			return 0;
		}

		$imported = 0;

		foreach ( $settings as $key => $value ) {
			// Skip if exists and not overwriting.
			if ( ! $overwrite && $this->exists( $key ) ) {
				continue;
			}

			if ( $this->update( $key, $value ) ) {
				++$imported;
			}
		}

		return $imported;
	}

	/**
	 * Clear settings cache
	 */
	public function clear_cache() {
		$this->cache = array();
	}

	/**
	 * Delete all plugin settings
	 *
	 * @return int Number of settings deleted.
	 */
	public function delete_all() {
		global $wpdb;

		$query = $wpdb->prepare(
			"DELETE FROM {$wpdb->options} 
			WHERE option_name LIKE %s",
			$wpdb->esc_like( $this->prefix ) . '%'
		);

		$deleted = $wpdb->query( $query );

		// Clear cache.
		$this->clear_cache();

		return $deleted;
	}

	/**
	 * Get setting with fallback chain
	 *
	 * @param array $keys     Array of keys to try in order.
	 * @param mixed $default  Default value if none found.
	 * @return mixed First found value or default.
	 */
	public function get_with_fallback( $keys, $default = null ) {
		if ( ! is_array( $keys ) ) {
			$keys = array( $keys );
		}

		foreach ( $keys as $key ) {
			$value = $this->get( $key );
			if ( false !== $value && '' !== $value ) {
				return $value;
			}
		}

		return $default;
	}

	/**
	 * Prefix setting key
	 *
	 * @param string $key Key without prefix.
	 * @return string Prefixed key.
	 */
	private function prefix_key( $key ) {
		// Don't add prefix if already prefixed.
		if ( 0 === strpos( $key, $this->prefix ) ) {
			return $key;
		}

		return $this->prefix . $key;
	}
}
