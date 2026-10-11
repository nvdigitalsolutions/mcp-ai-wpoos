<?php
/**
 * NV oOS Docs Hub — Checkout REST Controller
 *
 * Payment routes that power the NV oOS Complete purchase modal. Mirrors
 * the Content Graph commerce controller
 * (plugins/nvoos-content-graph/src/Rest/CommerceController.php) with
 * docs-hub naming and the docs-hub REST namespace.
 *
 * @package NV_oOS_Docs_Hub
 * @since   0.5.2
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Checkout REST controller for the Docs Hub addon.
 *
 * Exposes admin-only routes under /wp-json/nvoos-docs/v1/payments/ that
 * proxy the vendor checkout API. No Stripe keys are stored or configured
 * in this plugin.
 *
 * @since 0.5.2
 */
class NV_oOS_Docs_Hub_Checkout_REST {

	/**
	 * Per-user throttle window (seconds).
	 *
	 * @var int
	 */
	const THROTTLE_WINDOW = 10 * MINUTE_IN_SECONDS;

	/**
	 * Register the payment routes.
	 *
	 * Called once on rest_api_init by
	 * {@see NV_oOS_Docs_Hub_Plugin::init_rest()}.
	 *
	 * @since 0.5.2
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			NV_oOS_Docs_Hub_REST::NAMESPACE,
			'/payments/session',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'create_session' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);

		register_rest_route(
			NV_oOS_Docs_Hub_REST::NAMESPACE,
			'/payments/verify',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( __CLASS__, 'verify_payment' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
				'args'                => array(
					'payment_intent'  => array(
						'required'          => true,
						'type'              => 'string',
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && 1 === preg_match( '/^pi_[A-Za-z0-9]{8,}$/', $value );
						},
						'sanitize_callback' => 'sanitize_text_field',
					),
					'terms_agreed_at' => array(
						'type'              => 'integer',
						'validate_callback' => static function ( $value ) {
							if ( ! is_numeric( $value ) ) {
								return false;
							}

							$ts = (int) $value;
							return $ts > 0
								&& $ts >= time() - 7 * DAY_IN_SECONDS
								&& $ts <= time() + 10 * MINUTE_IN_SECONDS;
						},
						'sanitize_callback' => 'absint',
					),
					'buyer_email'     => array(
						'type'              => 'string',
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && ( '' === $value || false !== is_email( $value ) );
						},
						'sanitize_callback' => 'sanitize_email',
					),
					'buyer_country'   => array(
						'type'              => 'string',
						'validate_callback' => static function ( $value ) {
							return is_string( $value ) && ( '' === $value || 1 === preg_match( '/^[A-Z]{2}$/', $value ) );
						},
						'sanitize_callback' => static function ( $value ) {
							return strtoupper( sanitize_text_field( $value ) );
						},
					),
				),
			)
		);

		register_rest_route(
			NV_oOS_Docs_Hub_REST::NAMESPACE,
			'/payments/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'check_health' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);
	}

	/**
	 * Permission callback: administrators only.
	 *
	 * @since 0.5.2
	 *
	 * @return bool|WP_Error
	 */
	public static function check_permission() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error(
			'nvoos_docs_hub_checkout_forbidden',
			__( 'Administrator access required.', 'nvoos-docs-hub' ),
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
	 * @since 0.5.2
	 *
	 * @param WP_REST_Request $request Unused.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function create_session( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- REST callback signature
		// Already-licensed site: never create a chargeable session again.
		// The purchase modal renders the recorded license instead of a
		// payment form, so a second charge is impossible from this screen.
		if ( NV_oOS_Docs_Hub_Checkout_License::is_licensed() && NV_oOS_Docs_Hub_Checkout_Installer::is_bundle_active() ) {
			return rest_ensure_response(
				array(
					'already_licensed' => true,
					'bundle_active'    => true,
					'license_key'      => NV_oOS_Docs_Hub_Checkout_License::license_key(),
					'message'          => __( 'NV oOS Complete is already licensed and active on this site.', 'nvoos-docs-hub' ),
				)
			);
		}

		if ( ! self::passes_throttle( 'session', 5 ) ) {
			return new WP_Error(
				'nvoos_docs_hub_checkout_rate_limited',
				__( 'Too many checkout attempts. Please wait a few minutes and try again.', 'nvoos-docs-hub' ),
				array( 'status' => 429 )
			);
		}

		if ( ! NV_oOS_Docs_Hub_Checkout::is_configured() ) {
			return new WP_Error(
				'nvoos_docs_hub_checkout_unavailable',
				__( 'Checkout is not available on this build. Please contact the plugin vendor.', 'nvoos-docs-hub' ),
				array( 'status' => 424 )
			);
		}

		$client  = new NV_oOS_Docs_Hub_Checkout_Client( NV_oOS_Docs_Hub_Checkout::vendor_api_url() );
		$session = $client->create_session();

		if ( is_wp_error( $session ) ) {
			return $session;
		}

		if ( empty( $session['client_secret'] ) || empty( $session['publishable_key'] ) ) {
			return new WP_Error(
				'nvoos_docs_hub_checkout_session_failed',
				__( 'The checkout service did not return a payment session. Please try again.', 'nvoos-docs-hub' ),
				array( 'status' => 502 )
			);
		}

		return rest_ensure_response(
			array(
				'client_secret'     => sanitize_text_field( (string) $session['client_secret'] ),
				'publishable_key'   => sanitize_text_field( (string) $session['publishable_key'] ),
				'amount'            => (int) ( isset( $session['amount'] ) ? $session['amount'] : NV_oOS_Docs_Hub_Checkout::price_cents() ),
				'currency'          => sanitize_text_field( (string) ( isset( $session['currency'] ) ? $session['currency'] : NV_oOS_Docs_Hub_Checkout::currency() ) ),
				'test_mode'         => (bool) ( isset( $session['test_mode'] ) ? $session['test_mode'] : false ),
				'terms_url'         => self::sanitize_legal_url( (string) ( isset( $session['terms_url'] ) ? $session['terms_url'] : '' ), NV_oOS_Docs_Hub_Checkout::terms_url(), 'https://nvdigitalsolutions.com/terms-of-service' ),
				'refund_policy_url' => self::sanitize_legal_url( (string) ( isset( $session['refund_policy_url'] ) ? $session['refund_policy_url'] : '' ), NV_oOS_Docs_Hub_Checkout::refund_policy_url(), 'https://nvdigitalsolutions.com/refund-policy' ),
			)
		);
	}

	/**
	 * Connectivity probe: can this site reach the vendor checkout API?
	 *
	 * Calls the vendor's public `GET /health` endpoint and reports
	 * reachability, round-trip latency, and the vendor's own status
	 * payload. Deliberately **not** throttled: the session/verify buckets
	 * exist to protect the purchase flow, and a diagnostic probe that
	 * consumed them would make the "Too many checkout attempts" lockout
	 * even harder to debug.
	 *
	 * @since 0.5.2
	 *
	 * @return WP_REST_Response
	 */
	public static function check_health() {
		$configured = NV_oOS_Docs_Hub_Checkout::is_configured();
		$base       = array(
			'configured'     => $configured,
			'vendor_api_url' => NV_oOS_Docs_Hub_Checkout::vendor_api_url(),
		);

		if ( ! $configured ) {
			return rest_ensure_response(
				$base + array(
					'reachable' => false,
					'message'   => __( 'Checkout is not available on this build. Please contact the plugin vendor.', 'nvoos-docs-hub' ),
				)
			);
		}

		$started = microtime( true );
		$client  = new NV_oOS_Docs_Hub_Checkout_Client( NV_oOS_Docs_Hub_Checkout::vendor_api_url() );
		$health  = $client->health();
		$latency = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $health ) ) {
			$data = $health->get_error_data();
			return rest_ensure_response(
				$base + array(
					'reachable'  => false,
					'status'     => is_array( $data ) && isset( $data['status'] ) ? (int) $data['status'] : 502,
					'message'    => $health->get_error_message(),
					'latency_ms' => $latency,
				)
			);
		}

