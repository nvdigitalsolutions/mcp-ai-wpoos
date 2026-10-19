<?php
/**
 * Test-only stub of the NV oOS base plugin's credential API.
 *
 * The content-graph plugin accepts assistant credentials issued by the base
 * plugin (`WP_MCP_AI_Credentials`), guarded by class_exists() so the
 * integration stays optional. The plugin's own test environment does not
 * load the base plugin, so this stub supplies the two static methods the
 * REST controller calls, backed by an in-test token table.
 *
 * @package NvoosContentGraph\Tests
 * @since   1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Credentials' ) ) {
	/**
	 * Minimal stand-in for the base plugin's credential class.
	 */
	class WP_MCP_AI_Credentials {

		/**
		 * In-test token table: identifier => secret.
		 *
		 * @var array<string,string>
		 */
		public static $tokens = array();

		/**
		 * Seed a valid token for the next test(s).
		 *
		 * @return string Full token (cred_identifier.SECRET).
		 */
		public static function seed_token() {
			$identifier                  = 'cred_' . strtolower( wp_generate_password( 8, false, false ) );
			$secret                      = wp_generate_password( 32, false, false );
			self::$tokens[ $identifier ] = $secret;
			return $identifier . '.' . $secret;
		}

		/**
		 * Check whether a string has the base plugin's credential format.
		 *
		 * @param string $token Token candidate.
		 * @return bool
		 */
		public static function is_token_format( $token ) {
			return is_string( $token ) && 1 === preg_match( '/^cred_[a-z0-9]+\..+$/', $token );
		}

		/**
		 * Validate a credential token against the seeded table.
		 *
		 * @param string $token Raw token.
		 * @return array|WP_Error Credential metadata when valid.
		 */
		public static function validate_token( $token ) {
			if ( ! self::is_token_format( $token ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_token', 'The provided credential token is invalid.', array( 'status' => 401 ) );
			}
			foreach ( self::$tokens as $identifier => $secret ) {
				if ( hash_equals( $identifier . '.' . $secret, $token ) ) {
					return array(
						'assistant_id'  => 1,
						'credential_id' => $identifier,
						'created_by'    => 1,
					);
				}
			}
			return new WP_Error( 'wp_mcp_ai_invalid_token', 'The provided credential token is invalid.', array( 'status' => 401 ) );
		}
	}
}

if ( ! class_exists( 'WP_MCP_AI_Shortcode' ) ) {
	/**
	 * Minimal stand-in for the base plugin's guest-token validator.
	 *
	 * The content-graph REST controller delegates guest-token validation to
	 * WP_MCP_AI_Shortcode::validate_guest_token() (X-WP-MCP-AI-Guest header
	 * or guest_token parameter), guarded by class_exists() so the integration
	 * stays optional. This stub backs the in-test token table.
	 */
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound -- Test helper pairs the credential and guest-token stubs the controller calls.
	class WP_MCP_AI_Shortcode {

		/**
		 * In-test token table: token => assistant_id.
		 *
		 * @var array<string,int>
		 */
		public static $guest_tokens = array();

		/**
		 * Seed a valid guest token for the next test(s).
		 *
		 * @param int $assistant_id Assistant ID the token is scoped to.
		 * @return string Guest token.
		 */
		public static function seed_guest_token( $assistant_id = 0 ) {
			$token                        = 'guest_' . strtolower( wp_generate_password( 16, false, false ) );
			self::$guest_tokens[ $token ] = absint( $assistant_id );
			return $token;
		}

		/**
		 * Validate a guest token against the seeded table.
		 *
		 * @param string            $token        Guest access token.
		 * @param int               $assistant_id Requested assistant ID.
		 * @param WP_REST_Request|null $request     REST request (unused in the stub).
		 * @return int|false Assistant ID when valid, false otherwise.
		 */
		public static function validate_guest_token( $token, $assistant_id = 0, $request = null ) {
			// Signature mirrors the base plugin; the unused slots keep the stub drop-in.
			unset( $assistant_id, $request );
			$token = is_string( $token ) ? trim( $token ) : '';
			if ( '' === $token || ! array_key_exists( $token, self::$guest_tokens ) ) {
				return false;
			}
			return self::$guest_tokens[ $token ];
		}
	}
}
