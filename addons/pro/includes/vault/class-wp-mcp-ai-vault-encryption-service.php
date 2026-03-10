<?php
/**
 * Encryption Service for Password Vault with TOTP Support
 *
 * Implements OWASP-compliant cryptographic storage using AES-256-GCM.
 * Includes RFC 6238 TOTP (Time-based One-Time Password) authenticator support.
 *
 * Security Features:
 * - AES-256-GCM authenticated encryption (confidentiality + integrity)
 * - Per-user encryption keys (user isolation)
 * - PBKDF2 key derivation with 100,000 iterations
 * - Unique random IV per encryption operation
 * - Authentication tags for tamper detection
 * - RFC 6238 TOTP implementation (Google Authenticator compatible)
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 * @link https://cheatsheetseries.owasp.org/cheatsheets/Cryptographic_Storage_Cheat_Sheet.html
 * @link https://datatracker.ietf.org/doc/html/rfc6238
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles encryption, decryption, password generation, and TOTP operations.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Vault_Encryption_Service {

	/**
	 * PBKDF2 iteration count (OWASP minimum: 100,000 for PBKDF2-SHA256).
	 *
	 * @since 1.3.0
	 * @var int
	 */
	const PBKDF2_ITERATIONS = 100000;

	/**
	 * Encryption algorithm.
	 *
	 * @since 1.3.0
	 * @var string
	 */
	const CIPHER_METHOD = 'aes-256-gcm';

	/**
	 * TOTP time step in seconds (RFC 6238 default: 30 seconds).
	 *
	 * @since 1.3.0
	 * @var int
	 */
	const TOTP_TIME_STEP = 30;

	/**
	 * TOTP code length (standard: 6 digits).
	 *
	 * @since 1.3.0
	 * @var int
	 */
	const TOTP_CODE_LENGTH = 6;

	/**
	 * TOTP allowed time drift (±1 time step = ±30 seconds).
	 *
	 * @since 1.3.0
	 * @var int
	 */
	const TOTP_TIME_DRIFT = 1;

	/**
	 * Encrypt data using AES-256-GCM.
	 *
	 * Implements OWASP recommendations:
	 * - Uses authenticated encryption (GCM mode)
	 * - Generates unique random IV for each encryption
	 * - Returns authentication tag for integrity verification
	 *
	 * @since 1.3.0
	 *
	 * @param string $plaintext Data to encrypt.
	 * @param int    $user_id   User ID for key derivation.
	 * @return array|WP_Error Array with 'iv', 'ciphertext', 'auth_tag' or WP_Error on failure.
	 */
	public function encrypt( $plaintext, $user_id ) {
		if ( empty( $plaintext ) ) {
			return new WP_Error( 'empty_plaintext', __( 'Cannot encrypt empty data.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $user_id ) || ! is_numeric( $user_id ) ) {
			return new WP_Error( 'invalid_user_id', __( 'Valid user ID required for encryption.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get user-specific encryption key.
		$key = $this->get_user_encryption_key( $user_id );
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		// Generate cryptographically secure random IV (16 bytes for GCM).
		try {
			$iv = random_bytes( 16 );
		} catch ( Exception $e ) {
			return new WP_Error( 'iv_generation_failed', __( 'Failed to generate initialization vector.', 'mcp-ai-wpoos-pro' ) );
		}

		// Encrypt using AES-256-GCM.
		$auth_tag   = '';
		$ciphertext = openssl_encrypt(
			$plaintext,
			self::CIPHER_METHOD,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$auth_tag,
			'', // No additional authenticated data.
			16  // 128-bit authentication tag.
		);

		if ( false === $ciphertext ) {
			return new WP_Error( 'encryption_failed', __( 'Encryption operation failed.', 'mcp-ai-wpoos-pro' ) );
		}

		// Return base64-encoded components for database storage.
		return array(
			'iv'         => base64_encode( $iv ),
			'ciphertext' => base64_encode( $ciphertext ),
			'auth_tag'   => base64_encode( $auth_tag ),
		);
	}

	/**
	 * Decrypt data using AES-256-GCM.
	 *
	 * Verifies authentication tag to detect tampering.
	 *
	 * @since 1.3.0
	 *
	 * @param array $encrypted_data Array with 'iv', 'ciphertext', 'auth_tag'.
	 * @param int   $user_id        User ID for key derivation.
	 * @return string|WP_Error Decrypted plaintext or WP_Error on failure.
	 */
	public function decrypt( $encrypted_data, $user_id ) {
		// Validate input.
		if ( empty( $encrypted_data['iv'] ) || empty( $encrypted_data['ciphertext'] ) || empty( $encrypted_data['auth_tag'] ) ) {
			return new WP_Error( 'invalid_encrypted_data', __( 'Missing encryption components.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $user_id ) || ! is_numeric( $user_id ) ) {
			return new WP_Error( 'invalid_user_id', __( 'Valid user ID required for decryption.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get user-specific encryption key.
		$key = $this->get_user_encryption_key( $user_id );
		if ( is_wp_error( $key ) ) {
			return $key;
		}

		// Decode base64 components.
		$iv         = base64_decode( $encrypted_data['iv'], true );
		$ciphertext = base64_decode( $encrypted_data['ciphertext'], true );
		$auth_tag   = base64_decode( $encrypted_data['auth_tag'], true );

		if ( false === $iv || false === $ciphertext || false === $auth_tag ) {
			return new WP_Error( 'base64_decode_failed', __( 'Invalid base64 encoding.', 'mcp-ai-wpoos-pro' ) );
		}

		// Decrypt and verify authentication tag.
		$plaintext = openssl_decrypt(
			$ciphertext,
			self::CIPHER_METHOD,
			$key,
			OPENSSL_RAW_DATA,
			$iv,
			$auth_tag
		);

		if ( false === $plaintext ) {
			// Decryption failed - data may be corrupted or tampered with.
			return new WP_Error( 'decryption_failed', __( 'Decryption failed. Data may be corrupted or tampered with.', 'mcp-ai-wpoos-pro' ) );
		}

		return $plaintext;
	}

	/**
	 * Get or generate user-specific encryption key.
	 *
	 * Implements OWASP key derivation recommendations:
	 * - Uses PBKDF2-HMAC-SHA256 with 100,000 iterations
	 * - Unique per-user salt (32 bytes)
	 * - WordPress AUTH_KEY constant as master key material
	 * - 32-byte (256-bit) derived key for AES-256
	 *
	 * @since 1.3.0
	 *
	 * @param int $user_id User ID.
	 * @return string|WP_Error 32-byte encryption key or WP_Error on failure.
	 */
	private function get_user_encryption_key( $user_id ) {
		// Check if WordPress AUTH_KEY is defined (required for security).
		if ( ! defined( 'AUTH_KEY' ) || empty( AUTH_KEY ) ) {
			return new WP_Error( 'auth_key_missing', __( 'WordPress AUTH_KEY constant is not defined. Cannot derive encryption keys.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get or create user-specific salt.
		$user_salt = get_user_meta( $user_id, '_vault_encryption_salt', true );

		if ( empty( $user_salt ) ) {
			// Generate new 32-byte random salt for this user.
			try {
				$salt_bytes = random_bytes( 32 );
				$user_salt  = bin2hex( $salt_bytes );
			} catch ( Exception $e ) {
				return new WP_Error( 'salt_generation_failed', __( 'Failed to generate encryption salt.', 'mcp-ai-wpoos-pro' ) );
			}

			// Store salt in user meta.
			update_user_meta( $user_id, '_vault_encryption_salt', $user_salt );
		}

		// Prepare key derivation material.
		// Combines WordPress master key + user salt + user ID for uniqueness.
		$key_material = AUTH_KEY . $user_salt . $user_id;

		// Derive 32-byte key using PBKDF2-HMAC-SHA256.
		// OWASP recommendation: minimum 100,000 iterations for PBKDF2-SHA256.
		$derived_key = hash_pbkdf2(
			'sha256',
			$key_material,
			$user_salt,
			self::PBKDF2_ITERATIONS,
			32, // Key length in bytes (256 bits for AES-256).
			true // Return raw binary data.
		);

		if ( false === $derived_key ) {
			return new WP_Error( 'key_derivation_failed', __( 'Failed to derive encryption key.', 'mcp-ai-wpoos-pro' ) );
		}

		return $derived_key;
	}

	/**
	 * Generate secure random password.
	 *
	 * @since 1.3.0
	 *
	 * @param int  $length          Password length (12-128).
	 * @param bool $uppercase       Include uppercase letters.
	 * @param bool $lowercase       Include lowercase letters.
	 * @param bool $numbers         Include numbers.
	 * @param bool $symbols         Include symbols.
	 * @param bool $avoid_ambiguous Avoid ambiguous characters (0, O, l, I, etc.).
	 * @return string|WP_Error Generated password or WP_Error on failure.
	 */
	public function generate_password( $length = 20, $uppercase = true, $lowercase = true, $numbers = true, $symbols = true, $avoid_ambiguous = true ) {
		// Validate length.
		$length = absint( $length );
		if ( $length < 12 || $length > 128 ) {
			$length = 20;
		}

		// Build character sets.
		$charset = '';
		if ( $uppercase ) {
			$charset .= $avoid_ambiguous ? 'ABCDEFGHJKLMNPQRSTUVWXYZ' : 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
		}
		if ( $lowercase ) {
			$charset .= $avoid_ambiguous ? 'abcdefghjkmnpqrstuvwxyz' : 'abcdefghijklmnopqrstuvwxyz';
		}
		if ( $numbers ) {
			$charset .= $avoid_ambiguous ? '23456789' : '0123456789';
		}
		if ( $symbols ) {
			$charset .= '!@#$%^&*()_+-=[]{}|;:,.<>?';
		}

		if ( empty( $charset ) ) {
			return new WP_Error( 'no_charset', __( 'At least one character set must be enabled.', 'mcp-ai-wpoos-pro' ) );
		}

		// Generate password using cryptographically secure random.
		$password       = '';
		$charset_length = strlen( $charset );

		try {
			for ( $i = 0; $i < $length; $i++ ) {
				$random_index = random_int( 0, $charset_length - 1 );
				$password    .= $charset[ $random_index ];
			}
		} catch ( Exception $e ) {
			return new WP_Error( 'password_generation_failed', __( 'Failed to generate secure random password.', 'mcp-ai-wpoos-pro' ) );
		}

		return $password;
	}

	/**
	 * Calculate password strength score (0-4).
	 *
	 * @since 1.3.0
	 *
	 * @param string $password Password to analyze.
	 * @return int Strength score: 0=weak, 1=fair, 2=good, 3=strong, 4=very strong.
	 */
	public function calculate_password_strength( $password ) {
		$score = 0;

		// Length scoring.
		$length = strlen( $password );
		if ( $length >= 8 ) {
			++$score;
		}
		if ( $length >= 12 ) {
			++$score;
		}
		if ( $length >= 16 ) {
			++$score;
		}

		// Character diversity scoring.
		if ( preg_match( '/[a-z]/', $password ) ) {
			++$score;
		}
		if ( preg_match( '/[A-Z]/', $password ) ) {
			++$score;
		}
		if ( preg_match( '/[0-9]/', $password ) ) {
			++$score;
		}
		if ( preg_match( '/[^a-zA-Z0-9]/', $password ) ) {
			++$score;
		}

		// Normalize to 0-4 scale.
		return min( 4, (int) ( $score / 2 ) );
	}

	/*
	========================================================================
	 * TOTP (Time-based One-Time Password) Functions - RFC 6238
	 * Compatible with Google Authenticator, Authy, Microsoft Authenticator
	 * ======================================================================== */

	/**
	 * Generate a new TOTP secret (Base32 encoded).
	 *
	 * Creates a cryptographically secure random secret for TOTP authentication.
	 * Compatible with Google Authenticator and other RFC 6238 authenticator apps.
	 *
	 * @since 1.3.0
	 *
	 * @param int $length Secret length in bytes (default: 20 bytes = 160 bits).
	 * @return string|WP_Error Base32-encoded secret or WP_Error on failure.
	 */
	public function generate_totp_secret( $length = 20 ) {
		try {
			$random_bytes = random_bytes( $length );
		} catch ( Exception $e ) {
			return new WP_Error( 'totp_secret_failed', __( 'Failed to generate TOTP secret.', 'mcp-ai-wpoos-pro' ) );
		}

		// Encode to Base32 (RFC 4648).
		return $this->base32_encode( $random_bytes );
	}

	/**
	 * Generate current TOTP code for a secret.
	 *
	 * Implements RFC 6238 TOTP algorithm.
	 *
	 * @since 1.3.0
	 *
	 * @param string   $secret    Base32-encoded TOTP secret.
	 * @param int|null $timestamp Unix timestamp (null = current time).
	 * @return string|WP_Error 6-digit TOTP code or WP_Error on failure.
	 */
	public function generate_totp_code( $secret, $timestamp = null ) {
		if ( empty( $secret ) ) {
			return new WP_Error( 'invalid_secret', __( 'TOTP secret is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Use current time if not specified.
		if ( null === $timestamp ) {
			$timestamp = time();
		}

		// Calculate time counter (number of time steps since Unix epoch).
		$counter = floor( $timestamp / self::TOTP_TIME_STEP );

		// Decode Base32 secret.
		$decoded_secret = $this->base32_decode( $secret );
		if ( false === $decoded_secret ) {
			return new WP_Error( 'invalid_secret', __( 'Invalid TOTP secret format.', 'mcp-ai-wpoos-pro' ) );
		}

		// Generate HOTP code (TOTP is HOTP with time-based counter).
		return $this->generate_hotp_code( $decoded_secret, $counter );
	}

	/**
	 * Verify a TOTP code against a secret.
	 *
	 * Allows for time drift (±1 time step by default = ±30 seconds).
	 * Uses timing-safe comparison to prevent timing attacks.
	 *
	 * @since 1.3.0
	 *
	 * @param string $secret   Base32-encoded TOTP secret.
	 * @param string $code     6-digit code to verify.
	 * @param int    $window   Time drift window in steps (default: 1 = ±30 seconds).
	 * @return bool True if code is valid, false otherwise.
	 */
	public function verify_totp_code( $secret, $code, $window = self::TOTP_TIME_DRIFT ) {
		if ( empty( $secret ) || empty( $code ) ) {
			return false;
		}

		// Normalize code (remove spaces, ensure 6 digits).
		$code = str_replace( ' ', '', $code );
		if ( ! preg_match( '/^\d{6}$/', $code ) ) {
			return false;
		}

		$timestamp = time();

		// Check current time and ±window time steps.
		for ( $i = -$window; $i <= $window; $i++ ) {
			$check_timestamp = $timestamp + ( $i * self::TOTP_TIME_STEP );
			$expected_code   = $this->generate_totp_code( $secret, $check_timestamp );

			if ( is_wp_error( $expected_code ) ) {
				continue;
			}

			// Timing-safe comparison to prevent timing attacks.
			if ( hash_equals( $expected_code, $code ) ) {
				// RFC 6238 §5.2: each OTP MUST be accepted only once within its time window.
				// Record the used counter in a transient so a second request in the same
				// window is rejected even if it carries the identical code.
				$counter   = (int) floor( $check_timestamp / self::TOTP_TIME_STEP );
				$cache_key = 'vault_totp_used_' . wp_hash( $secret . '_' . $counter );
				if ( get_transient( $cache_key ) ) {
					return false; // Code already consumed in this time window.
				}
				// Keep the transient alive for three full time steps (covers clock drift).
				set_transient( $cache_key, 1, self::TOTP_TIME_STEP * 3 );
				return true;
			}
		}

		return false;
	}

	/**
	 * Generate otpauth:// URI for QR code generation.
	 *
	 * Creates a URI compatible with Google Authenticator and other authenticator apps.
	 *
	 * @since 1.3.0
	 *
	 * @param string $secret Base32-encoded TOTP secret.
	 * @param string $label  Account label (e.g., "user@example.com").
	 * @param string $issuer Issuer name (e.g., "My WordPress Site").
	 * @return string otpauth:// URI for QR code.
	 */
	public function get_totp_qr_code_uri( $secret, $label, $issuer = '' ) {
		if ( empty( $issuer ) ) {
			$issuer = get_bloginfo( 'name' );
		}

		// Encode parameters.
		$label_encoded  = rawurlencode( $label );
		$issuer_encoded = rawurlencode( $issuer );

		// Build otpauth:// URI.
		$uri = sprintf(
			'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
			$issuer_encoded,
			$label_encoded,
			$secret,
			$issuer_encoded,
			self::TOTP_CODE_LENGTH,
			self::TOTP_TIME_STEP
		);

		return $uri;
	}

	/**
	 * Generate HOTP code (RFC 4226).
	 *
	 * Helper function for TOTP implementation.
	 *
	 * @since 1.3.0
	 *
	 * @param string $secret  Binary secret key.
	 * @param int    $counter Counter value.
	 * @return string 6-digit code.
	 */
	private function generate_hotp_code( $secret, $counter ) {
		// Pack counter as 64-bit big-endian integer.
		$counter_bytes = pack( 'N*', 0 ) . pack( 'N*', $counter );

		// Calculate HMAC-SHA1.
		$hash = hash_hmac( 'sha1', $counter_bytes, $secret, true );

		// Dynamic truncation (RFC 4226 Section 5.3).
		$offset = ord( substr( $hash, -1 ) ) & 0x0F;
		$code   = (
			( ( ord( $hash[ $offset ] ) & 0x7F ) << 24 ) |
			( ( ord( $hash[ $offset + 1 ] ) & 0xFF ) << 16 ) |
			( ( ord( $hash[ $offset + 2 ] ) & 0xFF ) << 8 ) |
			( ord( $hash[ $offset + 3 ] ) & 0xFF )
		);

		// Extract 6-digit code.
		$code = $code % pow( 10, self::TOTP_CODE_LENGTH );

		// Pad with leading zeros if necessary.
		return str_pad( (string) $code, self::TOTP_CODE_LENGTH, '0', STR_PAD_LEFT );
	}

	/**
	 * Encode data to Base32 (RFC 4648).
	 *
	 * @since 1.3.0
	 *
	 * @param string $data Binary data to encode.
	 * @return string Base32-encoded string.
	 */
	private function base32_encode( $data ) {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$output   = '';
		$bits     = '';

		for ( $i = 0; $i < strlen( $data ); $i++ ) {
			$bits .= str_pad( decbin( ord( $data[ $i ] ) ), 8, '0', STR_PAD_LEFT );
		}

		// Split into 5-bit chunks.
		$chunks = str_split( $bits, 5 );

		foreach ( $chunks as $chunk ) {
			$chunk   = str_pad( $chunk, 5, '0', STR_PAD_RIGHT );
			$output .= $alphabet[ bindec( $chunk ) ];
		}

		return $output;
	}

	/**
	 * Decode Base32 string (RFC 4648).
	 *
	 * @since 1.3.0
	 *
	 * @param string $data Base32-encoded string.
	 * @return string|false Binary data or false on invalid input.
	 */
	private function base32_decode( $data ) {
		$alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
		$data     = strtoupper( $data );
		$bits     = '';

		for ( $i = 0; $i < strlen( $data ); $i++ ) {
			$pos = strpos( $alphabet, $data[ $i ] );
			if ( false === $pos ) {
				return false; // Invalid character.
			}
			$bits .= str_pad( decbin( $pos ), 5, '0', STR_PAD_LEFT );
		}

		// Split into 8-bit chunks.
		$chunks = str_split( $bits, 8 );
		$output = '';

		foreach ( $chunks as $chunk ) {
			if ( strlen( $chunk ) < 8 ) {
				break; // Skip padding.
			}
			$output .= chr( bindec( $chunk ) );
		}

		return $output;
	}
}
