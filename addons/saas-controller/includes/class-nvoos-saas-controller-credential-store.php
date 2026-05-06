<?php
/**
 * Credential store for the NV oOS SaaS Controller addon.
 *
 * Encrypts operator credentials (Cloudflare API token, Cloudflare account ID,
 * Stripe secret key, Stripe webhook signing secret, OpenRouter API key) at
 * rest using AES-256-CBC with a key derived from `AUTH_KEY + SECURE_AUTH_KEY`,
 * mirroring the production pattern used by `WP_MCP_AI_NV_Cloud_Service`.
 *
 * Falls back to base64 obfuscation when the `openssl` extension is missing —
 * the same envelope convention (`enc:` / `b64:`) that the rest of the codebase
 * uses, so an upgrade from a sodium-less host transparently works.
 *
 * @package NV_oOS_SaaS_Controller
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persists encrypted operator credentials in `wp_options`.
 *
 * @since 0.1.0
 */
class NVOOS_SaaS_Controller_Credential_Store {

	/**
	 * Option name that holds the encrypted credentials envelope.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'nvoos_saas_controller_credentials';

	/**
	 * Whitelisted credential keys.
	 *
	 * Keeps the surface area tight: arbitrary strings cannot be smuggled into
	 * the option via the REST endpoint.
	 *
	 * @var string[]
	 */
	const ALLOWED_KEYS = array(
		'cloudflare_account_id',
		'cloudflare_api_token',
		'stripe_secret_key',
		'stripe_webhook_secret',
		'openrouter_api_key',
		// Phase 6: optional provisioning key used by the OpenRouter mutating
		// client to create / list runtime API keys. Distinct from the
		// regular `openrouter_api_key` (which is a runtime key itself and
		// cannot manage other keys). Only the provisioning key has scope
		// over `/api/v1/keys`.
		'openrouter_provisioning_key',
	);

	/**
	 * Return the singleton instance.
	 *
	 * @since 0.1.0
	 *
	 * @return self
	 */
	public static function instance() {
		static $instance = null;
		if ( null === $instance ) {
			$instance = new self();
		}
		return $instance;
	}

	/**
	 * Get all credentials in plaintext.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string,string> Keyed by ALLOWED_KEYS; missing keys are returned as ''.
	 */
	public function get_all() {
		$envelope = get_option( self::OPTION_KEY, array() );
		$out      = array();
		foreach ( self::ALLOWED_KEYS as $key ) {
			$cipher      = isset( $envelope[ $key ] ) ? (string) $envelope[ $key ] : '';
			$out[ $key ] = '' === $cipher ? '' : $this->decrypt( $cipher );
		}
		return $out;
	}

	/**
	 * Get a redacted/masked snapshot suitable for the admin UI and REST GET.
	 *
	 * Each value is replaced by either '' (not configured) or a masked string
	 * showing only the last four characters.
	 *
	 * @since 0.1.0
	 *
	 * @return array<string,array{configured:bool,masked:string}>
	 */
	public function get_masked() {
		$plain = $this->get_all();
		$out   = array();
		foreach ( self::ALLOWED_KEYS as $key ) {
			$value       = $plain[ $key ];
			$configured  = '' !== $value;
			$out[ $key ] = array(
				'configured' => $configured,
				'masked'     => $configured ? $this->mask( $value ) : '',
			);
		}
		return $out;
	}

