<?php
/**
 * Per-toolkit-server API token service — Phase 3d.
 *
 * Generates, stores, validates, and revokes bearer tokens that allow
 * programmatic (non-user-session) access to a specific toolkit MCP endpoint.
 *
 * Token format:  mcptk_{8-char prefix}.{40-char secret}
 * Storage:       WP option `wp_mcp_ai_tk_mcp_token_{slug}` — array of
 *                {prefix, hash, label, created_at, last_used_at} records.
 *
 * The raw token is shown to the admin exactly once (at generation time).
 * Only the bcrypt hash of the secret is persisted on disk.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Manages per-toolkit-server bearer tokens.
 */
class WP_MCP_AI_Pro_Toolkit_Server_Token {

	/**
	 * Token string prefix — all tokens start with this.
	 */
	const TOKEN_PREFIX = 'mcptk_';

	/**
	 * WP option prefix that stores tokens per server.
	 */
	const OPTION_PREFIX = 'wp_mcp_ai_tk_mcp_token_';

	/**
	 * Maximum number of tokens that may exist for a single server.
	 */
	const MAX_TOKENS = 10;

	// -----------------------------------------------------------------------
	// Public API
	// -----------------------------------------------------------------------

	/**
	 * Generate a new bearer token for a toolkit server.
	 *
	 * @since 1.5.0
	 *
	 * @param string $slug  Server slug (sanitized key).
	 * @param string $label Human-readable label shown in the admin list.
	 * @return array{token:string,prefix:string,label:string,created_at:int}|WP_Error
	 *         Array with the raw token string (shown once only) on success,
	 *         WP_Error on failure.
	 */
	public static function generate( $slug, $label = '' ) {
		$slug = sanitize_key( $slug );
		if ( '' === $slug ) {
			return new WP_Error( 'invalid_slug', __( 'Server slug is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$tokens = self::load( $slug );
		if ( count( $tokens ) >= self::MAX_TOKENS ) {
			return new WP_Error(
				'token_limit',
				/* translators: %d: maximum number of tokens */
				sprintf( __( 'Maximum of %d tokens per server.', 'mcp-ai-wpoos-pro' ), self::MAX_TOKENS )
			);
		}

		// Generate a unique 8-char prefix and 40-char secret.
		$prefix = bin2hex( random_bytes( 4 ) );
		$secret = bin2hex( random_bytes( 20 ) );
		$raw    = self::TOKEN_PREFIX . $prefix . '.' . $secret;
		$now    = time();

		$tokens[] = array(
			'prefix'       => $prefix,
			'hash'         => wp_hash_password( $secret ),
			'label'        => sanitize_text_field( $label ),
			'created_at'   => $now,
			'last_used_at' => 0,
		);

		self::save( $slug, $tokens );

		return array(
			'token'      => $raw,
			'prefix'     => $prefix,
			'label'      => sanitize_text_field( $label ),
			'created_at' => $now,
		);
	}

	/**
	 * Revoke a token by its prefix.
	 *
	 * @since 1.5.0
	 *
	 * @param string $slug   Server slug.
	 * @param string $prefix 8-char token prefix.
	 * @return bool True if a token was removed, false if none matched.
	 */
	public static function revoke( $slug, $prefix ) {
		$slug   = sanitize_key( $slug );
		$prefix = sanitize_key( $prefix );
		$tokens = self::load( $slug );
		$before = count( $tokens );

		$tokens = array_values(
			array_filter(
				$tokens,
				static function ( $t ) use ( $prefix ) {
					return ! isset( $t['prefix'] ) || $t['prefix'] !== $prefix;
				}
			)
		);

		self::save( $slug, $tokens );

		return count( $tokens ) < $before;
	}

	/**
	 * Revoke all tokens for a server.
	 *
	 * @since 1.5.0
	 *
	 * @param string $slug Server slug.
	 * @return void
	 */
	public static function clear_all( $slug ) {
		self::save( sanitize_key( $slug ), array() );
	}

	/**
	 * Return token metadata for all active tokens on a server.
	 * The raw secret is never included.
	 *
	 * @since 1.5.0
	 *
	 * @param string $slug Server slug.
	 * @return array<int,array{prefix:string,label:string,created_at:int,last_used_at:int}>
	 */
	public static function list_tokens( $slug ) {
		$tokens = self::load( sanitize_key( $slug ) );

		return array_map(
			static function ( $t ) {
				return array(
					'prefix'       => isset( $t['prefix'] ) ? (string) $t['prefix'] : '',
					'label'        => isset( $t['label'] ) ? (string) $t['label'] : '',
					'created_at'   => isset( $t['created_at'] ) ? (int) $t['created_at'] : 0,
					'last_used_at' => isset( $t['last_used_at'] ) ? (int) $t['last_used_at'] : 0,
				);
			},
			$tokens
		);
	}

	/**
	 * Validate a raw bearer token against stored hashes for the given server.
	 * Updates `last_used_at` on success.
	 *
	 * @since 1.5.0
	 *
	 * @param string $slug      Server slug.
	 * @param string $raw_token Full token string (mcptk_{prefix}.{secret}).
	 * @return bool
	 */
	public static function validate( $slug, $raw_token ) {
		$slug = sanitize_key( $slug );
		if ( '' === $slug || '' === $raw_token ) {
			return false;
		}

		// Must start with token prefix.
		if ( 0 !== strpos( $raw_token, self::TOKEN_PREFIX ) ) {
			return false;
		}

		$without_prefix = substr( $raw_token, strlen( self::TOKEN_PREFIX ) );
		$dot_pos        = strpos( $without_prefix, '.' );
		if ( false === $dot_pos ) {
			return false;
		}

		$prefix = sanitize_key( substr( $without_prefix, 0, $dot_pos ) );
		$secret = substr( $without_prefix, $dot_pos + 1 );

		if ( '' === $prefix || '' === $secret ) {
			return false;
		}

		$tokens  = self::load( $slug );
		$changed = false;
		$valid   = false;

		foreach ( $tokens as &$entry ) {
			if ( ! isset( $entry['prefix'] ) || $entry['prefix'] !== $prefix ) {
				continue;
			}
			if ( wp_check_password( $secret, $entry['hash'] ) ) {
				$valid                 = true;
				$entry['last_used_at'] = time();
				$changed               = true;
			}
			break;
		}
		unset( $entry );

		if ( $changed ) {
			self::save( $slug, $tokens );
		}

		return $valid;
	}

	// -----------------------------------------------------------------------
	// Private helpers
	// -----------------------------------------------------------------------

	/**
	 * Load raw token records from the WP option.
	 *
	 * @param string $slug Sanitized server slug.
	 * @return array<int,array<string,mixed>>
	 */
	private static function load( $slug ) {
		$raw = get_option( self::OPTION_PREFIX . $slug, array() );
		return is_array( $raw ) ? $raw : array();
	}

	/**
	 * Persist raw token records to the WP option.
	 *
	 * @param string                         $slug   Sanitized server slug.
	 * @param array<int,array<string,mixed>> $tokens Token records.
	 * @return void
	 */
	private static function save( $slug, array $tokens ) {
		update_option( self::OPTION_PREFIX . $slug, $tokens, false );
	}
}
