<?php
/**
 * Export Provider: Toolkit Options.
 *
 * Scans the wp_options table for all plugin toolkit-specific settings
 * options matching wp_mcp_ai_*_toolkit_settings and exports/imports them.
 *
 * @package    WP_MCP_AI
 * @subpackage Admin\Export
 * @since      1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports and imports toolkit settings stored in wp_options.
 *
 * Each toolkit in the plugin (EZuite, Flowhub, Shopify Sync, Media,
 * Calendar, Chat Channels, Ecommerce, etc.) stores its configuration
 * under a wp_mcp_ai_{slug}_toolkit_settings option key. This provider
 * discovers all such options and makes them portable between sites.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Export_Provider_Toolkit_Options extends WP_MCP_AI_Export_Provider_Base {

	/**
	 * Unique provider identifier.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'toolkit_options';
	}

	/**
	 * Human-readable label for the UI checkbox.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Toolkit Settings', 'mcp-ai-wpoos' );
	}

	/**
	 * Description shown beneath the checkbox in the UI.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __(
			'Settings for all active toolkits: EZuite, Flowhub, Shopify Sync, Media, Calendar, Chat Channels, Ecommerce, and more.',
			'mcp-ai-wpoos'
		);
	}

	/**
	 * Whether this provider is available on the current site.
	 *
	 * Always available as it operates on the core wp_options table.
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
	 * Toolkit settings may contain connection IDs or endpoint URLs
	 * but do not store raw API keys or credentials directly.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function contains_sensitive_data(): bool {
		return false;
	}

	/**
	 * Count of toolkit option names currently stored in the database.
	 *
	 * @since 1.2.0
	 *
	 * @return int
	 */
	public function get_count(): int {
		global $wpdb;

		$like = $wpdb->esc_like( 'wp_mcp_ai_' ) . '%' . $wpdb->esc_like( '_toolkit_settings' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			)
		);
		// phpcs:enable

		return (int) $count;
	}

	/**
	 * Export all toolkit settings.
	 *
	 * Returns an associative array of option_name => value for every
	 * option matching wp_mcp_ai_*_toolkit_settings in the options table.
	 *
	 * @since 1.2.0
	 *
	 * @return array
	 */
	public function export(): array {
		global $wpdb;

		$like = $wpdb->esc_like( 'wp_mcp_ai_' ) . '%' . $wpdb->esc_like( '_toolkit_settings' );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching
		$option_names = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
				$like
			)
		);
		// phpcs:enable

		if ( empty( $option_names ) ) {
			return array();
		}

		$data = array();
		foreach ( $option_names as $option_name ) {
			$value = $this->get_option_safe( $option_name, array() );
			if ( is_array( $value ) ) {
				// Recursively decrypt any sensitive values within nested arrays.
				$value = $this->decrypt_option_array( $value );
			}
			$data[ $option_name ] = $value;
		}

		return $data;
	}

	/**
	 * Validate the import data before committing.
	 *
	 * Toolkit settings are arbitrary arrays; structural validation
	 * is not performed here. Returns true for any well-formed input.
	 *
	 * @since 1.2.0
	 *
	 * @param array $data The data section for this provider from the JSON.
	 * @return true|\WP_Error True if valid, WP_Error with specific failures.
	 */
	public function validate( array $data ) {
		if ( empty( $data ) ) {
			return true;
		}

		foreach ( $data as $option_name => $value ) {
			// Every key must be a valid option name matching the pattern.
			if ( ! is_string( $option_name ) || '' === $option_name ) {
				return new \WP_Error(
					'wp_mcp_ai_invalid_option_name',
					__( 'Toolkit settings data contains an invalid option name.', 'mcp-ai-wpoos' )
				);
			}

			if ( ! $this->is_toolkit_option_name( $option_name ) ) {
				return new \WP_Error(
					'wp_mcp_ai_unexpected_option_name',
					sprintf(
						/* translators: %s: unexpected option name */
						__( 'Unexpected option name "%s" in toolkit settings data.', 'mcp-ai-wpoos' ),
						$option_name
					)
				);
			}
		}

		return true;
	}

	/**
	 * Import toolkit settings into the current site.
	 *
	 * Each option in the provided data is saved via update_option().
	 * Existing options not present in the import are preserved.
	 *
	 * @since 1.2.0
	 *
	 * @param array $data The data section for this provider from the JSON.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function import( array $data ) {
		if ( empty( $data ) ) {
			return true;
		}

		foreach ( $data as $option_name => $value ) {
			// Only import keys matching the expected pattern.
			if ( ! $this->is_toolkit_option_name( $option_name ) ) {
				continue;
			}

			if ( ! is_array( $value ) ) {
				$value = array();
			}

			$updated = update_option( $option_name, $value, false );

			if ( false === $updated ) {
				// Check whether the value is identical to what is already stored.
				$existing = get_option( $option_name, null );
				if ( $existing !== $value ) {
					return new \WP_Error(
						'wp_mcp_ai_import_failed',
						sprintf(
							/* translators: %s: option name */
							__( 'Failed to import toolkit settings for "%s".', 'mcp-ai-wpoos' ),
							$option_name
						)
					);
				}
			}
		}

		$this->log_action( 'imported', array( 'count' => count( $data ) ) );

		return true;
	}

	/**
	 * Recursively decrypt sensitive values within an option array.
	 *
	 * Walks through a nested settings array and applies maybe_decrypt_value()
	 * to any string leaf that matches a sensitive key pattern.
	 *
	 * @since 1.2.0
	 *
	 * @param array $option The option value array.
	 * @return array The option value with sensitive entries decrypted.
	 */
	private function decrypt_option_array( array $option ): array {
		foreach ( $option as $key => $value ) {
			if ( is_array( $value ) ) {
				$option[ $key ] = $this->decrypt_option_array( $value );
			} elseif ( is_string( $value ) && $this->is_sensitive_key( $key ) ) {
				$option[ $key ] = $this->maybe_decrypt_value( $value );
			}
		}

		return $option;
	}

	/**
	 * Check whether an option name matches the toolkit settings pattern.
	 *
	 * @since 1.2.0
	 *
	 * @param string $option_name The option name to check.
	 * @return bool
	 */
	private function is_toolkit_option_name( string $option_name ): bool {
		return (bool) preg_match( '/^wp_mcp_ai_[a-z0-9_]+_toolkit_settings$/', $option_name );
	}
}
