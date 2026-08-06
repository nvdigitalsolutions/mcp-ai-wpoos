<?php
/**
 * Core Settings Export Provider.
 *
 * Handles export and import of all plugin configuration, API keys,
 * provider settings, and main dashboard options.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export provider for core plugin settings.
 *
 * Refactored from WP_MCP_AI_Settings_Dashboard::handle_export_settings()
 * and handle_import_settings() into a self-contained provider that the
 * export manager can orchestrate alongside other data domains.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Export_Provider_Core_Settings extends WP_MCP_AI_Export_Provider_Base {

	/**
	 * Unique provider identifier.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'core_settings';
	}

	/**
	 * Human-readable label for the UI checkbox.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Core Settings', 'mcp-ai-wpoos' );
	}

	/**
	 * Description shown beneath the checkbox in the UI.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __( 'Plugin configuration, API keys, provider settings, and all main dashboard options.', 'mcp-ai-wpoos' );
	}

	/**
	 * Whether this provider is available on the current site.
	 *
	 * Core settings are always available.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Whether the exported data contains sensitive values.
	 *
	 * Core settings include API keys and tokens.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function contains_sensitive_data(): bool {
		return true;
	}

	/**
	 * Approximate count of settings for the UI badge.
	 *
	 * @since 1.2.0
	 *
	 * @return int
	 */
	public function get_count(): int {
		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			return 0;
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();
		return is_array( $settings ) ? count( $settings ) : 0;
	}

	/**
	 * Export all core plugin settings.
	 *
	 * Clears caches before reading to ensure fresh data.
	 * Returns the merged settings array (defaults + saved + credentials,
	 * with decrypted sensitive values) via get_settings().
	 *
	 * @since 1.2.0
	 *
	 * @return array Associative array of core settings.
	 */
	public function export(): array {
		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' )
			|| ! class_exists( 'WP_MCP_AI_Admin_Settings_Base' )
		) {
			return array();
		}

		// Clear caches before export to ensure fresh data.
		WP_MCP_AI_Admin_Settings::reset_settings_cache();
		wp_cache_delete( WP_MCP_AI_Admin_Settings::OPTION_NAME, 'options' );
		wp_cache_delete( WP_MCP_AI_Admin_Settings_Base::CREDENTIALS_OPTION_NAME, 'options' );

		// get_settings() merges defaults, saved settings, and credentials,
		// and decrypts sensitive values — consistent with the Backup & Restore UI.
		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		return is_array( $settings ) ? $settings : array();
	}

	/**
	 * Validate imported core settings data.
	 *
	 * Performs a basic structure check only — the actual sanitization
	 * is handled by the import() method and the settings base class.
	 *
	 * @since 1.2.0
	 *
	 * @param array $data The data section for this provider from the JSON.
	 * @return true|\WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate( array $data ) {
		if ( empty( $data ) ) {
			return new \WP_Error(
				'core_settings_empty',
				__( 'Core settings data is empty.', 'mcp-ai-wpoos' )
			);
		}

		if ( ! is_array( $data ) ) {
			return new \WP_Error(
				'core_settings_invalid',
				__( 'Core settings data is not an array.', 'mcp-ai-wpoos' )
			);
		}

		$this->log_action( 'validated', true );

		return true;
	}

	/**
	 * Import core settings into the current site.
	 *
	 * Splits incoming settings into sensitive (credentials) and non-sensitive
	 * keys using WP_MCP_AI_Admin_Settings_Base::is_sensitive_setting_key().
	 * Merges incoming values with existing settings — existing values take
	 * precedence for keys not explicitly included in the import.
	 * Saves to the appropriate WordPress option for each category.
	 *
	 * @since 1.2.0
	 *
	 * @param array $data The data section for this provider from the JSON.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function import( array $data ) {
		if ( ! class_exists( 'WP_MCP_AI_Admin_Settings' )
			|| ! class_exists( 'WP_MCP_AI_Admin_Settings_Base' )
		) {
			return new \WP_Error(
				'core_settings_missing_class',
				__( 'Required settings classes are not available.', 'mcp-ai-wpoos' )
			);
		}

		// Read current settings from both options.
		$current_settings    = $this->get_option_safe( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
		$current_credentials = $this->get_option_safe( WP_MCP_AI_Admin_Settings_Base::CREDENTIALS_OPTION_NAME, array() );

		if ( ! is_array( $current_settings ) ) {
			$current_settings = array();
		}
		if ( ! is_array( $current_credentials ) ) {
			$current_credentials = array();
		}

		// Split incoming data into sensitive and non-sensitive buckets.
		$import_credentials   = array();
		$import_non_sensitive = array();

		foreach ( $data as $key => $value ) {
			if ( $this->is_sensitive_key( (string) $key ) ) {
				$import_credentials[ $key ] = $value;
			} else {
				$import_non_sensitive[ $key ] = $value;
			}
		}

		// Merge incoming credentials with existing — never wipe.
		// Existing credentials take precedence for keys not in the import file.
		$final_credentials = array_merge( $current_credentials, $import_credentials );

		// Merge non-sensitive settings with existing — never wipe settings
		// that are absent from the import file.
		$final_non_sensitive = array_merge( $current_settings, $import_non_sensitive );

		// Save non-sensitive settings (autoload=true).
		$update_result = update_option(
			WP_MCP_AI_Admin_Settings::OPTION_NAME,
			$final_non_sensitive,
			true
		);

		// Save credentials (autoload=false).
		if ( count( $final_credentials ) > 0 ) {
			update_option(
				WP_MCP_AI_Admin_Settings_Base::CREDENTIALS_OPTION_NAME,
				$final_credentials,
				false
			);
		}

		// update_option() returns false when the new value equals the
		// existing value. This is not an error — it means nothing changed.
		// Only treat as failure when incoming data genuinely differs.
		if ( false === $update_result ) {
			$existing_non_sensitive = get_option( WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
			// phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Loose comparison handles type coercion between DB-stored strings and import-supplied ints/bools.
			if ( $final_non_sensitive != $existing_non_sensitive ) {
				return new \WP_Error(
					'core_settings_save_failed',
					__( 'Failed to save imported settings.', 'mcp-ai-wpoos' )
				);
			}
		}

		// Clear all caches.
		WP_MCP_AI_Admin_Settings::reset_settings_cache();
		wp_cache_delete( WP_MCP_AI_Admin_Settings::OPTION_NAME, 'options' );
		wp_cache_delete( WP_MCP_AI_Admin_Settings_Base::CREDENTIALS_OPTION_NAME, 'options' );
		delete_transient( 'wp_mcp_ai_settings_cache' );

		$this->log_action( 'imported', true );

		return true;
	}
}
