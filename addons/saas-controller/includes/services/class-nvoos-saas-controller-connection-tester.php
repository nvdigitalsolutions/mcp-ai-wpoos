<?php
/**
 * Connection tester for NV oOS SaaS Controller.
 *
 * Performs *live preflight* validation of the three operator credentials this
 * addon manages — Cloudflare, Stripe, OpenRouter — by making a single,
 * cheap, read-only HTTP call against each provider's identity endpoint:
 *
 *   • Cloudflare  → GET https://api.cloudflare.com/client/v4/user/tokens/verify
 *   • Stripe      → GET https://api.stripe.com/v1/account
 *   • OpenRouter  → GET https://openrouter.ai/api/v1/auth/key
 *
 * Each result is normalised into the same shape:
 *
 *   { ok: bool, latency_ms: int, status: int, message: string }
 *
 * The tester never logs, returns, or interpolates the secret values it
 * receives — only sanitised provider-side messages are surfaced to callers.
 *
 * @package NV_oOS_SaaS_Controller
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connection tester service.
 *
 * @since 0.1.0
 */
class NVOOS_SaaS_Controller_Connection_Tester {

	/**
	 * Default per-request timeout, seconds.
	 *
	 * @var int
	 */
	const DEFAULT_TIMEOUT = 10;

	/**
	 * Maximum number of bytes from a provider response body to inspect.
	 *
	 * @var int
	 */
	const MAX_BODY_INSPECT = 4096;

	/**
	 * Test the Cloudflare credential pair.
	 *
	 * Uses the dedicated token-verification endpoint (`/user/tokens/verify`)
	 * rather than an account-scoped endpoint. This has two advantages:
	 *
	 *   1. It does not require the token to have a specific scope — any valid
	 *      API token will receive a 200 response, making the test meaningful
	 *      regardless of which permissions the operator granted.
	 *   2. It avoids the HTTP 400 "Invalid request headers" response that some
	 *      Cloudflare account endpoints return when default WordPress request
	 *      headers conflict with the account-details endpoint's strict header
	 *      validation.
	 *
	 * The account ID is still validated for format here so that bad values are
	 * surfaced immediately. Live account-scope verification is handled by the
	 * smoke tester's `check_cloudflare_workers()` check.
	 *
	 * @since 0.1.0
	 *
	 * @param string $account_id Cloudflare account ID (format-validated only).
	 * @param string $api_token  Cloudflare API token (live-verified).
	 * @return array Result shape: { ok, latency_ms, status, message }.
	 */
	public function test_cloudflare( $account_id, $api_token ) {
		$account_id = trim( (string) $account_id );
		$api_token  = trim( (string) $api_token );
		if ( '' === $account_id || '' === $api_token ) {
			return $this->failure( 0, 0, __( 'Cloudflare account ID and API token are both required.', 'nvoos-saas-controller' ) );
		}
		// Cloudflare account IDs are 32 hex chars on modern accounts but
		// some legacy/test accounts return shorter or longer IDs, so accept
		// 16–64 hex chars defensively.
		if ( ! preg_match( '/^[a-f0-9]{16,64}$/i', $account_id ) ) {
			return $this->failure( 0, 0, __( 'Cloudflare account ID format is invalid.', 'nvoos-saas-controller' ) );
		}
		// Use the dedicated token-verification endpoint instead of an
		// account-scoped URL. The /accounts/{id} endpoint can return HTTP 400
		// "Invalid request headers" depending on the token scope and the server
		// environment, while /user/tokens/verify is specifically designed for
		// this kind of preflight check.
		return $this->preflight(
			'https://api.cloudflare.com/client/v4/user/tokens/verify',
			array(
				'Authorization' => 'Bearer ' . $api_token,
				'Accept'        => 'application/json',
			),
			'cloudflare'
		);
	}

