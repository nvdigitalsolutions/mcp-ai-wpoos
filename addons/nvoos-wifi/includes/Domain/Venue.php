<?php
/**
 * Venue — the tenant root of the WiFi domain.
 *
 * A venue, not a WordPress site, is the unit that owns access policies,
 * consent records, sessions and KPIs. One installation may serve many venues,
 * and a venue may outlive any particular host deployment.
 *
 * @package NvoosWifi
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace NvoosWifi\Domain;

use DateTimeZone;

/**
 * An immutable venue record.
 *
 * @since 0.1.0
 */
final class Venue {

	/**
	 * Permitted data-residency labels.
	 *
	 * `unspecified` is allowed at creation but must be resolved before any
	 * cross-border transfer decision (Jamaica DPA 2020, research §6.3).
	 *
	 * @var array<int, string>
	 */
	const RESIDENCIES = array( 'jm', 'us', 'eu', 'unspecified' );

	/**
	 * Stable venue identifier.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Operator-facing label.
	 *
	 * @var string
	 */
	private string $label;

	/**
	 * IANA timezone identifier the venue's "local day" resolves against.
	 *
	 * @var string
	 */
	private string $timezone;

	/**
	 * Data-residency label.
	 *
	 * @var string
	 */
	private string $residency;

	/**
	 * Private constructor — use {@see self::from_array()}.
	 *
	 * @param string $id       Identifier.
	 * @param string $label    Label.
	 * @param string $timezone IANA timezone.
	 * @param string $residency Residency label.
	 */
	private function __construct( string $id, string $label, string $timezone, string $residency ) {
		$this->id        = $id;
		$this->label     = $label;
		$this->timezone  = $timezone;
		$this->residency = $residency;
	}

	/**
	 * Hydrate and validate a venue from host-supplied data.
	 *
	 * @param array<string, mixed> $data Raw venue data.
	 * @return Result `ok( Venue )` or `fail( <code> )`.
	 */
	public static function from_array( array $data ): Result {
		$id    = isset( $data['id'] ) && is_string( $data['id'] ) ? trim( $data['id'] ) : '';
		$label = isset( $data['label'] ) && is_string( $data['label'] ) ? trim( $data['label'] ) : '';

		if ( '' === $id ) {
			return Result::fail( 'invalid_venue', 'A venue requires a non-empty id.' );
		}

		if ( '' === $label ) {
			return Result::fail( 'invalid_venue', 'A venue requires a non-empty label.' );
		}

		$timezone = isset( $data['timezone'] ) && is_string( $data['timezone'] )
			? trim( $data['timezone'] )
			: 'UTC';

		if ( ! self::is_valid_timezone( $timezone ) ) {
			return Result::fail(
				'invalid_timezone',
				sprintf( '"%s" is not a recognised IANA timezone identifier.', $timezone )
			);
		}

		$residency = isset( $data['residency'] ) && is_string( $data['residency'] )
			? strtolower( trim( $data['residency'] ) )
			: 'unspecified';

		if ( ! in_array( $residency, self::RESIDENCIES, true ) ) {
			return Result::fail(
				'invalid_residency',
				sprintf(
					'Residency must be one of: %s.',
					implode( ', ', self::RESIDENCIES )
				)
			);
		}

		return Result::ok( new self( $id, $label, $timezone, $residency ) );
	}

	/**
	 * Whether a string is a valid IANA timezone identifier.
	 *
	 * Uses PHP's bundled timezone database — no network, no WordPress.
	 *
	 * @param string $timezone Candidate identifier.
	 * @return bool
	 */
	private static function is_valid_timezone( string $timezone ): bool {
		return in_array( $timezone, DateTimeZone::listIdentifiers(), true );
	}

	/**
	 * Stable venue identifier.
	 *
	 * @return string
	 */
	public function id(): string {
		return $this->id;
	}

	/**
	 * Operator-facing label.
	 *
	 * @return string
	 */
	public function label(): string {
		return $this->label;
	}

	/**
	 * IANA timezone identifier.
	 *
	 * @return string
	 */
	public function timezone(): string {
		return $this->timezone;
	}

	/**
	 * Data-residency label.
	 *
	 * @return string
	 */
	public function residency(): string {
		return $this->residency;
	}

	/**
	 * Canonical serialisation.
	 *
	 * @return array{id:string, label:string, timezone:string, residency:string}
	 */
	public function to_array(): array {
		return array(
			'id'        => $this->id,
			'label'     => $this->label,
			'timezone'  => $this->timezone,
			'residency' => $this->residency,
		);
	}
}
