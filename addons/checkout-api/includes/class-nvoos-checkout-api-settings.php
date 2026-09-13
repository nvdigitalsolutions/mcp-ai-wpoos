<?php
/**
 * Storefront settings for the checkout API.
 *
 * @package NV_oOS_Checkout_API
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Storefront settings.
 *
 * A single grouped option holds every tunable: Stripe keys, price,
 * currency, test mode, the addon version being sold, and the ZIP source
 * URL pattern. Constants may override the credentials:
 *
 *   define( 'NVOOS_CHECKOUT_STRIPE_SECRET_KEY', 'sk_live_…' );
 *   define( 'NVOOS_CHECKOUT_STRIPE_PUBLISHABLE_KEY', 'pk_live_…' );
 *   define( 'NVOOS_CHECKOUT_STRIPE_WEBHOOK_SECRET', 'whsec_…' );
 *
 * @since 0.1.0
 */
class NVOOS_Checkout_API_Settings {

	public const OPTION = 'nvoos_checkout_settings';

	public const DEFAULT_PRICE_CENTS   = 3499;
	public const DEFAULT_ADDON_VERSION = '1.1.74';

	/**
	 * Default legal-document URLs surfaced at checkout.
	 *
	 * Buyers must agree to the Terms of Service and acknowledge the Refund
	 * Policy before paying; the checkout client renders these links next to
	 * the consent checkbox and records the agreement timestamp with the
	 * license. Override via settings (or leave blank for the defaults).
	 */
	public const DEFAULT_TERMS_URL         = 'https://nvdigitalsolutions.com/terms-of-service';
	public const DEFAULT_REFUND_POLICY_URL = 'https://nvdigitalsolutions.com/refund-policy';

	/** Default Stripe product name used when auto-creating Product/Price. */
	public const DEFAULT_PRODUCT_NAME = 'NV oOS Complete';

	/**
	 * Default ZIP source pattern.
	 *
	 * Serves the NV oOS Complete bundle release asset by default;
	 * `{VERSION}` is replaced with the addon version of the license being
	 * served. Override with a local path or a private mirror via settings.
	 *
	 * @return string
	 */
	public static function default_zip_source(): string {
		return 'https://github.com/nvdigitalsolutions/mcp-ai-wpoos/releases/download/nvdigital-oos-v{VERSION}/nvdigital-open-operator-system-oos-complete-{VERSION}.zip';
	}