	/**
	 * Test the Stripe secret key.
	 *
	 * Uses HTTP Basic auth with the secret key as the username (Stripe's
	 * documented auth scheme). Never logs the key.
	 *
	 * @since 0.1.0
	 *
	 * @param string $secret_key Stripe secret key (sk_live_… or sk_test_…).
	 * @return array
	 */
	public function test_stripe( $secret_key ) {
		$secret_key = trim( (string) $secret_key );
		if ( '' === $secret_key ) {
			return $this->failure( 0, 0, __( 'Stripe secret key is required.', 'nvoos-saas-controller' ) );
		}
		if ( 0 !== strpos( $secret_key, 'sk_live_' ) && 0 !== strpos( $secret_key, 'sk_test_' ) ) {
			return $this->failure( 0, 0, __( 'Stripe secret key must start with sk_live_ or sk_test_.', 'nvoos-saas-controller' ) );
		}
		return $this->preflight(
			'https://api.stripe.com/v1/account',
			array(
				'Authorization' => 'Basic ' . base64_encode( $secret_key . ':' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				'Accept'        => 'application/json',
			),
			'stripe'
		);
	}

	/**
	 * Test the OpenRouter API key.
	 *
	 * @since 0.1.0
	 *
	 * @param string $api_key OpenRouter API key.
	 * @return array
	 */
	public function test_openrouter( $api_key ) {
		$api_key = trim( (string) $api_key );
		if ( '' === $api_key ) {
			return $this->failure( 0, 0, __( 'OpenRouter API key is required.', 'nvoos-saas-controller' ) );
		}
		return $this->preflight(
			'https://openrouter.ai/api/v1/auth/key',
			array(
				'Authorization' => 'Bearer ' . $api_key,
				'Accept'        => 'application/json',
			),
			'openrouter'
		);
	}

	/**
	 * Run all three preflights using either the supplied credentials or
	 * (when keys are missing/empty) the values from the credential store.
	 *
	 * Empty supplied values are *not* a hard error here — the caller
	 * (typically the wizard's "Test" button) may want to test only the
	 * credential they just typed. Missing-credential failures are surfaced
	 * per-provider in the result.
	 *
	 * @since 0.1.0
	 *
	 * @param array<string,string> $supplied Optional credential overrides.
	 * @return array<string,array{ok:bool,latency_ms:int,status:int,message:string}>
	 */
	public function test_all( array $supplied = array() ) {
		$store  = NVOOS_SaaS_Controller_Credential_Store::instance();
		$stored = $store->get_all();
		$pick   = static function ( $key ) use ( $supplied, $stored ) {
			if ( array_key_exists( $key, $supplied ) && '' !== (string) $supplied[ $key ] ) {
				return (string) $supplied[ $key ];
			}
			return isset( $stored[ $key ] ) ? (string) $stored[ $key ] : '';
		};

		return array(
			'cloudflare' => $this->test_cloudflare( $pick( 'cloudflare_account_id' ), $pick( 'cloudflare_api_token' ) ),
			'stripe'     => $this->test_stripe( $pick( 'stripe_secret_key' ) ),
			'openrouter' => $this->test_openrouter( $pick( 'openrouter_api_key' ) ),
		);
	}

	/**
	 * Issue a single HTTPS preflight request and normalise the result.
	 *
	 * @since 0.1.0
	 *
	 * @param string               $url      Endpoint URL.
	 * @param array<string,string> $headers  Request headers (must include Authorization).
	 * @param string               $provider Provider slug for {@see extract_message()}.
	 * @return array
	 */
	protected function preflight( $url, array $headers, $provider ) {
		$started  = microtime( true );
		$response = wp_remote_get(
			$url,
			array(
				'headers'   => $headers,
				'timeout'   => self::DEFAULT_TIMEOUT,
				'sslverify' => true,
			)
		);
		$latency  = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return $this->failure(
				$latency,
				0,
				sprintf(
				/* translators: %s: provider error message */
					__( 'Network error contacting upstream: %s', 'nvoos-saas-controller' ),
					$response->get_error_message()
				)
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$body   = (string) wp_remote_retrieve_body( $response );
		// Defensive: never inspect more than MAX_BODY_INSPECT bytes.
		if ( strlen( $body ) > self::MAX_BODY_INSPECT ) {
			$body = substr( $body, 0, self::MAX_BODY_INSPECT );
		}

		if ( $status >= 200 && $status < 300 ) {
			return array(
				'ok'         => true,
				'latency_ms' => $latency,
				'status'     => $status,
				'message'    => __( 'OK', 'nvoos-saas-controller' ),
			);
		}

		return $this->failure(
			$latency,
			$status,
			$this->extract_message( $provider, $status, $body )
		);
	}

	/**
	 * Build a normalised failure shape.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $latency Latency ms.
	 * @param int    $status  HTTP status.
	 * @param string $message Sanitised message.
	 * @return array
	 */
	protected function failure( $latency, $status, $message ) {
		return array(
			'ok'         => false,
			'latency_ms' => (int) $latency,
			'status'     => (int) $status,
			'message'    => (string) $message,
		);
	}

	/**
	 * Convert a provider error body into a human-readable message.
	 *
	 * Falls back to a generic "HTTP {status}" string when the body is not
	 * JSON or does not contain a recognised error field.
	 *
	 * @since 0.1.0
	 *
	 * @param string $provider Provider slug.
	 * @param int    $status   HTTP status.
	 * @param string $body     Response body (already truncated).
	 * @return string
	 */
	protected function extract_message( $provider, $status, $body ) {
		$decoded = json_decode( $body, true );
		if ( is_array( $decoded ) ) {
			switch ( $provider ) {
				case 'cloudflare':
					if ( ! empty( $decoded['errors'][0]['message'] ) ) {
						return $this->sanitize_message( (string) $decoded['errors'][0]['message'], $status );
					}
					break;
				case 'stripe':
					if ( ! empty( $decoded['error']['message'] ) ) {
						return $this->sanitize_message( (string) $decoded['error']['message'], $status );
					}
					break;
				case 'openrouter':
					if ( ! empty( $decoded['error']['message'] ) ) {
						return $this->sanitize_message( (string) $decoded['error']['message'], $status );
					}
					if ( ! empty( $decoded['message'] ) ) {
						return $this->sanitize_message( (string) $decoded['message'], $status );
					}
					break;
			}
		}
		return sprintf(
			/* translators: %d: HTTP status code */
			__( 'Upstream returned HTTP %d.', 'nvoos-saas-controller' ),
			(int) $status
		);
	}

	/**
	 * Sanitise an upstream message before surfacing it.
	 *
	 * Strips control characters, hard-caps length, and prepends the HTTP
	 * status so the caller knows the message came from the upstream.
	 *
	 * @since 0.1.0
	 *
	 * @param string $message Raw upstream message.
	 * @param int    $status  HTTP status.
	 * @return string
	 */
	protected function sanitize_message( $message, $status ) {
		$message = wp_strip_all_tags( $message );
		$message = preg_replace( '/[\x00-\x1F\x7F]+/', ' ', $message );
		$message = trim( (string) $message );
		if ( strlen( $message ) > 280 ) {
			$message = substr( $message, 0, 277 ) . '…';
		}
		return sprintf( 'HTTP %d — %s', (int) $status, $message );
	}
}
