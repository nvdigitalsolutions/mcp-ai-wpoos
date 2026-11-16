<?php
/**
 * Device identity — an opaque, rotation-aware subject identifier.
 *
 * IEEE 802.11bh-2024 ("Operation with Randomized and Changing MAC Addresses")
 * introduced Device ID as the sanctioned way to keep session continuity without
 * a stable MAC address. IEEE 802.11bi-2026 reinforced the privacy direction,
 * and RFC 9724 recommends randomised MACs outright.
 *
 * Consequence for this design (Proposal 150 decision D-9): **there is no `mac`
 * property on this class, and a MAC-shaped value is rejected at construction.**
 * If a controller hands us something that looks like a MAC, that is a bug in the
 * adapter, and it must fail loudly here rather than inflate every unique-visitor
 * count downstream by 300–500% (research §6.1).
 *
 * @package NvoosWifi
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace NvoosWifi\Domain;

/**
 * An immutable device identity plus the method by which it was derived.
 *
 * @since 0.1.0
 */
final class Device_Identity {

	/**
	 * Identity derived from an 802.11bh Device ID association exchange.
	 */
	const METHOD_DEVICE_ID = 'device_id';

	/**
	 * Identity derived from a portal capture (email/phone subject).
	 */
	const METHOD_SUBJECT = 'subject';

	/**
	 * Identity derived from a rotating probe hash, accepted only when no device
	 * identifier is available. Counts built on this method are explicitly weaker.
	 */
	const METHOD_PROBE_HASH = 'probe_hash';

	/**
	 * Permitted identity derivation methods.
	 *
	 * @var array<int, string>
	 */
	const METHODS = array( self::METHOD_DEVICE_ID, self::METHOD_SUBJECT, self::METHOD_PROBE_HASH );

	/**
	 * Opaque identifier. Never a MAC address.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * How the identifier was derived.
	 *
	 * @var string
	 */
	private string $method;

	/**
	 * Private constructor — use {@see self::make()}.
	 *
	 * @param string $id     Opaque identifier.
	 * @param string $method Derivation method.
	 */
	private function __construct( string $id, string $method ) {
		$this->id     = $id;
		$this->method = $method;
	}

	/**
	 * Build a validated identity.
	 *
	 * @param string $id     Opaque identifier.
	 * @param string $method One of {@see self::METHODS}.
	 * @return Result `ok( Device_Identity )` or `fail( <code> )`.
	 */
	public static function make( string $id, string $method ): Result {
		$id     = trim( $id );
		$method = strtolower( trim( $method ) );

		if ( '' === $id ) {
			return Result::fail( 'invalid_device_identity', 'A device identity requires a non-empty id.' );
		}

		if ( ! in_array( $method, self::METHODS, true ) ) {
			return Result::fail(
				'invalid_device_identity',
				sprintf(
					'Identity method must be one of: %s.',
					implode( ', ', self::METHODS )
				)
			);
		}

		if ( self::looks_like_mac( $id ) ) {
			return Result::fail(
				'mac_not_allowed',
				'The supplied identifier is MAC-shaped. Wi-Fi identity must be an opaque '
				. 'Device ID: MAC addresses randomise by design (802.11bh-2024, RFC 9724) '
				. 'and would inflate unique counts.'
			);
		}

		return Result::ok( new self( $id, $method ) );
	}

	/**
	 * Whether a string is shaped like a MAC address.
	 *
	 * Detects the two common serialisations — `aa:bb:cc:dd:ee:ff` (with `:` or
	 * `-`) and bare `aabbccddeeff` — case-insensitively.
	 *
	 * @param string $value Candidate identifier.
	 * @return bool
	 */
	private static function looks_like_mac( string $value ): bool {
		if ( 1 === preg_match( '/^[0-9a-f]{2}([:\-][0-9a-f]{2}){5}$/i', $value ) ) {
			return true;
		}

		return 1 === preg_match( '/^[0-9a-f]{12}$/i', $value );
	}

	/**
	 * The opaque identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * How the identifier was derived.
	 *
	 * @return string
	 */
	public function method(): string {
		return $this->method;
	}

	/**
	 * Whether counts built on this identity are the weaker probe-hash kind.
	 *
	 * @return bool
	 */
	public function is_weak(): bool {
		return self::METHOD_PROBE_HASH === $this->method;
	}

	/**
	 * Canonical serialisation.
	 *
	 * @return array{id:string, method:string, weak:bool}
	 */
	public function to_array(): array {
		return array(
			'id'     => $this->id,
			'method' => $this->method,
			'weak'   => $this->is_weak(),
		);
	}
}
