<?php
/**
 * Credential Repository
 *
 * Handles database operations for credentials.
 * Part of Phase 4 refactoring (Milestone 9 - Repository Pattern).
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Credential Repository class
 *
 * Responsible for:
 * - Credential storage and retrieval
 * - Credential validation
 * - Token management
 * - Secure credential operations
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Credential_Repository {

	/**
	 * Meta key for credentials
	 *
	 * @var string
	 */
	private $credentials_meta_key = 'mcp_ai_credentials';

	/**
	 * Get credentials for an assistant
	 *
	 * @param int $assistant_id Assistant ID.
	 * @return array Array of credentials.
	 */
	public function get_credentials( $assistant_id ) {
		$credentials = get_post_meta( $assistant_id, $this->credentials_meta_key, true );

		if ( ! is_array( $credentials ) ) {
			return array();
		}

		return $credentials;
	}

	/**
	 * Save credentials for an assistant
	 *
	 * @param int   $assistant_id Assistant ID.
	 * @param array $credentials  Credentials array.
	 * @return bool True on success, false on failure.
	 */
	public function save_credentials( $assistant_id, $credentials ) {
		if ( ! is_array( $credentials ) ) {
			return false;
		}

		return update_post_meta( $assistant_id, $this->credentials_meta_key, $credentials );
	}

	/**
	 * Find credential by token
	 *
	 * @param string $token Credential token to find.
	 * @return array|null Credential data or null if not found.
	 */
	public function find_by_token( $token ) {
		global $wpdb;

		// Search through all assistants' credentials.
		$query = $wpdb->prepare(
			"SELECT post_id, meta_value 
			FROM {$wpdb->postmeta} 
			WHERE meta_key = %s",
			$this->credentials_meta_key
		);

		$results = $wpdb->get_results( $query );

		foreach ( $results as $result ) {
			$credentials = maybe_unserialize( $result->meta_value );

			if ( ! is_array( $credentials ) ) {
				continue;
			}

			foreach ( $credentials as $credential ) {
				if ( isset( $credential['token'] ) && $this->verify_token( $token, $credential['token'] ) ) {
					return array(
						'assistant_id' => $result->post_id,
						'credential'   => $credential,
					);
				}
			}
		}

		return null;
	}

	/**
	 * Create new credential for assistant
	 *
	 * @param int   $assistant_id   Assistant ID.
	 * @param array $credential_data Credential data.
	 * @return string|WP_Error Generated token or error.
	 */
	public function create_credential( $assistant_id, $credential_data ) {
		$credentials = $this->get_credentials( $assistant_id );

		// Generate secure token.
		$token        = $this->generate_token();
		$hashed_token = $this->hash_token( $token );

		$new_credential = array(
			'id'          => wp_generate_uuid4(),
			'token'       => $hashed_token,
			'name'        => $credential_data['name'] ?? '',
			'description' => $credential_data['description'] ?? '',
			'created_at'  => current_time( 'mysql' ),
			'expires_at'  => $credential_data['expires_at'] ?? null,
			'permissions' => $credential_data['permissions'] ?? array(),
		);

		$credentials[] = $new_credential;

		$saved = $this->save_credentials( $assistant_id, $credentials );

		if ( ! $saved ) {
			return new WP_Error(
				'wp_mcp_ai_credential_save_failed',
				__( 'Failed to save credential.', 'wp-mcp-ai' )
			);
		}

		// Return the plain token (only time it's available).
		return $token;
	}

	/**
	 * Revoke credential
	 *
	 * @param int    $assistant_id Assistant ID.
	 * @param string $credential_id Credential ID.
	 * @return bool True on success, false on failure.
	 */
	public function revoke_credential( $assistant_id, $credential_id ) {
		$credentials = $this->get_credentials( $assistant_id );

		$updated_credentials = array_filter(
			$credentials,
			function ( $credential ) use ( $credential_id ) {
				return ( $credential['id'] ?? '' ) !== $credential_id;
			}
		);

		// Reset array keys.
		$updated_credentials = array_values( $updated_credentials );

		return $this->save_credentials( $assistant_id, $updated_credentials );
	}

	/**
	 * Check if credential is expired
	 *
	 * @param array $credential Credential data.
	 * @return bool True if expired, false otherwise.
	 */
	public function is_expired( $credential ) {
		if ( empty( $credential['expires_at'] ) ) {
			return false; // No expiration set.
		}

		$expires_timestamp = strtotime( $credential['expires_at'] );
		$current_timestamp = current_time( 'timestamp' );

		return $current_timestamp > $expires_timestamp;
	}

	/**
	 * Validate credential
	 *
	 * @param string $token Token to validate.
	 * @return array|null Credential data with assistant_id or null if invalid.
	 */
	public function validate_credential( $token ) {
		$result = $this->find_by_token( $token );

		if ( ! $result ) {
			return null;
		}

		// Check if expired.
		if ( $this->is_expired( $result['credential'] ) ) {
			return null;
		}

		return $result;
	}

	/**
	 * Generate secure token
	 *
	 * @return string Generated token.
	 */
	private function generate_token() {
		return 'cred_' . bin2hex( random_bytes( 32 ) );
	}

	/**
	 * Hash token for storage
	 *
	 * @param string $token Plain token.
	 * @return string Hashed token.
	 */
	private function hash_token( $token ) {
		return wp_hash_password( $token );
	}

	/**
	 * Verify token against hash
	 *
	 * @param string $token  Plain token.
	 * @param string $hash   Hashed token.
	 * @return bool True if matches, false otherwise.
	 */
	private function verify_token( $token, $hash ) {
		return wp_check_password( $token, $hash );
	}

	/**
	 * Get all credentials across all assistants
	 *
	 * @return array Array of credentials with assistant context.
	 */
	public function get_all_credentials() {
		global $wpdb;

		$query = $wpdb->prepare(
			"SELECT post_id, meta_value 
			FROM {$wpdb->postmeta} 
			WHERE meta_key = %s",
			$this->credentials_meta_key
		);

		$results         = $wpdb->get_results( $query );
		$all_credentials = array();

		foreach ( $results as $result ) {
			$credentials = maybe_unserialize( $result->meta_value );

			if ( ! is_array( $credentials ) ) {
				continue;
			}

			foreach ( $credentials as $credential ) {
				$all_credentials[] = array(
					'assistant_id' => $result->post_id,
					'credential'   => $credential,
				);
			}
		}

		return $all_credentials;
	}

	/**
	 * Clean up expired credentials
	 *
	 * @return int Number of credentials removed.
	 */
	public function cleanup_expired() {
		global $wpdb;

		$query = $wpdb->prepare(
			"SELECT post_id, meta_value 
			FROM {$wpdb->postmeta} 
			WHERE meta_key = %s",
			$this->credentials_meta_key
		);

		$results       = $wpdb->get_results( $query );
		$removed_count = 0;

		foreach ( $results as $result ) {
			$credentials = maybe_unserialize( $result->meta_value );

			if ( ! is_array( $credentials ) ) {
				continue;
			}

			$valid_credentials = array_filter(
				$credentials,
				function ( $credential ) {
					return ! $this->is_expired( $credential );
				}
			);

			$removed = count( $credentials ) - count( $valid_credentials );

			if ( $removed > 0 ) {
				$this->save_credentials( $result->post_id, array_values( $valid_credentials ) );
				$removed_count += $removed;
			}
		}

		return $removed_count;
	}
}
