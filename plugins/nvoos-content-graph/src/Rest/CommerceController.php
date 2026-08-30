<?php
declare(strict_types=1);

namespace NvoosContentGraph\Rest;

use NvoosContentGraph\Commerce\Installer;
use NvoosContentGraph\Commerce\License;
use NvoosContentGraph\Commerce\Payments;
use NvoosContentGraph\Commerce\Vendor;
use NvoosContentGraph\Schema;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

use function current_user_can;
use function do_action;
use function esc_url_raw;
use function get_current_user_id;
use function get_transient;
use function home_url;
use function is_wp_error;
use function register_rest_route;
use function rest_ensure_response;
use function sanitize_text_field;
use function set_transient;
use function time;
use function wp_get_current_user;
use function wp_parse_url;

/**
 * REST controller for the addon purchase flow.
 *
 * Routes (all admin-only, cookie auth + X-WP-Nonce):
 *   POST /payments/session — start a checkout session via the vendor API.
 *   POST /payments/verify  — verify the paid intent, record the license, install the addon.
 *
 * All Stripe communication (PaymentIntent creation, secret keys,
 * server-side verification) happens on the vendor's server — see
 * {@see Vendor}. This plugin only forwards the minimal payload.
 *
 * @since 1.0.4
 */
class CommerceController {

	private const THROTTLE_WINDOW = 10 * MINUTE_IN_SECONDS;
	private const THROTTLE_MAX    = 5;