		return rest_ensure_response(
			$base + array(
				'reachable'  => true,
				'message'    => __( 'The checkout service is reachable.', 'nvoos-docs-hub' ),
				'vendor'     => $health,
				'latency_ms' => $latency,
			)
		);
	}

	/**
	 * Verify a completed payment, record the license, and install the Complete bundle.
	 *
	 * The vendor re-verifies the PaymentIntent server-side (status, amount,
	 * product, site binding) and returns a license key plus a signed
	 * download URL. This endpoint is idempotent: an already-licensed site
	 * returns the current state without contacting the vendor.
	 *
	 * @since 0.5.2
	 *
	 * @param WP_REST_Request $request Request with `payment_intent` param.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function verify_payment( $request ) {
		if ( ! self::passes_throttle( 'verify', 15 ) ) {
			return new WP_Error(
				'nvoos_docs_hub_checkout_rate_limited',
				__( 'Too many attempts. Please wait a few minutes and try again.', 'nvoos-docs-hub' ),
				array( 'status' => 429 )
			);
		}

		$intent_id       = (string) $request->get_param( 'payment_intent' );
		$terms_agreed_at = (int) $request->get_param( 'terms_agreed_at' );
		$buyer_email     = (string) $request->get_param( 'buyer_email' );
		$buyer_country   = (string) $request->get_param( 'buyer_country' );

		if ( NV_oOS_Docs_Hub_Checkout_License::is_licensed() && NV_oOS_Docs_Hub_Checkout_Installer::is_bundle_active() ) {
			return rest_ensure_response(
				array(
					'licensed'      => true,
					'installed'     => true,
					'activated'     => true,
					'bundle_active' => true,
					'license_key'   => NV_oOS_Docs_Hub_Checkout_License::license_key(),
					'message'       => __( 'NV oOS Complete is already licensed and active on this site.', 'nvoos-docs-hub' ),
				)
			);
		}

		if ( ! NV_oOS_Docs_Hub_Checkout::is_configured() ) {
			return new WP_Error(
				'nvoos_docs_hub_checkout_unavailable',
				__( 'Checkout is not available on this build. Please contact the plugin vendor.', 'nvoos-docs-hub' ),
				array( 'status' => 424 )
			);
		}

		$client = new NV_oOS_Docs_Hub_Checkout_Client( NV_oOS_Docs_Hub_Checkout::vendor_api_url() );
		$result = $client->verify( $intent_id, $terms_agreed_at, $buyer_email, $buyer_country );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( empty( $result['license_key'] ) ) {
			return new WP_Error(
				'nvoos_docs_hub_checkout_license_issue_failed',
				__( 'The checkout service did not issue a license. Please contact support.', 'nvoos-docs-hub' ),
				array( 'status' => 502 )
			);
		}

		// ─── Record the license ─────────────────────────────────────
		$user = wp_get_current_user();

		$record = array(
			'license_key'           => sanitize_text_field( (string) $result['license_key'] ),
			'stripe_payment_intent' => $intent_id,
			'amount_received'       => (int) ( isset( $result['amount'] ) ? $result['amount'] : 0 ),
			'currency'              => sanitize_text_field( (string) ( isset( $result['currency'] ) ? $result['currency'] : NV_oOS_Docs_Hub_Checkout::currency() ) ),
			'addon_version'         => sanitize_text_field( (string) ( isset( $result['addon_version'] ) ? $result['addon_version'] : NV_oOS_Docs_Hub_Checkout::addon_version() ) ),
			'site_url'              => home_url( '' ),
			'purchaser_id'          => get_current_user_id(),
			'purchaser_email'       => sanitize_text_field( (string) $user->user_email ),
			'purchased_at'          => time(),
			'terms_agreed_at'       => $terms_agreed_at,
			'buyer_email'           => '' !== $buyer_email ? sanitize_email( $buyer_email ) : sanitize_email( (string) $user->user_email ),
			'buyer_country'         => '' !== $buyer_country && 1 === preg_match( '/^[A-Z]{2}$/', $buyer_country ) ? strtoupper( $buyer_country ) : '',
		);

		NV_oOS_Docs_Hub_Checkout_License::save( $record );

		/**
		 * Fires after a successful bundle purchase is recorded.
		 *
		 * @since 0.5.2
		 *
		 * @param array<string,mixed> $record The purchase record that was saved.
		 */
		do_action( 'nvoos_docs_hub_checkout_purchase_recorded', $record );

		// ─── Install the NV oOS Complete bundle ────────────────────
		$zip_url = self::sanitize_zip_url(
			(string) ( isset( $result['download_url'] ) ? $result['download_url'] : '' )
		);
		if ( '' === $zip_url ) {
			$zip_url = NV_oOS_Docs_Hub_Checkout::zip_url();
		}

		$install = NV_oOS_Docs_Hub_Checkout_Installer::install( $zip_url );

		if ( is_wp_error( $install ) ) {
			$data = $install->get_error_data();
			return new WP_Error(
				$install->get_error_code(),
				$install->get_error_message()
					. ' '
					. __( 'Your license is recorded — you can also download the NV oOS Complete ZIP manually and upload it on the Plugins screen.', 'nvoos-docs-hub' ),
				array(
					'status'   => 500,
					'zip_url'  => is_array( $data ) && isset( $data['zip_url'] ) ? $data['zip_url'] : $zip_url,
					'licensed' => true,
				)
			);
		}

		return rest_ensure_response(
			array(
				'licensed'      => true,
				'installed'     => (bool) $install['installed'],
				'activated'     => (bool) $install['activated'],
				'bundle_active' => NV_oOS_Docs_Hub_Checkout_Installer::is_bundle_active(),
				'license_key'   => $record['license_key'],
				// Manual install is the primary documented path — always
				// surface the signed download URL alongside the auto-install
				// result so the buyer can upload the ZIP themselves.
				'download_url'  => $zip_url,
				'message'       => (string) $install['message'],
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
	 * @since 0.5.2
	 *
	 * @param string $url Candidate URL.
	 * @return string The URL when https, empty string otherwise.
	 */
	private static function sanitize_zip_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || 'https' !== ( isset( $parts['scheme'] ) ? $parts['scheme'] : '' ) ) {
			return '';
		}
		return esc_url_raw( $url );
	}

	/**
	 * Escape a legal-document URL, falling back to a client-side default.
	 *
	 * The vendor's session response carries the authoritative Terms of
	 * Service and Refund Policy URLs; when the vendor omits them (or
	 * returns something that is not an http(s) URL), the plugin's own
	 * filterable defaults keep the consent links present.
	 *
	 * @since 0.5.2
	 *
	 * @param string $url           Candidate URL from the vendor response.
	 * @param string $fallback      Client-side fallback URL.
	 * @param string $hard_fallback Last-resort URL for this link.
	 * @return string An http(s) URL, never empty.
	 */
	private static function sanitize_legal_url( $url, $fallback, $hard_fallback ) {
		$parts = wp_parse_url( $url );
		if ( is_array( $parts ) && in_array( isset( $parts['scheme'] ) ? $parts['scheme'] : '', array( 'http', 'https' ), true ) && ! empty( $parts['host'] ) ) {
			return esc_url_raw( $url );
		}

		$fallback = esc_url_raw( $fallback );
		return '' !== $fallback ? $fallback : $hard_fallback;
	}

	/**
	 * Cheap per-user throttle on checkout endpoints.
	 *
	 * Each endpoint has its own bucket so a busy session flow never blocks
	 * the verification step that follows a successful payment.
	 *
	 * @since 0.5.2
	 *
	 * @param string $bucket Bucket name ('session' | 'verify').
	 * @param int    $max    Max requests per window.
	 * @return bool True when the request may proceed.
	 */
	private static function passes_throttle( $bucket, $max ) {
		$key   = 'nvoos_docs_hub_checkout_' . $bucket . '_throttle_' . get_current_user_id();
		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			return false;
		}

		set_transient( $key, $count + 1, self::THROTTLE_WINDOW );
		return true;
	}

	/**
	 * Private constructor — static utility, not instantiable.
	 */
	private function __construct() {}
}
