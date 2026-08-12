<?php
/**
 * Operator credential repository for the Fleet Operator addon.
 *
 * Persists external-operator credentials as a single grouped option and
 * exposes mint / verify / revoke / rate-limit operations. Operator tokens
 * use the format `op_<id>.<secret>` and are audience-bound to the site URL
 * they were minted for (RFC 8707 resource-indicator pattern, simplified:
 * no authorization server).
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Storage and verification for external-operator credentials.
 */
class WP_MCP_AI_Operator_Credential_Repository {

	const OPTION_KEY       = 'wp_mcp_ai_fleet_operators';
	const TOKEN_PATTERN    = '/^(op_[a-z0-9]+)\.([a-zA-Z0-9]+)$/';
	const RATE_TTL_SECONDS = 60;
	const USAGE_WRITE_GAP  = 30;

	/**
	 * Retrieve all operator records keyed by operator ID.
	 *
	 * @return array<string,array>
	 */
	public static function get_all() {
		$records = get_option( self::OPTION_KEY, array() );
		return is_array( $records ) ? $records : array();
	}

	/**
	 * Retrieve a single operator record.
	 *
	 * @param string $id Operator identifier (op_xxxx).
	 * @return array|null Record or null when not found.
	 */
	public static function get( $id ) {
		$id      = sanitize_key( $id );
		$records = self::get_all();
		return isset( $records[ $id ] ) ? $records[ $id ] : null;
	}

	/**
	 * Create a new operator credential.
	 *
	 * @param string $label         Human-readable operator label (e.g. "Hermes").
	 * @param int    $user_id       WordPress user the operator acts as (the authorizing human).
	 * @param array  $allowed_tools Tool slugs, globs, or group:<toolkit> entries.
	 * @param string $mode          "read" or "readwrite".
	 * @param int    $expires_days  Validity in days; 0 = never expires.
	 * @param int    $rate_limit    Allowed requests per 60 seconds.
	 * @return array|WP_Error Array with "record" and "token" keys, or error.
	 */
	public static function create( $label, $user_id, $allowed_tools, $mode = 'readwrite', $expires_days = 90, $rate_limit = 60 ) {
		$label = sanitize_text_field( $label );
		if ( '' === $label ) {
			return new WP_Error( 'wp_mcp_ai_operator_invalid_label', __( 'An operator label is required.', 'mcp-ai-wpoos' ) );
		}

		$user_id = absint( $user_id );
		if ( ! get_userdata( $user_id ) ) {
			return new WP_Error( 'wp_mcp_ai_operator_invalid_user', __( 'The operator must map to an existing WordPress user.', 'mcp-ai-wpoos' ) );
		}

		$mode = ( 'read' === $mode ) ? 'read' : 'readwrite';

		$allowed_tools = array_values( array_unique( array_filter( array_map( array( 'WP_MCP_AI_Operator_Tool_Scope', 'sanitize_entry' ), (array) $allowed_tools ) ) ) );

		$expires_days = absint( $expires_days );
		$expires_at   = ( $expires_days > 0 ) ? time() + ( $expires_days * DAY_IN_SECONDS ) : 0;

		$rate_limit = absint( $rate_limit );
		$rate_limit = max( 1, min( 600, $rate_limit ) );

		$id     = self::generate_id();
		$secret = bin2hex( random_bytes( 24 ) );

		$record = array(
			'id'            => $id,
			'label'         => $label,
			'user_id'       => $user_id,
			'secret_hash'   => wp_hash_password( $secret ),
			'allowed_tools' => $allowed_tools,
			'mode'          => $mode,
			'audience'      => untrailingslashit( home_url( '/' ) ),
			'rate_limit'    => $rate_limit,
			'expires_at'    => $expires_at,
			'created_at'    => time(),
			'last_used_at'  => 0,
			'status'        => 'active',
		);

		$records        = self::get_all();
		$records[ $id ] = $record;
		update_option( self::OPTION_KEY, $records, false );

		return array(
			'record' => $record,
			'token'  => $id . '.' . $secret,
		);
	}

