<?php
/**
 * Federation Export Provider.
 *
 * Exports and imports federation peer configurations and MCP server
 * connections for distributed AI mesh topologies.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export/import provider for federation and MCP connection data.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Export_Provider_Federation extends WP_MCP_AI_Export_Provider_Base {

	/**
	 * Settings option key for federation and MCP data.
	 *
	 * @since 1.2.0
	 *
	 * @var string
	 */
	const OPTION_NAME = 'wp_mcp_ai_settings';

	/**
	 * Known federation-related top-level keys within the settings option.
	 *
	 * @since 1.2.0
	 *
	 * @var string[]
	 */
	const FEDERATION_KEYS = array(
		'federation_peers',
		'mcp_connections',
	);

	/**
	 * Get the unique provider identifier.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'federation';
	}

	/**
	 * Get the human-readable label.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Federation & MCP', 'mcp-ai-wpoos' );
	}

	/**
	 * Get the description for the UI.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_description(): string {
		return __(
			'Federation peer configurations and MCP server connections for distributed AI mesh.',
			'mcp-ai-wpoos'
		);
	}

	/**
	 * Whether this provider is available on the current site.
	 *
	 * Always available — the settings key is read dynamically and
	 * empty arrays are returned when federation is not configured.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * Whether exported data contains sensitive values.
	 *
	 * Federation peer configurations and MCP connections do not
	 * store raw credentials directly.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function contains_sensitive_data(): bool {
		return false;
	}

	/**
	 * Count of federation peers and MCP connections.
	 *
	 * Reads settings to determine how many peers and connections
	 * are currently configured.
	 *
	 * @since 1.2.0
	 *
	 * @return int
	 */
	public function get_count(): int {
		$settings         = $this->get_option_safe( self::OPTION_NAME, array() );
		$federation_peers = isset( $settings['federation_peers'] ) && is_array( $settings['federation_peers'] )
			? count( $settings['federation_peers'] )
			: 0;
		$mcp_connections  = isset( $settings['mcp_connections'] ) && is_array( $settings['mcp_connections'] )
			? count( $settings['mcp_connections'] )
			: 0;

		return $federation_peers + $mcp_connections;
	}

	/**
	 * Export federation peers and MCP connection data.
	 *
	 * Reads the settings option and returns only the federation-related
	 * keys ('federation_peers' and 'mcp_connections').
	 *
	 * @since 1.2.0
	 *
	 * @return array
	 */
	public function export(): array {
		$settings = $this->get_option_safe( self::OPTION_NAME, array() );

		$exported = array();

		foreach ( self::FEDERATION_KEYS as $key ) {
			$exported[ $key ] = isset( $settings[ $key ] ) ? $settings[ $key ] : array();
		}

		return $exported;
	}

	/**
	 * Validate import data before committing.
	 *
	 * Checks that expected keys are present and that federation_peers
	 * and mcp_connections values are arrays.
	 *
	 * @since 1.2.0
	 *
	 * @param array $data The data section for this provider from the JSON.
	 * @return true|\WP_Error True if valid, WP_Error with specific failures.
	 */
	public function validate( array $data ) {
		if ( empty( $data ) ) {
			return new \WP_Error(
				'federation_empty',
				__( 'Federation data is empty.', 'mcp-ai-wpoos' )
			);
		}

		foreach ( self::FEDERATION_KEYS as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				return new \WP_Error(
					'federation_missing_key',
					sprintf(
						/* translators: %s: settings key */
						__( 'Federation data is missing required key "%s".', 'mcp-ai-wpoos' ),
						$key
					)
				);
			}

			if ( ! is_array( $data[ $key ] ) ) {
				return new \WP_Error(
					'federation_invalid_key',
					sprintf(
						/* translators: %s: settings key */
						__( 'Federation key "%s" must be an array.', 'mcp-ai-wpoos' ),
						$key
					)
				);
			}
		}

		$this->log_action( 'validated', true );

		return true;
	}

	/**
	 * Import federation data into the current site.
	 *
	 * Reads existing settings, merges in federation_peers and mcp_connections
	 * from the import data, and saves the combined result. Existing non-federation
	 * settings are preserved intact.
	 *
	 * @since 1.2.0
	 *
	 * @param array $data The data section for this provider from the JSON.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function import( array $data ) {
		if ( empty( $data ) ) {
			return new \WP_Error(
				'federation_empty',
				__( 'No federation data to import.', 'mcp-ai-wpoos' )
			);
		}

		// Read current settings.
		$current_settings = $this->get_option_safe( self::OPTION_NAME, array() );
		if ( ! is_array( $current_settings ) ) {
			$current_settings = array();
		}

		// Merge in federation-related keys from the import data.
		foreach ( self::FEDERATION_KEYS as $key ) {
			if ( isset( $data[ $key ] ) && is_array( $data[ $key ] ) ) {
				$current_settings[ $key ] = $data[ $key ];
			}
		}

		$updated = update_option( self::OPTION_NAME, $current_settings, true );

		if ( false === $updated ) {
			$existing = get_option( self::OPTION_NAME, array() );
			// phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual -- Loose comparison handles type coercion between DB-stored strings and import-supplied ints/bools.
			if ( $current_settings != $existing ) {
				return new \WP_Error(
					'federation_save_failed',
					__( 'Failed to save federation settings.', 'mcp-ai-wpoos' )
				);
			}
		}

		// Clear caches.
		wp_cache_delete( self::OPTION_NAME, 'options' );

		$this->log_action( 'imported', true );

		return true;
	}
}
