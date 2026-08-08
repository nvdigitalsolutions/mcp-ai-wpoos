<?php
/**
 * Export Manager — Orchestrator for Backup & Restore.
 *
 * Manages registration of export providers and orchestrates
 * export/import operations across all registered providers.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Central orchestrator for export/import operations.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Export_Manager {

	/**
	 * Registered providers keyed by provider ID.
	 *
	 * @var WP_MCP_AI_Export_Provider[]
	 */
	private $providers = array();

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Current export format version.
	 *
	 * @var string
	 */
	const EXPORT_VERSION = '2.0';

	/**
	 * Prefix for pre-import backup option names.
	 *
	 * @var string
	 */
	const BACKUP_PREFIX = 'wp_mcp_ai_settings_backup_pre_import_';

	/**
	 * Get the singleton instance.
	 *
	 * @since 1.2.0
	 *
	 * @return self
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register a provider.
	 *
	 * Idempotent — overwrites existing provider with the same ID.
	 *
	 * @since 1.2.0
	 *
	 * @param WP_MCP_AI_Export_Provider $provider Provider instance.
	 * @return void
	 */
	public function register( WP_MCP_AI_Export_Provider $provider ): void {
		$this->providers[ $provider->get_id() ] = $provider;
	}

	/**
	 * Get all registered providers (including unavailable ones).
	 *
	 * @since 1.2.0
	 *
	 * @return WP_MCP_AI_Export_Provider[]
	 */
	public function get_providers(): array {
		return $this->providers;
	}

	/**
	 * Get only available providers, sorted by label.
	 *
	 * @since 1.2.0
	 *
	 * @return WP_MCP_AI_Export_Provider[]
	 */
	public function get_available_providers(): array {
		$available = array_filter(
			$this->providers,
			function ( $p ) {
				return $p->is_available();
			}
		);
		uasort(
			$available,
			function ( $a, $b ) {
				return strcmp( $a->get_label(), $b->get_label() );
			}
		);
		return $available;
	}

	/**
	 * Get a single provider by ID.
	 *
	 * @since 1.2.0
	 *
	 * @param string $id Provider ID.
	 * @return WP_MCP_AI_Export_Provider|null
	 */
	public function get_provider( string $id ): ?WP_MCP_AI_Export_Provider {
		return $this->providers[ $id ] ?? null;
	}

	/**
	 * Export selected providers to a JSON string.
	 *
	 * @since 1.2.0
	 *
	 * @param string[] $provider_ids Provider IDs to export. Empty = all available.
	 * @param string   $password     Optional passphrase for AES-256-CBC encryption.
	 * @return string|\WP_Error JSON string or WP_Error on failure.
	 */
	public function export( array $provider_ids = array(), string $password = '' ) {
		$available = $this->get_available_providers();

		if ( empty( $provider_ids ) ) {
			$provider_ids = array_keys( $available );
		}

		$providers_data = array();
		$has_sensitive  = false;

		foreach ( $provider_ids as $id ) {
			$provider = $this->get_provider( $id );
			if ( ! $provider || ! $provider->is_available() ) {
				continue;
			}

			try {
				$exported = $provider->export();
			} catch ( \Throwable $e ) {
				return new \WP_Error(
					'export_error',
					sprintf(
						/* translators: 1: provider ID, 2: error message */
						__( 'Failed to export provider "%1$s": %2$s', 'mcp-ai-wpoos' ),
						$id,
						$e->getMessage()
					)
				);
			}

			if ( $provider->contains_sensitive_data() ) {
				$has_sensitive = true;
			}

			$providers_data[ $id ] = array(
				'label'   => $provider->get_label(),
				'version' => 1,
				'data'    => $exported,
			);
		}

		if ( empty( $providers_data ) ) {
			return new \WP_Error(
				'no_providers',
				__( 'No data to export. Select at least one data type.', 'mcp-ai-wpoos' )
			);
		}

		$envelope = $this->build_envelope( $providers_data, $has_sensitive );
		$json     = wp_json_encode( $envelope, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );

		if ( false === $json ) {
			return new \WP_Error(
				'json_encode_error',
				__( 'Failed to encode export data as JSON.', 'mcp-ai-wpoos' )
			);
		}

		// Optionally encrypt the payload.
		if ( ! empty( $password ) ) {
			$encrypted = $this->encrypt_string( $json, $password );
			if ( is_wp_error( $encrypted ) ) {
				return $encrypted;
			}
			$json = wp_json_encode(
				array(
					'encrypted' => true,
					'version'   => self::EXPORT_VERSION,
					'payload'   => $encrypted,
				),
				JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
			);
		}

		return $json;
	}

	/**
	 * Import from a JSON string.
	 *
	 * @since 1.2.0
	 *
	 * @param string   $json         Raw JSON content.
	 * @param string[] $provider_ids Provider IDs to import. Empty = all in file.
	 * @param string   $password     Passphrase if file is encrypted.
	 * @return array|\WP_Error Array with 'results' key (per-provider) or WP_Error.
	 */
	public function import( string $json, array $provider_ids = array(), string $password = '' ) {
		$import_data = json_decode( $json, true );

		if ( null === $import_data || JSON_ERROR_NONE !== json_last_error() ) {
			return new \WP_Error(
				'invalid_json',
				sprintf(
					/* translators: %s: JSON error message */
					__( 'Invalid JSON format: %s', 'mcp-ai-wpoos' ),
					json_last_error_msg()
				)
			);
		}

		// Handle encrypted files.
		if ( ! empty( $import_data['encrypted'] ) ) {
			if ( empty( $password ) ) {
				return new \WP_Error(
					'password_required',
					__( 'This export file is encrypted. Please provide the decryption passphrase.', 'mcp-ai-wpoos' )
				);
			}
			if ( empty( $import_data['payload'] ) ) {
				return new \WP_Error(
					'invalid_format',
					__( 'Encrypted file is missing the payload.', 'mcp-ai-wpoos' )
				);
			}
			$decrypted = $this->decrypt_string( $import_data['payload'], $password );
			if ( is_wp_error( $decrypted ) ) {
				return $decrypted;
			}
			$import_data = json_decode( $decrypted, true );
			if ( null === $import_data ) {
				return new \WP_Error(
					'decrypt_invalid_json',
					__( 'Decrypted data is not valid JSON. Check your passphrase.', 'mcp-ai-wpoos' )
				);
			}
		}

		// Detect and auto-wrap legacy v1 format.
		if ( isset( $import_data['settings'] ) && ! isset( $import_data['providers'] ) ) {
			$import_data = array(
				'version'   => '1.0',
				'providers' => array(
					'core_settings' => array(
						'label'   => __( 'Core Settings', 'mcp-ai-wpoos' ),
						'version' => 1,
						'data'    => $import_data['settings'],
					),
				),
			);
		}

		if ( ! isset( $import_data['providers'] ) || ! is_array( $import_data['providers'] ) ) {
			return new \WP_Error(
				'invalid_structure',
				__( 'Invalid settings file structure. Missing "providers" section.', 'mcp-ai-wpoos' )
			);
		}

		// Create pre-import backup.
		$this->create_pre_import_backup();

		$results = array();

		foreach ( $import_data['providers'] as $provider_id => $provider_section ) {
			// Skip providers not requested.
			if ( ! empty( $provider_ids ) && ! in_array( $provider_id, $provider_ids, true ) ) {
				continue;
			}

			$provider = $this->get_provider( $provider_id );
			if ( ! $provider || ! $provider->is_available() ) {
				$results[ $provider_id ] = array(
					'success' => false,
					'message' => sprintf(
						/* translators: %s: provider ID */
						__( 'Provider "%s" is not available on this site.', 'mcp-ai-wpoos' ),
						$provider_id
					),
				);
				continue;
			}

			$data = isset( $provider_section['data'] ) ? $provider_section['data'] : array();

			// Validate before import.
			$validation = $provider->validate( $data );
			if ( is_wp_error( $validation ) ) {
				$results[ $provider_id ] = array(
					'success' => false,
					'message' => $validation->get_error_message(),
				);
				$provider->log_action( 'validation_failed', $validation );
				continue;
			}

			// Import.
			$import_result = $provider->import( $data );
			if ( is_wp_error( $import_result ) ) {
				$results[ $provider_id ] = array(
					'success' => false,
					'message' => $import_result->get_error_message(),
				);
				$provider->log_action( 'import_failed', $import_result );
			} else {
				$results[ $provider_id ] = array(
					'success' => true,
					'message' => sprintf(
						/* translators: %s: provider label */
						__( '"%s" imported successfully.', 'mcp-ai-wpoos' ),
						$provider->get_label()
					),
				);
				$provider->log_action( 'imported', $import_result );

				/**
				 * Fires after a successful import for a provider.
				 *
				 * Use to clear caches, rebuild indexes, or trigger side effects.
				 *
				 * @since 1.2.0
				 *
				 * @param string $provider_id  The provider identifier.
				 * @param array  $imported_data The data that was imported.
				 */
				do_action( 'wp_mcp_ai_after_import', $provider_id, $data );
			}
		}

		// Clear settings cache after import.
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		return array( 'results' => $results );
	}

	/**
	 * Create a pre-import backup of current state.
	 *
	 * @since 1.2.0
	 *
	 * @return string Backup option key.
	 */
	public function create_pre_import_backup(): string {
		$backup_key = self::BACKUP_PREFIX . time();

		$full_backup = array();

		// Include core settings and credentials.
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$current_settings    = get_option( \WP_MCP_AI_Admin_Settings::OPTION_NAME, array() );
			$current_credentials = get_option( \WP_MCP_AI_Admin_Settings_Base::CREDENTIALS_OPTION_NAME, array() );
			$full_backup         = array_merge(
				is_array( $current_settings ) ? $current_settings : array(),
				is_array( $current_credentials ) ? $current_credentials : array()
			);
		}

		update_option( $backup_key, $full_backup, false );

		return $backup_key;
	}

	/**
	 * Build the JSON envelope for export.
	 *
	 * @since 1.2.0
	 *
	 * @param array $providers_data Provider data keyed by provider ID.
	 * @param bool  $has_sensitive  Whether any provider has sensitive data.
	 * @return array
	 */
	private function build_envelope( array $providers_data, bool $has_sensitive ): array {
		$envelope = array(
			'version'        => self::EXPORT_VERSION,
			'exported_at'    => current_time( 'mysql' ),
			'exported_by'    => wp_get_current_user()->user_login,
			'site_url'       => get_site_url(),
			'plugin_version' => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : 'unknown',
			'providers'      => $providers_data,
		);

		if ( $has_sensitive ) {
			$envelope['contains_sensitive_data'] = true;
		}

		return $envelope;
	}

	/**
	 * AES-256-CBC encrypt a string.
	 *
	 * Uses OpenSSL with a key derived from the passphrase via SHA-256.
	 * The IV is prepended to the ciphertext.
	 *
	 * @since 1.2.0
	 *
	 * @param string $plaintext  The plaintext to encrypt.
	 * @param string $passphrase The user-provided passphrase.
	 * @return string|\WP_Error Base64-encoded "IV:ciphertext" or WP_Error.
	 */
	private function encrypt_string( string $plaintext, string $passphrase ) {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return new \WP_Error(
				'openssl_missing',
				__( 'OpenSSL extension is required for password-protected exports.', 'mcp-ai-wpoos' )
			);
		}

		$key    = hash( 'sha256', $passphrase, true );
		$iv_len = openssl_cipher_iv_length( 'aes-256-cbc' );
		if ( false === $iv_len ) {
			return new \WP_Error(
				'cipher_error',
				__( 'Failed to determine cipher IV length.', 'mcp-ai-wpoos' )
			);
		}

		$iv = openssl_random_pseudo_bytes( $iv_len );

		$ciphertext = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $ciphertext ) {
			return new \WP_Error(
				'encrypt_error',
				__( 'Encryption failed.', 'mcp-ai-wpoos' )
			);
		}

		// Prepend IV to ciphertext for storage.
		return base64_encode( $iv . $ciphertext ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encryption payload, not obfuscation.
	}

	/**
	 * AES-256-CBC decrypt a string.
	 *
	 * @since 1.2.0
	 *
	 * @param string $ciphertext_b64 Base64-encoded "IV:ciphertext".
	 * @param string $passphrase     The user-provided passphrase.
	 * @return string|\WP_Error Plaintext or WP_Error.
	 */
	private function decrypt_string( string $ciphertext_b64, string $passphrase ) {
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return new \WP_Error(
				'openssl_missing',
				__( 'OpenSSL extension is required for password-protected exports.', 'mcp-ai-wpoos' )
			);
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decryption payload, not obfuscation.
		$data = base64_decode( $ciphertext_b64, true );
		if ( false === $data ) {
			return new \WP_Error(
				'decode_error',
				__( 'Failed to decode encrypted data. The file may be corrupted.', 'mcp-ai-wpoos' )
			);
		}

		$key    = hash( 'sha256', $passphrase, true );
		$iv_len = openssl_cipher_iv_length( 'aes-256-cbc' );
		if ( false === $iv_len ) {
			return new \WP_Error(
				'cipher_error',
				__( 'Failed to determine cipher IV length.', 'mcp-ai-wpoos' )
			);
		}

		if ( strlen( $data ) <= $iv_len ) {
			return new \WP_Error(
				'data_too_short',
				__( 'Encrypted data is too short. Check your passphrase.', 'mcp-ai-wpoos' )
			);
		}

		$iv         = substr( $data, 0, $iv_len );
		$ciphertext = substr( $data, $iv_len );

		$plaintext = openssl_decrypt( $ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $plaintext ) {
			return new \WP_Error(
				'decrypt_error',
				__( 'Decryption failed. Check your passphrase.', 'mcp-ai-wpoos' )
			);
		}

		return $plaintext;
	}
}