	/**
	 * Verify a bearer token and return the operator record on success.
	 *
	 * Checks format, existence, status, expiry, secret, audience binding,
	 * and the per-operator rate limit before touching last_used_at.
	 *
	 * @param string $token Raw bearer token (op_xxxx.SECRET).
	 * @return array|WP_Error Operator record or error.
	 */
	public static function verify( $token ) {
		if ( ! is_string( $token ) || ! preg_match( self::TOKEN_PATTERN, $token, $matches ) ) {
			return new WP_Error(
				'wp_mcp_ai_operator_invalid_format',
				__( 'Operator tokens must use the op_xxxx.SECRET format.', 'mcp-ai-wpoos' ),
				array( 'status' => 401 )
			);
		}

		$id     = $matches[1];
		$secret = $matches[2];
		$record = self::get( $id );

		if ( null === $record ) {
			return new WP_Error(
				'wp_mcp_ai_operator_unknown',
				__( 'Unknown operator credential.', 'mcp-ai-wpoos' ),
				array( 'status' => 401 )
			);
		}

		if ( 'active' !== $record['status'] ) {
			return new WP_Error(
				'wp_mcp_ai_operator_revoked',
				__( 'This operator credential has been revoked.', 'mcp-ai-wpoos' ),
				array( 'status' => 401 )
			);
		}

		if ( ! empty( $record['expires_at'] ) && time() > $record['expires_at'] ) {
			return new WP_Error(
				'wp_mcp_ai_operator_expired',
				__( 'This operator credential has expired.', 'mcp-ai-wpoos' ),
				array( 'status' => 401 )
			);
		}

		if ( ! wp_check_password( $secret, $record['secret_hash'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_operator_invalid_secret',
				__( 'Invalid operator credential secret.', 'mcp-ai-wpoos' ),
				array( 'status' => 401 )
			);
		}

		$audience = self::audience_matches( $record );
		if ( is_wp_error( $audience ) ) {
			return $audience;
		}

		$rate_limit = self::check_rate_limit( $record );
		if ( is_wp_error( $rate_limit ) ) {
			return $rate_limit;
		}

		self::touch_last_used( $record );

		return $record;
	}

	/**
	 * Revoke an operator credential (kill switch).
	 *
	 * The record is kept for audit purposes; status flips to "revoked".
	 *
	 * @param string $id Operator identifier.
	 * @return bool True on success, false when the record is missing.
	 */
	public static function revoke( $id ) {
		$id      = sanitize_key( $id );
		$records = self::get_all();

		if ( ! isset( $records[ $id ] ) ) {
			return false;
		}

		$records[ $id ]['status'] = 'revoked';
		update_option( self::OPTION_KEY, $records, false );
		return true;
	}

	/**
	 * Check that the record audience matches the current (or given) site URL.
	 *
	 * @param array  $record Operator record.
	 * @param string $url    Optional URL to compare against (tests, proxies).
	 * @return true|WP_Error True on match, error otherwise.
	 */
	public static function audience_matches( $record, $url = '' ) {
		if ( '' === $url ) {
			$url = untrailingslashit( home_url( '/' ) );
		}

		/**
		 * Filter the audience URL used for operator credential verification.
		 *
		 * Useful behind reverse proxies or for test environments where
		 * home_url() differs from the externally visible canonical URL.
		 *
		 * @param string $url Canonical audience URL.
		 */
		$url = apply_filters( 'wp_mcp_ai_operator_audience_url', $url );

		$expected = isset( $record['audience'] ) ? $record['audience'] : '';
		if ( '' === $expected ) {
			return new WP_Error(
				'wp_mcp_ai_operator_missing_audience',
				__( 'Operator credential is missing its audience binding.', 'mcp-ai-wpoos' ),
				array( 'status' => 401 )
			);
		}

		if ( trailingslashit( $url ) !== trailingslashit( $expected ) ) {
			return new WP_Error(
				'wp_mcp_ai_operator_audience_mismatch',
				__( 'Operator credential was not issued for this site.', 'mcp-ai-wpoos' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * Enforce the per-operator token-bucket rate limit.
	 *
	 * @param array $record Operator record.
	 * @return true|WP_Error True when allowed, error when limited.
	 */
	public static function check_rate_limit( $record ) {
		$limit = isset( $record['rate_limit'] ) ? absint( $record['rate_limit'] ) : 60;
		$limit = max( 1, min( 600, $limit ) );

		$key   = 'wp_mcp_ai_op_rl_' . $record['id'];
		$count = get_transient( $key );

		if ( false !== $count && $count >= $limit ) {
			return new WP_Error(
				'wp_mcp_ai_operator_rate_limited',
				__( 'Operator request rate limit exceeded. Slow down and retry shortly.', 'mcp-ai-wpoos' ),
				array( 'status' => 429 )
			);
		}

		set_transient( $key, false === $count ? 1 : $count + 1, self::RATE_TTL_SECONDS );
		return true;
	}

	/**
	 * Update last_used_at with write throttling to avoid per-request writes.
	 *
	 * @param array $record Operator record.
	 * @return void
	 */
	protected static function touch_last_used( $record ) {
		$last = isset( $record['last_used_at'] ) ? absint( $record['last_used_at'] ) : 0;
		if ( ( time() - $last ) < self::USAGE_WRITE_GAP ) {
			return;
		}

		$records = self::get_all();
		if ( isset( $records[ $record['id'] ] ) ) {
			$records[ $record['id'] ]['last_used_at'] = time();
			update_option( self::OPTION_KEY, $records, false );
		}
	}

	/**
	 * Generate a unique operator identifier (op_ + 10 alphanumeric chars).
	 *
	 * @return string
	 */
	protected static function generate_id() {
		$existing = array_keys( self::get_all() );
		do {
			$candidate = 'op_' . strtolower( wp_generate_password( 10, false, false ) );
		} while ( in_array( $candidate, $existing, true ) );

		return $candidate;
	}
}