	/**
	 * Register the payment routes.
	 *
	 * Called once on rest_api_init by {@see \NvoosContentGraph\Plugin::register()}.
	 *
	 * @since 1.0.4
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			Schema::REST_NAMESPACE,
			'/payments/session',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'createSession' ),
				'permission_callback' => array( $this, 'checkPermission' ),
			)
		);

		register_rest_route(
			Schema::REST_NAMESPACE,
			'/payments/verify',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'verifyPayment' ),
				'permission_callback' => array( $this, 'checkPermission' ),
				'args'                => array(
					'payment_intent' => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && 1 === preg_match( '/^pi_[A-Za-z0-9]{8,}$/', $value );
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * Permission callback: administrators only.
	 *
	 * @since 1.0.4
	 *
	 * @return bool|WP_Error
	 */
	public function checkPermission() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error(
			'nvoos_content_graph_forbidden',
			__( 'Administrator access required.', 'nvoos-content-graph' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Start a checkout session via the vendor API.
	 *
	 * The vendor returns the client_secret and publishable key that the
	 * browser needs to mount Stripe's Payment Element. No Stripe keys
	 * are stored or configured in this plugin.
	 *
	 * @since 1.0.4
	 *
	 * @param WP_REST_Request $request Unused.
	 * @return WP_REST_Response|WP_Error
	 */
	public function createSession( WP_REST_Request $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- REST callback signature
		if ( ! $this->passesThrottle() ) {
			return new WP_Error(
				'nvoos_content_graph_rate_limited',
				__( 'Too many checkout attempts. Please wait a few minutes and try again.', 'nvoos-content-graph' ),
				array( 'status' => 429 )
			);
		}

		if ( ! Payments::isConfigured() ) {
			return new WP_Error(
				'nvoos_content_graph_checkout_unavailable',
				__( 'Checkout is not available on this build. Please contact the plugin vendor.', 'nvoos-content-graph' ),
				array( 'status' => 424 )
			);
		}

		$vendor = new Vendor( Payments::vendorApiUrl() );
		$session = $vendor->createSession();

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		if ( empty( $session['client_secret'] ) || empty( $session['publishable_key'] ) ) {
			return new WP_Error(
				'nvoos_content_graph_checkout_session_failed',
				__( 'The checkout service did not return a payment session. Please try again.', 'nvoos-content-graph' ),
				array( 'status' => 502 )
			);
		}

		return rest_ensure_response(
			array(
				'client_secret'   => sanitize_text_field( (string) $session['client_secret'] ),
				'publishable_key' => sanitize_text_field( (string) $session['publishable_key'] ),
				'amount'          => (int) ( $session['amount'] ?? Payments::priceCents() ),
				'currency'        => sanitize_text_field( (string) ( $session['currency'] ?? Payments::currency() ) ),
				'test_mode'       => (bool) ( $session['test_mode'] ?? false ),
			)
		);
	}

	/**
	 * Verify a completed payment, record the license, and install the addon.
	 *
	 * The vendor re-verifies the PaymentIntent server-side (status, amount,
	 * product, site binding) and returns a license key plus a signed
	 * download URL. This endpoint is idempotent: an already-licensed site
	 * returns the current state without contacting the vendor.
	 *
	 * @since 1.0.4
	 *
	 * @param WP_REST_Request $request Request with `payment_intent` param.
	 * @return WP_REST_Response|WP_Error
	 */
	public function verifyPayment( WP_REST_Request $request ) {
		$intentId = (string) $request->get_param( 'payment_intent' );

		if ( License::isLicensed() && Installer::isActive() ) {
			return rest_ensure_response(
				array(
					'licensed'    => true,
					'installed'   => true,
					'activated'   => true,
					'license_key' => License::licenseKey(),
					'message'     => __( 'NV oOS Content Graph — AI is already licensed and active on this site.', 'nvoos-content-graph' ),
				)
			);
		}

		if ( ! Payments::isConfigured() ) {
			return new WP_Error(
				'nvoos_content_graph_checkout_unavailable',
				__( 'Checkout is not available on this build. Please contact the plugin vendor.', 'nvoos-content-graph' ),
				array( 'status' => 424 )
			);
		}

		$vendor = new Vendor( Payments::vendorApiUrl() );
		$result = $vendor->verify( $intentId );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['license_key'] ) ) {
			return new WP_Error(
				'nvoos_content_graph_license_issue_failed',
				__( 'The checkout service did not issue a license. Please contact support.', 'nvoos-content-graph' ),
				array( 'status' => 502 )
			);
		}

		// ─── Record the license ─────────────────────────────────────
		$user = wp_get_current_user();

		$record = array(
			'license_key'           => sanitize_text_field( (string) $result['license_key'] ),
			'stripe_payment_intent' => $intentId,
			'amount_received'       => (int) ( $result['amount'] ?? 0 ),
			'currency'              => sanitize_text_field( (string) ( $result['currency'] ?? Payments::currency() ) ),
			'addon_version'         => sanitize_text_field( (string) ( $result['addon_version'] ?? Payments::addonVersion() ) ),
			'site_url'              => home_url( '' ),
			'purchaser_id'          => get_current_user_id(),
			'purchaser_email'       => sanitize_text_field( (string) $user->user_email ),
			'purchased_at'          => time(),
		);

		License::save( $record );

		/**
		 * Fires after a successful addon purchase is recorded.
		 *
		 * The AI addon can hook into this to reactivate itself or sync the
		 * license key with its own storage.
		 *
		 * @since 1.0.4
		 *
		 * @param array<string,mixed> $record The purchase record that was saved.
		 */
		do_action( 'nvoos_content_graph/payments/purchase_recorded', $record );

		// ─── Install the addon ──────────────────────────────────────
		$zipUrl = self::sanitizeZipUrl(
			(string) ( $result['download_url'] ?? '' )
		);
		if ( '' === $zipUrl ) {
			$zipUrl = Payments::zipUrl();
		}

		$install = Installer::install( $zipUrl );

		if ( is_wp_error( $install ) ) {
			$data = $install->get_error_data();
			return new WP_Error(
				$install->get_error_code(),
				$install->get_error_message()
					. ' '
					. __( 'Your license is recorded — you can also download the addon ZIP manually and upload it on the Plugins screen.', 'nvoos-content-graph' ),
				array(
					'status'   => 500,
					'zip_url'  => is_array( $data ) && isset( $data['zip_url'] ) ? $data['zip_url'] : $zipUrl,
					'licensed' => true,
				)
			);
		}

		return rest_ensure_response(
			array(
				'licensed'    => true,
				'installed'   => (bool) $install['installed'],
				'activated'   => (bool) $install['activated'],
				'license_key' => $record['license_key'],
				'message'     => (string) $install['message'],
			)
		);
	}

	/**
	 * Allow only https download URLs from the vendor response.
	 *
	 * The vendor is trusted, but a cheap scheme check keeps a
	 * misconfigured vendor endpoint from pointing the installer at
	 * arbitrary local resources.
	 *
	 * @since 1.0.4
	 *
	 * @param string $url Candidate URL.
	 * @return string The URL when https, empty string otherwise.
	 */
	private static function sanitizeZipUrl( string $url ): string {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( $parts['scheme'] ?? '' ) ) {
			return '';
		}
		return esc_url_raw( $url );
	}

	/**
	 * Cheap per-user throttle on checkout-session creation.
	 *
	 * @since 1.0.4
	 *
	 * @return bool True when the request may proceed.
	 */
	private function passesThrottle(): bool {
		$key   = Schema::TRANSIENT_PREFIX . 'commerce_throttle_' . get_current_user_id();
		$count = (int) get_transient( $key );

		if ( $count >= self::THROTTLE_MAX ) {
			return false;
		}

		set_transient( $key, $count + 1, self::THROTTLE_WINDOW );
		return true;
	}
}
