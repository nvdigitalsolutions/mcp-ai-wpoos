<?php
/**
 * Remote Sites Export Provider.
 *
 * Exports and imports all remote site connections including their
 * decrypted credentials for portable migration between sites.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Exports/imports remote site connections stored in wp_mcp_ai_pro_remote_sites.
 *
 * On export, credential values are decrypted so the export file is portable.
 * On import, credentials are re-encrypted with the target site's encryption key.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Export_Provider_Remote_Sites extends WP_MCP_AI_Export_Provider_Base {

	/**
	 * Field keys that contain credential data and must be
	 * decrypted on export / re-encrypted on import.
	 *
	 * Used as a fallback when WP_MCP_AI_Pro_Remote_Site_Manager
	 * does not expose an is_credential_field() method.
	 *
	 * @since 1.2.0
	 * @var   array<int, string>
	 */
	const CREDENTIAL_FIELDS = array(
		'api_key',
		'api_secret',
		'client_secret',
		'bot_token',
		'password',
		'access_token',
		'access_token_secret',
		'refresh_token',
		'webhook_secret',
		'private_key',
		'mesh_inbound_api_key',
		'consumer_key',
		'consumer_secret',
		'app_password',
		'auth_code',
		'bearer_token',
		'signing_secret',
		'public_key',
		'encryption_key',
	);

	/**
	 * Unique provider identifier.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_id(): string {
		return 'remote_sites';
	}

	/**
	 * Human-readable label for the UI checkbox.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	public function get_label(): string {
		return __( 'Remote Sites', 'mcp-ai-wpoos-pro' );
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
			'All external service connections: Telegram, Discord, WordPress remotes, WooCommerce, Shopify, Upwork, Gmail, LinkedIn, EZuite ERP, Flowhub, QuickBooks, and more.',
			'mcp-ai-wpoos-pro'
		);
	}

	/**
	 * Whether this provider is available on the current site.
	 *
	 * Requires the Pro Remote Site Manager class to be loaded.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function is_available(): bool {
		return class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' );
	}

	/**
	 * Whether the exported data contains sensitive values.
	 *
	 * @since 1.2.0
	 *
	 * @return bool
	 */
	public function contains_sensitive_data(): bool {
		return true;
	}

	/**
	 * Approximate count of items for the UI badge.
	 *
	 * @since 1.2.0
	 *
	 * @return int
	 */
	public function get_count(): int {
		if ( ! $this->is_available() ) {
			return 0;
		}

		return count( WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections() );
	}

	/**
	 * Export all remote site connections with decrypted credentials.
	 *
	 * @since 1.2.0
	 *
	 * @return array Associative array with 'connections' key containing
	 *               the full connection list with plaintext credentials.
	 */
	public function export(): array {
		if ( ! $this->is_available() ) {
			return array( 'connections' => array() );
		}

		$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		if ( empty( $connections ) ) {
			return array( 'connections' => array() );
		}

		$exported = array();

		foreach ( $connections as $connection_id => $connection ) {
			if ( ! is_array( $connection ) ) {
				continue;
			}

			$decrypted_connection = array();

			foreach ( $connection as $key => $value ) {
				// Skip internal encrypted flags.
				if ( '_' === substr( (string) $key, 0, 1 ) && '_encrypted' === substr( (string) $key, -10 ) ) {
					continue;
				}

				if ( $this->is_credential_field( $key ) && is_string( $value ) && '' !== $value ) {
					$decrypted_connection[ $key ] = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $value );
				} else {
					$decrypted_connection[ $key ] = $value;
				}
			}

			$exported[ $connection_id ] = $decrypted_connection;
		}

		return array( 'connections' => $exported );
	}

	/**
	 * Validate imported data before committing.
	 *
	 * @since 1.2.0
	 *
	 * @param array $data The data section for this provider from the JSON.
	 * @return true|\WP_Error True if valid, WP_Error with specific failures.
	 */
	public function validate( array $data ) {
		if ( ! isset( $data['connections'] ) || ! is_array( $data['connections'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_export_invalid_remote_sites',
				__( 'Remote sites import data is missing the required "connections" array.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $data['connections'] ) ) {
			return true;
		}

		foreach ( $data['connections'] as $index => $connection ) {
			if ( ! is_array( $connection ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_export_invalid_connection',
					sprintf(
						/* translators: %d: connection index */
						__( 'Connection at index %d is not a valid array.', 'mcp-ai-wpoos-pro' ),
						$index
					)
				);
			}

			if ( empty( $connection['connection_type'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_export_missing_connection_type',
					sprintf(
						/* translators: %d: connection index */
						__( 'Connection at index %d is missing the required "connection_type" field.', 'mcp-ai-wpoos-pro' ),
						$index
					)
				);
			}
		}

		return true;
	}

	/**
	 * Import remote site connections, re-encrypting credentials
	 * with the target site's encryption key.
	 *
	 * Merges with existing connections: same ID is overwritten,
	 * new IDs are added.
	 *
	 * @since 1.2.0
	 *
	 * @param array $data The data section for this provider from the JSON.
	 * @return true|\WP_Error True on success, WP_Error on failure.
	 */
	public function import( array $data ) {
		if ( ! $this->is_available() ) {
			return new WP_Error(
				'wp_mcp_ai_pro_export_remote_sites_unavailable',
				__( 'Remote Site Manager is not available on this site.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $data['connections'] ) || ! is_array( $data['connections'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_pro_export_empty_remote_sites',
				__( 'No remote site connections to import.', 'mcp-ai-wpoos-pro' )
			);
		}

		$existing = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$imported = array();

		foreach ( $data['connections'] as $connection_id => $connection ) {
			if ( ! is_array( $connection ) ) {
				continue;
			}

			$reencrypted = array();

			foreach ( $connection as $key => $value ) {
				if ( $this->is_credential_field( $key ) && is_string( $value ) && '' !== $value ) {
					$reencrypted[ $key ] = WP_MCP_AI_Pro_Remote_Site_Manager::encrypt_value( $value );
				} else {
					$reencrypted[ $key ] = $value;
				}
			}

			// Use the connection's ID field as the key, falling back to
			// the array key from the export file.
			$key              = ! empty( $reencrypted['id'] ) ? sanitize_key( $reencrypted['id'] ) : sanitize_key( (string) $connection_id );
			$imported[ $key ] = $reencrypted;
		}

		// Merge: overwrite same ID, add new.
		$merged = array_replace( $existing, $imported );

		$updated = update_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME, $merged, false );

		if ( false === $updated ) {
			// update_option returns false when the value is unchanged or on
			// actual failure. Verify the write by re-reading.
			$saved = get_option( WP_MCP_AI_Pro_Remote_Site_Manager::OPTION_NAME, array() );
			if ( ! is_array( $saved ) || $saved !== $merged ) {
				return new WP_Error(
					'wp_mcp_ai_pro_export_remote_sites_save_failed',
					__( 'Failed to save remote site connections. Please try again.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		$this->log_action( 'imported', 'success' );

		return true;
	}

	/**
	 * Determine whether a field key refers to a credential that
	 * must be decrypted on export and re-encrypted on import.
	 *
	 * Delegates to WP_MCP_AI_Pro_Remote_Site_Manager::is_credential_field()
	 * when available; otherwise falls back to the hardcoded list in
	 * self::CREDENTIAL_FIELDS.
	 *
	 * @since 1.2.0
	 *
	 * @param string $field_key The field key to check.
	 * @return bool
	 */
	private function is_credential_field( string $field_key ): bool {
		if ( method_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager', 'is_credential_field' ) ) {
			return WP_MCP_AI_Pro_Remote_Site_Manager::is_credential_field( $field_key );
		}

		return in_array( $field_key, self::CREDENTIAL_FIELDS, true );
	}
}
