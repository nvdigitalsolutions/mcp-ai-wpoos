<?php
/**
 * Stripe webhook signature verifier.
 *
 * Stripe signs every webhook delivery with an HMAC-SHA256 of
 * `{timestamp}.{raw_body}` using the endpoint's signing secret and ships
 * the result in the `Stripe-Signature` header (one or more `v1=` values
 * alongside a `t=` timestamp). This verifier reproduces Stripe's library
 * algorithm — see https://stripe.com/docs/webhooks#verify-manually — with
 * a configurable tolerance window (default 5 minutes) and a constant-time
 * comparison to avoid timing oracles.
 *
 * The verifier intentionally does **not** parse the JSON body; it only
 * validates the signature and exposes the decoded event id + type for the
 * webhook receiver. JSON parsing is the receiver's responsibility.
 *
 * @package NV_oOS_SaaS_Controller
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stateless Stripe webhook verifier.
 *
 * @since 0.1.0
 */
class NVOOS_SaaS_Controller_Stripe_Webhook_Verifier {

	/**
	 * Default tolerance window (seconds). Stripe's official libraries use
	 * 300 seconds (5 minutes) — we follow the same default.
	 *
	 * @var int
	 */
	const DEFAULT_TOLERANCE = 300;

	/**
	 * Verify a Stripe webhook delivery.
	 *
	 * Returns a structured result describing whether the signature is
	 * valid, the timestamp it carried, and (on success only) the event id
	 * and type extracted from the body. The shape is stable so the REST
	 * layer can map verdicts onto HTTP status codes without re-parsing
	 * arbitrary error strings.
	 *
	 * @since 0.1.0
	 *
	 * @param string $raw_body  Raw request body (must be the *exact* bytes Stripe signed).
	 * @param string $signature Value of the `Stripe-Signature` header.
	 * @param string $secret    Endpoint signing secret (`whsec_…`).
	 * @param int    $tolerance Optional tolerance window in seconds.
	 * @param int    $now       Optional unix timestamp (test override).
	 * @return array{
	 *     ok: bool,
	 *     reason: string,
	 *     timestamp: int,
	 *     event_id: string,
	 *     event_type: string
	 * }
	 */
	public static function verify( $raw_body, $signature, $secret, $tolerance = self::DEFAULT_TOLERANCE, $now = 0 ) {
		$result = array(
			'ok'         => false,
			'reason'     => '',
			'timestamp'  => 0,
			'event_id'   => '',
			'event_type' => '',
		);

		$raw_body  = is_string( $raw_body ) ? $raw_body : '';
		$signature = is_string( $signature ) ? $signature : '';
		$secret    = is_string( $secret ) ? $secret : '';

		if ( '' === $secret ) {
			$result['reason'] = 'missing_secret';
			return $result;
		}
		if ( '' === $signature ) {
			$result['reason'] = 'missing_signature';
			return $result;
		}
		if ( '' === $raw_body ) {
			$result['reason'] = 'empty_body';
			return $result;
		}

		$parsed = self::parse_signature_header( $signature );
		if ( null === $parsed ) {
			$result['reason'] = 'malformed_signature';
			return $result;
		}

		$ts = (int) $parsed['t'];
		if ( $ts <= 0 ) {
			$result['reason'] = 'invalid_timestamp';
			return $result;
		}
		$result['timestamp'] = $ts;

		$tolerance = (int) $tolerance;
		if ( $tolerance < 1 ) {
			$tolerance = self::DEFAULT_TOLERANCE;
		}
		$now = (int) $now;
		if ( $now <= 0 ) {
			$now = time();
		}
		if ( abs( $now - $ts ) > $tolerance ) {
			$result['reason'] = 'timestamp_outside_tolerance';
			return $result;
		}

		$signed_payload = $ts . '.' . $raw_body;
		$expected       = hash_hmac( 'sha256', $signed_payload, $secret );

		$matched = false;
		foreach ( $parsed['v1'] as $candidate ) {
			if ( ! is_string( $candidate ) || '' === $candidate ) {
				continue;
			}
			if ( strlen( $candidate ) !== strlen( $expected ) ) {
				continue;
			}
			if ( hash_equals( $expected, $candidate ) ) {
				$matched = true;
				break;
			}
		}
		if ( ! $matched ) {
			$result['reason'] = 'signature_mismatch';
			return $result;
		}

		$decoded = json_decode( $raw_body, true );
		if ( ! is_array( $decoded ) ) {
			$result['reason'] = 'invalid_json';
			return $result;
		}

		$event_id   = isset( $decoded['id'] ) && is_string( $decoded['id'] ) ? $decoded['id'] : '';
		$event_type = isset( $decoded['type'] ) && is_string( $decoded['type'] ) ? $decoded['type'] : '';

		$result['ok']         = true;
		$result['reason']     = 'verified';
		$result['event_id']   = $event_id;
		$result['event_type'] = $event_type;
		return $result;
	}

	/**
	 * Parse the `Stripe-Signature` header into its `t=` timestamp and
	 * `v1=` signature(s).
	 *
	 * Stripe's header is a comma-separated list of `key=value` pairs. There
	 * is exactly one `t=` and one or more `v1=` values during a secret
	 * rotation. Other schemes (`v0=`) are ignored.
	 *
	 * @since 0.1.0
	 *
	 * @param string $header Raw `Stripe-Signature` value.
	 * @return array{t:int, v1:string[]}|null Null on malformed input.
	 */
	protected static function parse_signature_header( $header ) {
		$ts        = null;
		$v1_values = array();
		$parts     = explode( ',', $header );
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			$eq = strpos( $part, '=' );
			if ( false === $eq || 0 === $eq ) {
				continue;
			}
			$key = substr( $part, 0, $eq );
			$val = substr( $part, $eq + 1 );
			if ( 't' === $key ) {
				if ( ! preg_match( '/^[0-9]+$/', $val ) ) {
					return null;
				}
				$ts = (int) $val;
			} elseif ( 'v1' === $key ) {
				if ( preg_match( '/^[0-9a-f]+$/i', $val ) ) {
					$v1_values[] = strtolower( $val );
				}
			}
		}
		if ( null === $ts || empty( $v1_values ) ) {
			return null;
		}
		return array(
			't'  => $ts,
			'v1' => $v1_values,
		);
	}
}
