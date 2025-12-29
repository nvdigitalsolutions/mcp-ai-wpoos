<?php
/**
 * Credential management helpers for NV oOS.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Credentials' ) ) {
	/**
	 * Handles creation, storage, and validation of assistant credentials.
	 */
	class WP_MCP_AI_Credentials {
		const META_KEY            = '_wp_mcp_ai_credentials';
		const ASSISTANT_POST_TYPE = 'mcp_ai_assistant';
		const INDEX_OPTION        = 'wp_mcp_ai_credential_index';

		/**
		 * Determine whether a token string matches the expected credential format.
		 *
		 * @param string $token Raw token string.
		 * @return bool
		 */
		public static function is_token_format( $token ) {
			$parts = self::parse_token( $token );

			return null !== $parts;
		}

		/**
		 * Parse a token into its identifier and secret components.
		 *
		 * @param string $token Raw token string.
		 * @return array|null
		 */
		public static function parse_token( $token ) {
			if ( ! is_string( $token ) || '' === $token ) {
				return null;
			}

			$parts = explode( '.', $token, 2 );
			if ( 2 !== count( $parts ) ) {
				return null;
			}

			$identifier = sanitize_key( $parts[0] );
			$secret     = trim( $parts[1] );

			if ( '' === $identifier || '' === $secret ) {
				return null;
			}

			if ( 0 !== strpos( $identifier, 'cred_' ) ) {
				return null;
			}

			return array( $identifier, $secret );
		}

		/**
		 * Retrieve all stored credentials for an assistant.
		 *
		 * @param int $assistant_id Assistant post ID.
		 * @return array
		 */
		public static function get_credentials( $assistant_id ) {
			$assistant_id = absint( $assistant_id );
			if ( ! $assistant_id ) {
				return array();
			}

			$stored = get_post_meta( $assistant_id, self::META_KEY, true );
			if ( ! is_array( $stored ) ) {
				return array();
			}

			return self::normalize_credentials( $stored );
		}

		/**
		 * Issue a new credential for an assistant.
		 *
		 * @param int $assistant_id Assistant post ID.
		 * @param int $user_id      Issuing user ID.
		 * @return array|WP_Error {
		 *     Array containing the raw token and stored credential metadata.
		 *
		 *     @type string $token      Raw token value (identifier.secret).
		 *     @type array  $credential Stored credential metadata.
		 * }
		 */
		public static function issue_credential( $assistant_id, $user_id ) {
			$assistant_id = absint( $assistant_id );
			$user_id      = absint( $user_id );

			if ( ! self::is_valid_assistant( $assistant_id ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_assistant', __( 'Unable to issue a credential for the requested assistant.', 'wp-mcp-ai' ) );
			}

			$credentials = self::get_credentials( $assistant_id );

			$credential_id = self::generate_unique_id( $credentials );
			$secret        = wp_generate_password( 32, false, false );
			$hash          = wp_hash_password( $secret );

			$record = array(
				'id'         => $credential_id,
				'hash'       => $hash,
				'created_at' => current_time( 'mysql', true ),
				'created_by' => $user_id,
				'revoked_at' => '',
				'revoked_by' => 0,
			);

			$credentials[] = $record;

			self::store_credentials( $assistant_id, $credentials );
			self::update_index( $credential_id, $assistant_id );

			WP_MCP_AI_Logger::log_event(
				'credential_issued',
				'Assistant credential issued.',
				array(
					'assistant_id'  => $assistant_id,
					'credential_id' => $credential_id,
					'user_id'       => $user_id,
				)
			);

			return array(
				'token'      => $credential_id . '.' . $secret,
				'credential' => $record,
			);
		}

		/**
		 * Revoke an existing credential.
		 *
		 * @param int    $assistant_id  Assistant post ID.
		 * @param string $credential_id Credential identifier.
		 * @param int    $user_id       Acting user ID.
		 * @return array|WP_Error Updated credential record on success.
		 */
		public static function revoke_credential( $assistant_id, $credential_id, $user_id ) {
			$assistant_id  = absint( $assistant_id );
			$credential_id = sanitize_key( $credential_id );
			$user_id       = absint( $user_id );

			if ( ! self::is_valid_assistant( $assistant_id ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_assistant', __( 'Unable to revoke the credential for the requested assistant.', 'wp-mcp-ai' ) );
			}

			if ( '' === $credential_id ) {
				return new WP_Error( 'wp_mcp_ai_unknown_credential', __( 'The requested credential could not be found.', 'wp-mcp-ai' ) );
			}

			$credentials = self::get_credentials( $assistant_id );

			foreach ( $credentials as $index => $credential ) {
				if ( $credential_id !== $credential['id'] ) {
					continue;
				}

				if ( ! empty( $credential['revoked_at'] ) ) {
					return new WP_Error( 'wp_mcp_ai_credential_already_revoked', __( 'The credential has already been revoked.', 'wp-mcp-ai' ) );
				}

				$credentials[ $index ]['revoked_at'] = current_time( 'mysql', true );
				$credentials[ $index ]['revoked_by'] = $user_id;

				self::store_credentials( $assistant_id, $credentials );

				WP_MCP_AI_Logger::log_event(
					'credential_revoked',
					'Assistant credential revoked.',
					array(
						'assistant_id'  => $assistant_id,
						'credential_id' => $credential_id,
						'user_id'       => $user_id,
					)
				);

				return $credentials[ $index ];
			}

			return new WP_Error( 'wp_mcp_ai_unknown_credential', __( 'The requested credential could not be found.', 'wp-mcp-ai' ) );
		}

		/**
		 * Permanently delete a credential.
		 *
		 * @param int    $assistant_id  Assistant post ID.
		 * @param string $credential_id Credential identifier.
		 * @param int    $user_id       Acting user ID.
		 * @return array|WP_Error Deleted credential record on success.
		 */
		public static function delete_credential( $assistant_id, $credential_id, $user_id ) {
			$assistant_id  = absint( $assistant_id );
			$credential_id = sanitize_key( $credential_id );
			$user_id       = absint( $user_id );

			if ( ! self::is_valid_assistant( $assistant_id ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_assistant', __( 'Unable to delete the credential for the requested assistant.', 'wp-mcp-ai' ) );
			}

			if ( '' === $credential_id ) {
				return new WP_Error( 'wp_mcp_ai_unknown_credential', __( 'The requested credential could not be found.', 'wp-mcp-ai' ) );
			}

			$credentials = self::get_credentials( $assistant_id );

			foreach ( $credentials as $index => $credential ) {
				if ( $credential_id !== $credential['id'] ) {
					continue;
				}

				$deleted = $credential;
				unset( $credentials[ $index ] );

				self::store_credentials( $assistant_id, array_values( $credentials ) );
				self::remove_from_index( $credential_id );

				WP_MCP_AI_Logger::log_event(
					'credential_deleted',
					'Assistant credential deleted.',
					array(
						'assistant_id'  => $assistant_id,
						'credential_id' => $credential_id,
						'user_id'       => $user_id,
					)
				);

				return $deleted;
			}

			return new WP_Error( 'wp_mcp_ai_unknown_credential', __( 'The requested credential could not be found.', 'wp-mcp-ai' ) );
		}

		/**
		 * Validate a credential token.
		 *
		 * @param string   $token          Raw token.
		 * @param int|null $assistant_hint Optional assistant ID hint from the request.
		 * @return array|WP_Error Credential metadata when valid.
		 */
		public static function validate_token( $token, $assistant_hint = null ) {
			$parsed = self::parse_token( $token );
			if ( null === $parsed ) {
				return new WP_Error( 'wp_mcp_ai_invalid_token', __( 'The provided credential token is invalid.', 'wp-mcp-ai' ), array( 'status' => 401 ) );
			}

			list( $credential_id, $secret ) = $parsed;

			$assistant_ids = array();
			$hint_id       = absint( $assistant_hint );
			if ( $hint_id ) {
				$assistant_ids[] = $hint_id;
			}

			$index = self::get_index();
			if ( isset( $index[ $credential_id ] ) ) {
				$assistant_ids[] = absint( $index[ $credential_id ] );
			}

			$assistant_ids = array_values( array_unique( array_filter( $assistant_ids ) ) );

			if ( empty( $assistant_ids ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_token', __( 'The provided credential token is invalid.', 'wp-mcp-ai' ), array( 'status' => 401 ) );
			}

			foreach ( $assistant_ids as $assistant_id ) {
				$credentials = self::get_credentials( $assistant_id );
				foreach ( $credentials as $credential ) {
					if ( $credential_id !== $credential['id'] ) {
						continue;
					}

					if ( ! empty( $credential['revoked_at'] ) ) {
						return new WP_Error( 'wp_mcp_ai_revoked_token', __( 'This credential has been revoked.', 'wp-mcp-ai' ), array( 'status' => 401 ) );
					}

					if ( wp_check_password( $secret, $credential['hash'] ) ) {
						return array(
							'assistant_id'  => $assistant_id,
							'credential_id' => $credential['id'],
							'created_at'    => $credential['created_at'],
							'created_by'    => $credential['created_by'],
						);
					}

					return new WP_Error( 'wp_mcp_ai_invalid_token', __( 'The provided credential token is invalid.', 'wp-mcp-ai' ), array( 'status' => 401 ) );
				}
			}

			return new WP_Error( 'wp_mcp_ai_invalid_token', __( 'The provided credential token is invalid.', 'wp-mcp-ai' ), array( 'status' => 401 ) );
		}

		/**
		 * Remove all credential mappings for an assistant from the index.
		 *
		 * @param int $assistant_id Assistant post ID.
		 */
		public static function purge_assistant_credentials( $assistant_id ) {
			$assistant_id = absint( $assistant_id );

			if ( ! $assistant_id ) {
				return;
			}

			$credentials = self::get_credentials( $assistant_id );

			if ( empty( $credentials ) ) {
				return;
			}

			foreach ( $credentials as $credential ) {
				if ( empty( $credential['id'] ) ) {
					continue;
				}

				self::remove_from_index( $credential['id'] );
			}
		}

		/**
		 * Normalize an array of stored credential records.
		 *
		 * @param array $credentials Raw credential array.
		 * @return array
		 */
		protected static function normalize_credentials( $credentials ) {
			$normalized = array();

			foreach ( $credentials as $credential ) {
				if ( ! is_array( $credential ) ) {
					continue;
				}

				$id   = isset( $credential['id'] ) ? sanitize_key( $credential['id'] ) : '';
				$hash = isset( $credential['hash'] ) ? (string) $credential['hash'] : '';

				if ( '' === $id || '' === $hash ) {
					continue;
				}

				$normalized[] = array(
					'id'         => $id,
					'hash'       => $hash,
					'created_at' => isset( $credential['created_at'] ) ? sanitize_text_field( $credential['created_at'] ) : '',
					'created_by' => isset( $credential['created_by'] ) ? absint( $credential['created_by'] ) : 0,
					'revoked_at' => isset( $credential['revoked_at'] ) ? sanitize_text_field( $credential['revoked_at'] ) : '',
					'revoked_by' => isset( $credential['revoked_by'] ) ? absint( $credential['revoked_by'] ) : 0,
				);
			}

			return $normalized;
		}

		/**
		 * Persist credentials to post meta.
		 *
		 * @param int   $assistant_id Assistant post ID.
		 * @param array $credentials  Credential list.
		 */
		protected static function store_credentials( $assistant_id, $credentials ) {
			update_post_meta( $assistant_id, self::META_KEY, array_values( $credentials ) );
		}

		/**
		 * Determine whether an assistant ID is valid.
		 *
		 * @param int $assistant_id Assistant post ID.
		 * @return bool
		 */
		protected static function is_valid_assistant( $assistant_id ) {
			if ( ! $assistant_id ) {
				return false;
			}

			return self::ASSISTANT_POST_TYPE === get_post_type( $assistant_id );
		}

		/**
		 * Generate a unique credential identifier.
		 *
		 * @param array $credentials Existing credentials.
		 * @return string
		 */
		protected static function generate_unique_id( $credentials ) {
			$existing = wp_list_pluck( $credentials, 'id' );

			do {
				$candidate = 'cred_' . strtolower( wp_generate_password( 12, false, false ) );
			} while ( in_array( $candidate, $existing, true ) );

			return $candidate;
		}

		/**
		 * Retrieve the credential index option.
		 *
		 * @return array
		 */
		protected static function get_index() {
			$index = get_option( self::INDEX_OPTION, array() );

			if ( ! is_array( $index ) ) {
				return array();
			}

			$clean = array();
			foreach ( $index as $credential_id => $assistant_id ) {
				$key = sanitize_key( $credential_id );
				if ( '' === $key ) {
					continue;
				}

				$clean[ $key ] = absint( $assistant_id );
			}

			return $clean;
		}

		/**
		 * Update the credential index with a new mapping.
		 *
		 * @param string $credential_id Credential identifier.
		 * @param int    $assistant_id  Assistant post ID.
		 */
		protected static function update_index( $credential_id, $assistant_id ) {
			$index                   = self::get_index();
			$index[ $credential_id ] = absint( $assistant_id );
			update_option( self::INDEX_OPTION, $index, false );
		}

		/**
		 * Remove a credential from the index.
		 *
		 * @param string $credential_id Credential identifier.
		 */
		protected static function remove_from_index( $credential_id ) {
			$index = self::get_index();

			if ( isset( $index[ $credential_id ] ) ) {
				unset( $index[ $credential_id ] );
				update_option( self::INDEX_OPTION, $index, false );
			}
		}
	}
}
