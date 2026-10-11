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
