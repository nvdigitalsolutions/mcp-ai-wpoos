<?php
/**
 * Signed download-token issue/verify.
 *
 * @package NV_oOS_Checkout_API
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HMAC-signed, expiring download tokens.
 *
 * A download URL carries three values: the license key, an expiry
 * timestamp, and an HMAC-SHA256 over both keyed with the site's secret
 * keys. Verification is constant-time (hash_equals) and rejects expired
 * tokens, so a captured download URL stops working after the TTL.
 *
 * @since 0.1.0
 */
class NVOOS_Checkout_API_Token {

	/** Default token lifetime in seconds. */
	public const DEFAULT_TTL = HOUR_IN_SECONDS;

	/**
	 * Signing secret derived from WordPress secret keys.
	 *
	 * @return string
	 */
	private static function secret(): string {
		// wp_salt() returns the defined *_KEY constants on a normal install
		// and a stable fallback when wp-config omits them (never a fatal).
		return wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
	}

	/**
	 * Issue a token for a license key.
	 *
	 * @param string $license_key License key.
	 * @param int    $ttl         Lifetime in seconds.
	 * @return array{expires: int, token: string}
	 */
	public static function issue( string $license_key, int $ttl = self::DEFAULT_TTL ): array {
		$expires = time() + $ttl;
		return array(
			'expires' => $expires,
			'token'   => self::sign( $license_key, $expires ),
		);
	}

	/**
	 * Verify a token for a license key.
	 *
	 * @param string $license_key License key.
	 * @param int    $expires     Expiry timestamp.
	 * @param string $token       Token value.
	 * @return bool
	 */
	public static function verify( string $license_key, int $expires, string $token ): bool {
		if ( $expires < time() ) {
			return false;
		}

		return hash_equals( self::sign( $license_key, $expires ), $token );
	}

	/**
	 * Compute the HMAC for a license + expiry pair.
	 *
	 * @param string $license_key License key.
	 * @param int    $expires     Expiry timestamp.
	 * @return string
	 */
	private static function sign( string $license_key, int $expires ): string {
		return hash_hmac( 'sha256', $license_key . '|' . $expires, self::secret() );
	}

	/**
	 * Build the signed download URL for a license.
	 *
	 * @param string $license_key License key.
	 * @param int    $ttl         Lifetime in seconds.
	 * @return string
	 */
	public static function download_url( string $license_key, int $ttl = self::DEFAULT_TTL ): string {
		$issued = self::issue( $license_key, $ttl );
		return add_query_arg(
			array(
				'nvoos_checkout_download' => '1',
				'license'                 => rawurlencode( $license_key ),
				'expires'                 => $issued['expires'],
				'token'                   => rawurlencode( $issued['token'] ),
			),
			home_url( '/' )
		);
	}
}
