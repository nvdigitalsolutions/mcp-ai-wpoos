<?php
/**
 * License email sender.
 *
 * @package NV_oOS_Checkout_API
 * @since   0.1.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends the buyer a confirmation email with their license details.
 *
 * Stripe already emails the payment receipt (the client sets
 * `receipt_email` on the PaymentIntent); this email is the vendor-side
 * license record: key, product, site binding, and amount. It is sent at
 * most once per license — the `email_sent_at` column on the license row is
 * the idempotency guard, so repeated /verify calls or webhook redeliveries
 * can never email the buyer twice.
 *
 * @since 0.1.2
 */
class NVOOS_Checkout_API_Mailer {

	/**
	 * Send the license email when the preconditions hold.
	 *
	 * Skips silently (returns false) when emailing is disabled in settings,
	 * when the license has no buyer email, or when the email was already
	 * sent. A failed send is not marked, so a later /verify retry tries
	 * again.
	 *
	 * @since 0.1.2
	 *
	 * @param array<string,mixed> $license License row.
	 * @return bool True when the email was sent (or had already been sent).
	 */
	public static function maybe_send( array $license ): bool {
		if ( ! NVOOS_Checkout_API_Settings::license_email_enabled() ) {
			return false;
		}

		// Re-read the stored row: the caller's array may be stale, and the
		// email_sent_at column is the idempotency guard. A synthetic
		// (not-yet-stored) shape falls through untouched.
		$stored = NVOOS_Checkout_API_License_Store::get_by_key( (string) ( $license['license_key'] ?? '' ) );
		if ( is_array( $stored ) ) {
			$license = $stored;
		}

		$to = sanitize_email( (string) ( $license['buyer_email'] ?? '' ) );
		if ( '' === $to ) {
			return false;
		}

		if ( ! empty( $license['email_sent_at'] ) ) {
			return true;
		}

		$sent = wp_mail( $to, self::subject( $license ), self::body( $license ), self::headers() );

		if ( ! $sent ) {
			// Never include the buyer's address in logs — the license key
			// alone identifies the failed send.
			error_log( sprintf( '[nvoos-checkout-api] license email send failed for license %s', (string) ( $license['license_key'] ?? '' ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Vendor-server diagnostics; no customer data logged.
			return false;
		}

		NVOOS_Checkout_API_License_Store::mark_email_sent( (string) $license['license_key'] );
		return true;
	}

	/**
	 * The email subject (custom setting, or a per-product default).
	 *
	 * @since 0.1.2
	 *
	 * @param array<string,mixed> $license License row.
	 * @return string
	 */
	private static function subject( array $license ): string {
		$custom = NVOOS_Checkout_API_Settings::license_email_subject();
		if ( '' !== $custom ) {
			return $custom;
		}

		/* translators: %s: product display name. */
		return sprintf( __( 'Your %s license', 'nvoos-checkout-api' ), self::product_label( $license ) );
	}

	/**
	 * The plain-text email body.
	 *
	 * @since 0.1.2
	 *
	 * @param array<string,mixed> $license License row.
	 * @return string
	 */
	private static function body( array $license ): string {
		$amount   = (int) ( $license['amount'] ?? 0 );
		$currency = strtoupper( (string) ( $license['currency'] ?? 'usd' ) );

		$lines = array(
			sprintf(
				/* translators: %s: product display name. */
				__( 'Thank you for purchasing %s!', 'nvoos-checkout-api' ),
				self::product_label( $license )
			),
			'',
			__( 'Your license details:', 'nvoos-checkout-api' ),
			sprintf(
				/* translators: %s: license key. */
				__( 'License key: %s', 'nvoos-checkout-api' ),
				(string) ( $license['license_key'] ?? '' )
			),
			sprintf(
				/* translators: %s: product display name. */
				__( 'Product: %s', 'nvoos-checkout-api' ),
				self::product_label( $license )
			),
			sprintf(
				/* translators: %s: licensed site URL. */
				__( 'Site: %s', 'nvoos-checkout-api' ),
				esc_url_raw( (string) ( $license['site_url'] ?? '' ) )
			),
			sprintf(
				/* translators: 1: amount, 2: currency code. */
				__( 'Amount paid: %1$s %2$s', 'nvoos-checkout-api' ),
				number_format( $amount / 100, 2 ),
				$currency
			),
			'',
			__( 'This license is tied to the site above and is already active there. To re-download or reinstall the package, open the purchase area in the plugin on that site — your license is recognized and no new payment is taken.', 'nvoos-checkout-api' ),
			'',
			__( 'Questions? Reply to this email and we will help.', 'nvoos-checkout-api' ),
			'',
			'— ' . self::from_name(),
		);

		return implode( "\n", $lines );
	}

	/**
	 * Mail headers: From (when configured) and Reply-To (always).
	 *
	 * @since 0.1.2
	 *
	 * @return array<int,string>
	 */
	private static function headers(): array {
		$headers = array();

		$from_email = NVOOS_Checkout_API_Settings::license_email_from();
		if ( '' !== $from_email ) {
			$headers[] = 'From: ' . self::from_name() . ' <' . $from_email . '>';
		}

		$reply_to = '' !== $from_email ? $from_email : sanitize_email( (string) get_option( 'admin_email', '' ) );
		if ( '' !== $reply_to ) {
			$headers[] = 'Reply-To: ' . $reply_to;
		}

		return $headers;
	}

	/**
	 * The sender display name (setting → site name → hard default).
	 *
	 * @since 0.1.2
	 *
	 * @return string
	 */
	private static function from_name(): string {
		$name = NVOOS_Checkout_API_Settings::license_email_from_name();
		if ( '' !== $name ) {
			return $name;
		}

		$site_name = sanitize_text_field( (string) get_option( 'blogname', '' ) );
		return '' !== $site_name ? $site_name : __( 'NV Digital Solutions', 'nvoos-checkout-api' );
	}

	/**
	 * Display name for a product slug.
	 *
	 * @since 0.1.2
	 *
	 * @param array<string,mixed> $license License row.
	 * @return string
	 */
	private static function product_label( array $license ): string {
		$labels = array(
			'nvoos-oos-complete'     => 'NV oOS Complete',
			'nvoos-content-graph-ai' => 'NV oOS Content Graph AI',
		);

		$slug = (string) ( $license['product'] ?? '' );
		return $labels[ $slug ] ?? 'NV oOS';
	}

	/** Private constructor — not instantiable. */
	private function __construct() {}
}