	/**
	 * Persist a partial set of credentials. Empty/missing keys are left
	 * untouched (use {@see clear_key()} or {@see clear_all()} to remove).
	 *
	 * @since 0.1.0
	 *
	 * @param array<string,string> $values Map of credential key → plaintext value.
	 * @return bool True when the option was updated (or already in the desired state).
	 */
	public function set( array $values ) {
		$envelope = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $envelope ) ) {
			$envelope = array();
		}
		foreach ( self::ALLOWED_KEYS as $key ) {
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}
			$incoming = (string) $values[ $key ];
			if ( '' === $incoming ) {
				continue;
			}
			$envelope[ $key ] = $this->encrypt( $incoming );
		}
		return (bool) update_option( self::OPTION_KEY, $envelope, false );
	}

	/**
	 * Clear a single credential.
	 *
	 * @since 0.1.0
	 *
	 * @param string $key Credential key.
	 * @return bool True if the key existed and was removed.
	 */
	public function clear_key( $key ) {
		if ( ! in_array( $key, self::ALLOWED_KEYS, true ) ) {
			return false;
		}
		$envelope = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $envelope ) || ! isset( $envelope[ $key ] ) ) {
			return false;
		}
		unset( $envelope[ $key ] );
		return (bool) update_option( self::OPTION_KEY, $envelope, false );
	}

	/**
	 * Clear every credential (used by the "Disconnect" / reset flow).
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public function clear_all() {
		return (bool) delete_option( self::OPTION_KEY );
	}

	/**
	 * Mask a credential for display.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value Plaintext credential.
	 * @return string Masked representation, e.g. "••••abcd".
	 */
	protected function mask( $value ) {
		$length = strlen( $value );
		if ( $length <= 4 ) {
			return str_repeat( '•', $length );
		}
		return str_repeat( '•', max( 4, $length - 4 ) ) . substr( $value, -4 );
	}

	/**
	 * Derive a 32-byte symmetric key from WordPress salts.
	 *
	 * @since 0.1.0
	 *
	 * @return string Raw 32-byte key.
	 */
	protected function get_encryption_key() {
		$secret = '';
		if ( defined( 'AUTH_KEY' ) && AUTH_KEY ) {
			$secret .= (string) AUTH_KEY;
		}
		if ( defined( 'SECURE_AUTH_KEY' ) && SECURE_AUTH_KEY ) {
			$secret .= (string) SECURE_AUTH_KEY;
		}
		if ( '' === $secret ) {
			$secret = (string) get_option( 'siteurl', '' );
		}
		return hash( 'sha256', 'nvoos_saas_controller|' . $secret, true );
	}

	/**
	 * Encrypt a plaintext credential.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value Plaintext.
	 * @return string Envelope `enc:<b64(iv|cipher)>` or `b64:<b64>` fallback.
	 */
	protected function encrypt( $value ) {
		if ( '' === $value ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) || ! function_exists( 'openssl_random_pseudo_bytes' ) ) {
			return 'b64:' . base64_encode( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		$key    = $this->get_encryption_key();
		$iv_len = openssl_cipher_iv_length( 'aes-256-cbc' );
		if ( false === $iv_len ) {
			return 'b64:' . base64_encode( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		$iv     = openssl_random_pseudo_bytes( $iv_len );
		$cipher = openssl_encrypt( $value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		if ( false === $cipher ) {
			return 'b64:' . base64_encode( $value ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		return 'enc:' . base64_encode( $iv . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt an envelope produced by {@see encrypt()}.
	 *
	 * @since 0.1.0
	 *
	 * @param string $value Envelope string.
	 * @return string Plaintext, or '' on failure.
	 */
	protected function decrypt( $value ) {
		if ( '' === $value ) {
			return '';
		}
		if ( 0 === strpos( $value, 'b64:' ) ) {
			$decoded = base64_decode( substr( $value, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			return false === $decoded ? '' : (string) $decoded;
		}
		if ( 0 !== strpos( $value, 'enc:' ) ) {
			// Legacy/raw plaintext write — return as-is for read-back compatibility.
			return $value;
		}
		if ( ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$payload = base64_decode( substr( $value, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $payload ) {
			return '';
		}
		$iv_len = openssl_cipher_iv_length( 'aes-256-cbc' );
		if ( false === $iv_len || strlen( $payload ) <= $iv_len ) {
			return '';
		}
		$iv     = substr( $payload, 0, $iv_len );
		$cipher = substr( $payload, $iv_len );
		$key    = $this->get_encryption_key();
		$plain  = openssl_decrypt( $cipher, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		return false === $plain ? '' : (string) $plain;
	}
}