	/**
	 * Retrieve the full settings array merged with defaults.
	 *
	 * @return array<string,mixed>
	 */
	public static function all(): array {
		$defaults = array(
			'stripe_secret_key'       => '',
			'stripe_publishable_key'  => '',
			'stripe_webhook_secret'   => '',
			'price_cents'             => self::DEFAULT_PRICE_CENTS,
			'currency'                => 'usd',
			'test_mode'               => true,
			'addon_version'           => self::DEFAULT_ADDON_VERSION,
			'zip_source'              => self::default_zip_source(),
			'terms_url'               => self::DEFAULT_TERMS_URL,
			'refund_policy_url'       => self::DEFAULT_REFUND_POLICY_URL,
			'statement_descriptor'    => '',
			'product_name'            => self::DEFAULT_PRODUCT_NAME,
			'product_id'              => '',
			'price_id'                => '',
			'license_email_enabled'   => true,
			'license_email_subject'   => '',
			'license_email_from_name' => '',
			'license_email_from'      => '',
		);

		$stored = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $stored ) ? $stored : array(), $defaults );
	}

	/**
	 * Retrieve a single setting.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Fallback value.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = null ) {
		$all = self::all();
		return $all[ $key ] ?? $fallback;
	}

	/**
	 * The Stripe secret key (constant → setting).
	 *
	 * Stored encrypted at rest; never logged, never exposed by any endpoint.
	 *
	 * @return string
	 */
	public static function stripe_secret_key(): string {
		if ( defined( 'NVOOS_CHECKOUT_STRIPE_SECRET_KEY' ) ) {
			return (string) NVOOS_CHECKOUT_STRIPE_SECRET_KEY;
		}
		return NVOOS_Checkout_API_Crypto::decrypt( (string) self::get( 'stripe_secret_key', '' ) );
	}

	/**
	 * The Stripe publishable key (constant → setting).
	 *
	 * @return string
	 */
	public static function stripe_publishable_key(): string {
		if ( defined( 'NVOOS_CHECKOUT_STRIPE_PUBLISHABLE_KEY' ) ) {
			return (string) NVOOS_CHECKOUT_STRIPE_PUBLISHABLE_KEY;
		}
		return (string) self::get( 'stripe_publishable_key', '' );
	}

	/**
	 * The Stripe webhook signing secret (constant → setting).
	 *
	 * Stored encrypted at rest.
	 *
	 * @return string
	 */
	public static function stripe_webhook_secret(): string {
		if ( defined( 'NVOOS_CHECKOUT_STRIPE_WEBHOOK_SECRET' ) ) {
			return (string) NVOOS_CHECKOUT_STRIPE_WEBHOOK_SECRET;
		}
		return NVOOS_Checkout_API_Crypto::decrypt( (string) self::get( 'stripe_webhook_secret', '' ) );
	}

	/**
	 * Whether checkout is configured.
	 *
	 * @return bool
	 */
	public static function is_configured(): bool {
		return '' !== self::stripe_secret_key() && '' !== self::stripe_publishable_key();
	}

	/**
	 * Whether test mode is active.
	 *
	 * A live secret key always runs live; a test key always runs in test
	 * mode; otherwise the setting decides.
	 *
	 * @return bool
	 */
	public static function is_test_mode(): bool {
		$secret = self::stripe_secret_key();
		if ( str_starts_with( $secret, 'sk_test_' ) ) {
			return true;
		}
		if ( str_starts_with( $secret, 'sk_live_' ) ) {
			return false;
		}
		return (bool) self::get( 'test_mode', true );
	}

	/**
	 * The price in the smallest currency unit.
	 *
	 * @return int
	 */
	public static function price_cents(): int {
		return max( 50, (int) self::get( 'price_cents', self::DEFAULT_PRICE_CENTS ) );
	}

	/**
	 * The three-letter ISO currency code.
	 *
	 * @return string
	 */
	public static function currency(): string {
		$currency = strtolower( (string) self::get( 'currency', 'usd' ) );
		return preg_match( '/^[a-z]{3}$/', $currency ) ? $currency : 'usd';
	}

	/**
	 * The addon version being sold.
	 *
	 * @return string
	 */
	public static function addon_version(): string {
		$version = sanitize_text_field( (string) self::get( 'addon_version', self::DEFAULT_ADDON_VERSION ) );
		return '' !== $version ? $version : self::DEFAULT_ADDON_VERSION;
	}

	/**
	 * Resolve the ZIP source for a given addon version.
	 *
	 * @param string $version Addon version (replaces {VERSION}).
	 * @return string
	 */
	public static function zip_source_for( string $version ): string {
		return str_replace( '{VERSION}', $version, (string) self::get( 'zip_source', self::default_zip_source() ) );
	}

	/**
	 * The Terms of Service URL shown at checkout.
	 *
	 * Falls back to the default when unset or invalid — the consent flow
	 * must always have a terms link.
	 *
	 * @return string
	 */
	public static function terms_url(): string {
		$url = esc_url_raw( (string) self::get( 'terms_url', self::DEFAULT_TERMS_URL ) );
		return '' !== $url ? $url : self::DEFAULT_TERMS_URL;
	}

	/**
	 * The Refund Policy URL shown at checkout.
	 *
	 * Falls back to the default when unset or invalid.
	 *
	 * @return string
	 */
	public static function refund_policy_url(): string {
		$url = esc_url_raw( (string) self::get( 'refund_policy_url', self::DEFAULT_REFUND_POLICY_URL ) );
		return '' !== $url ? $url : self::DEFAULT_REFUND_POLICY_URL;
	}

	/**
	 * The card statement descriptor suffix, or '' to use Stripe's default.
	 *
	 * Stripe rejects the full `statement_descriptor` parameter for card
	 * charges created with automatic payment methods, so the stored value
	 * is sent as `statement_descriptor_suffix` instead: 2–22 characters
	 * containing at least one letter, appended to the account's statement
	 * descriptor prefix. Invalid values are dropped so Stripe's default
	 * applies.
	 *
	 * @return string
	 */
	public static function statement_descriptor(): string {
		$descriptor = strtoupper( (string) self::get( 'statement_descriptor', '' ) );
		$descriptor = preg_replace( '/[^A-Z0-9 ._+*,-]/', '', $descriptor ) ?? '';
		$descriptor = trim( $descriptor );
		if ( strlen( $descriptor ) < 2 || strlen( $descriptor ) > 22 ) {
			return '';
		}
		return 1 === preg_match( '/[A-Z]/', $descriptor ) ? $descriptor : '';
	}

	/**
	 * The Stripe product name used when auto-creating Product/Price objects.
	 *
	 * @return string
	 */
	public static function product_name(): string {
		$name = sanitize_text_field( (string) self::get( 'product_name', self::DEFAULT_PRODUCT_NAME ) );
		return '' !== $name ? $name : self::DEFAULT_PRODUCT_NAME;
	}

	/**
	 * The Stripe Product ID recorded on payment intents (reporting metadata).
	 *
	 * @return string
	 */
	public static function product_id(): string {
		return (string) self::get( 'product_id', '' );
	}

	/**
	 * The Stripe Price ID recorded on payment intents (reporting metadata).
	 *
	 * @return string
	 */
	public static function price_id(): string {
		return (string) self::get( 'price_id', '' );
	}

	/**
	 * Whether the license email is enabled.
	 *
	 * @since 0.1.2
	 *
	 * @return bool
	 */
	public static function license_email_enabled(): bool {
		return (bool) self::get( 'license_email_enabled', true );
	}

	/**
	 * Custom license email subject (empty = the per-product default).
	 *
	 * @since 0.1.2
	 *
	 * @return string
	 */
	public static function license_email_subject(): string {
		return sanitize_text_field( (string) self::get( 'license_email_subject', '' ) );
	}

	/**
	 * Custom From name for the license email (empty = WordPress default).
	 *
	 * @since 0.1.2
	 *
	 * @return string
	 */
	public static function license_email_from_name(): string {
		return sanitize_text_field( (string) self::get( 'license_email_from_name', '' ) );
	}

	/**
	 * Custom From address for the license email (empty = WordPress default).
	 *
	 * @since 0.1.2
	 *
	 * @return string
	 */
	public static function license_email_from(): string {
		$email = sanitize_email( (string) self::get( 'license_email_from', '' ) );
		return false !== is_email( $email ) ? $email : '';
	}

	/**
	 * Update a single non-secret settings field, bypassing the Settings API.
	 *
	 * Used by the admin-post handlers (e.g. auto-creating the Stripe
	 * Product/Price). Reads the stored option directly so encrypted
	 * credentials are never round-tripped through sanitization.
	 *
	 * @param string $key   Field key (product_id, price_id, product_name, statement_descriptor).
	 * @param string $value New value.
	 * @return bool True when the option was updated.
	 */
	public static function update_field( string $key, string $value ): bool {
		$allowed = array( 'product_id', 'price_id', 'product_name', 'statement_descriptor' );
		if ( ! in_array( $key, $allowed, true ) ) {
			return false;
		}

		$sanitized = self::sanitize( array( $key => $value ) );
		if ( ! array_key_exists( $key, $sanitized ) ) {
			return false;
		}

		$stored         = get_option( self::OPTION, array() );
		$stored         = is_array( $stored ) ? $stored : array();
		$stored[ $key ] = $sanitized[ $key ];

		return update_option( self::OPTION, $stored, false );
	}

	/**
	 * Sanitize the settings array on save.
	 *
	 * @param mixed $raw Raw submitted values.
	 * @return array<string,mixed>
	 */
	public static function sanitize( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$sanitized = array();

		// The Settings API replaces the whole option array with this return
		// value, so credentials must be explicitly preserved when the
		// submitted field is blank — the admin page renders the secret key
		// and webhook secret as masked, empty inputs ("leave blank to keep").
		$stored = get_option( self::OPTION, array() );
		$stored = is_array( $stored ) ? $stored : array();

		foreach ( array( 'stripe_secret_key', 'stripe_webhook_secret' ) as $key ) {
			if ( isset( $raw[ $key ] ) && is_string( $raw[ $key ] ) && '' !== trim( $raw[ $key ] ) ) {
				$sanitized[ $key ] = NVOOS_Checkout_API_Crypto::encrypt( sanitize_text_field( $raw[ $key ] ) );
			} elseif ( isset( $stored[ $key ] ) && is_string( $stored[ $key ] ) && '' !== $stored[ $key ] ) {
				// Blank input: keep the stored credential untouched.
				// Legacy plaintext values are upgraded to encrypted storage
				// on the next save; already-encrypted values pass through.
				$sanitized[ $key ] = str_starts_with( $stored[ $key ], NVOOS_Checkout_API_Crypto::PREFIX )
					? $stored[ $key ]
					: NVOOS_Checkout_API_Crypto::encrypt( $stored[ $key ] );
			}
		}

		if ( isset( $raw['stripe_publishable_key'] ) && is_string( $raw['stripe_publishable_key'] ) && '' !== trim( $raw['stripe_publishable_key'] ) ) {
			$sanitized['stripe_publishable_key'] = sanitize_text_field( $raw['stripe_publishable_key'] );
		}

		if ( isset( $raw['price_cents'] ) ) {
			$sanitized['price_cents'] = max( 50, absint( $raw['price_cents'] ) );
		}

		if ( isset( $raw['currency'] ) ) {
			$currency              = strtolower( sanitize_text_field( $raw['currency'] ) );
			$sanitized['currency'] = preg_match( '/^[a-z]{3}$/', $currency ) ? $currency : 'usd';
		}

		$sanitized['test_mode'] = ! empty( $raw['test_mode'] ) ? 1 : 0;

		if ( isset( $raw['addon_version'] ) ) {
			$version                    = sanitize_text_field( $raw['addon_version'] );
			$sanitized['addon_version'] = '' !== $version ? $version : self::DEFAULT_ADDON_VERSION;
		}

		if ( isset( $raw['zip_source'] ) ) {
			$source = trim( (string) $raw['zip_source'] );
			// Accept https URLs and absolute local paths only.
			if ( '' === $source || 0 === strpos( $source, 'https://' ) || 0 === strpos( $source, '/' ) ) {
				$sanitized['zip_source'] = $source;
			}
		}

		// Legal-document URLs: empty falls back to the built-in defaults via
		// the getters, so the checkout consent links can never disappear.
		foreach ( array( 'terms_url', 'refund_policy_url' ) as $key ) {
			if ( isset( $raw[ $key ] ) ) {
				$sanitized[ $key ] = esc_url_raw( (string) $raw[ $key ] );
			}
		}

		// Statement descriptor suffix: sanitized strictly; values that are
		// shorter than 2 or longer than 22 characters, or contain no letter,
		// are dropped (empty) so Stripe's default descriptor applies.
		if ( isset( $raw['statement_descriptor'] ) ) {
			$descriptor                        = strtoupper( (string) $raw['statement_descriptor'] );
			$descriptor                        = preg_replace( '/[^A-Z0-9 ._+*,-]/', '', $descriptor ) ?? '';
			$descriptor                        = trim( $descriptor );
			$valid                             = strlen( $descriptor ) >= 2 && strlen( $descriptor ) <= 22 && 1 === preg_match( '/[A-Z]/', $descriptor );
			$sanitized['statement_descriptor'] = $valid ? $descriptor : '';
		}

		if ( isset( $raw['product_name'] ) ) {
			$sanitized['product_name'] = sanitize_text_field( (string) $raw['product_name'] );
		}

		foreach ( array( 'product_id', 'price_id' ) as $key ) {
			if ( isset( $raw[ $key ] ) ) {
				$value             = (string) $raw[ $key ];
				$sanitized[ $key ] = preg_match( '/^[A-Za-z0-9_]+$/', $value ) ? $value : '';
			}
		}

		// License email (vendor → buyer confirmation with the license key).
		$sanitized['license_email_enabled'] = ! empty( $raw['license_email_enabled'] ) ? 1 : 0;

		if ( isset( $raw['license_email_subject'] ) ) {
			$sanitized['license_email_subject'] = sanitize_text_field( (string) $raw['license_email_subject'] );
		}

		if ( isset( $raw['license_email_from_name'] ) ) {
			$sanitized['license_email_from_name'] = sanitize_text_field( (string) $raw['license_email_from_name'] );
		}

		if ( isset( $raw['license_email_from'] ) ) {
			$sanitized['license_email_from'] = sanitize_email( (string) $raw['license_email_from'] );
		}

		return $sanitized;
	}
}
