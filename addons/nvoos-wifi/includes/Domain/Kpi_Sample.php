<?php
/**
 * A single measured KPI value, with provenance.
 *
 * The invariant this class enforces is the product's core honesty guarantee
 * (Proposal 150 design law #4): **an entity count is never emitted without the
 * method by which entities were counted.** A number without a method is not a
 * measurement, it is a rumour — and under MAC randomisation it is a *wrong*
 * rumour that drives real staffing and lease decisions.
 *
 * @package NvoosWifi
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace NvoosWifi\Domain;

/**
 * An immutable measured value.
 *
 * @since 0.1.0
 */
final class Kpi_Sample {

	/**
	 * Canonical KPI key.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Measured value, or null when the source could not produce one.
	 *
	 * `null` is meaningful and never coerced to `0` — a missing denominator is
	 * an absence, not a zero (implementation plan §7, row 6).
	 *
	 * @var int|float|null
	 */
	private $value;

	/**
	 * Measurement timestamp, epoch seconds.
	 *
	 * @var int
	 */
	private int $at;

	/**
	 * Venue the value belongs to.
	 *
	 * @var string
	 */
	private string $venue_id;

	/**
	 * How entities were counted, when the KPI counts entities.
	 *
	 * @var string|null
	 */
	private ?string $method;

	/**
	 * Adapter that produced the value, for provenance.
	 *
	 * @var string
	 */
	private string $source_adapter;

	/**
	 * Private constructor — use {@see self::make()}.
	 *
	 * @param string          $key            Canonical KPI key.
	 * @param int|float|null  $value          Measured value.
	 * @param int             $at             Timestamp.
	 * @param string          $venue_id       Venue identifier.
	 * @param string|null     $method         Counting method.
	 * @param string          $source_adapter Producing adapter.
	 */
	private function __construct(
		string $key,
		$value,
		int $at,
		string $venue_id,
		?string $method,
		string $source_adapter
	) {
		$this->key            = $key;
		$this->value          = $value;
		$this->at             = $at;
		$this->venue_id       = $venue_id;
		$this->method         = $method;
		$this->source_adapter = $source_adapter;
	}

	/**
	 * Build a validated sample.
	 *
	 * @param string         $key        Canonical KPI key.
	 * @param mixed          $value      Measured value (int, float or null).
	 * @param int            $at         Measurement timestamp, epoch seconds.
	 * @param string         $venue_id   Venue identifier.
	 * @param string|null    $method     Counting method for entity counts.
	 * @param string         $adapter_id Producing adapter identifier.
	 * @return Result `ok( Kpi_Sample )` or `fail( <code> )`.
	 */
	public static function make(
		string $key,
		$value,
		int $at,
		string $venue_id,
		?string $method,
		string $adapter_id
	): Result {
		if ( ! Kpi_Schema::has( $key ) ) {
			return Result::fail(
				'unknown_kpi_key',
				sprintf( '"%s" is not in the KPI schema. Add it to Kpi_Schema deliberately.', $key )
			);
		}

		if ( null !== $value && ! is_int( $value ) && ! is_float( $value ) ) {
			return Result::fail(
				'invalid_kpi_value',
				sprintf( 'KPI "%s" must be numeric or null.', $key )
			);
		}

		if ( $at < 0 ) {
			return Result::fail( 'invalid_kpi_time', 'KPI timestamp must be non-negative epoch seconds.' );
		}

		if ( '' === trim( $venue_id ) ) {
			return Result::fail( 'invalid_venue', 'A KPI sample requires a venue id.' );
		}

		$method = null === $method ? null : strtolower( trim( $method ) );

		if ( null !== $method && '' === $method ) {
			$method = null;
		}

		// The invariant: entity counts must name their method.
		if ( Kpi_Schema::requires_method( $key ) && null === $method ) {
			return Result::fail(
				'method_required',
				sprintf(
					'KPI "%s" counts entities and must declare a counting method (one of: %s).',
					$key,
					implode( ', ', Device_Identity::METHODS )
				)
			);
		}

		if ( null !== $method && ! in_array( $method, Device_Identity::METHODS, true ) ) {
			return Result::fail(
				'invalid_method',
				sprintf(
					'Method must be one of: %s.',
					implode( ', ', Device_Identity::METHODS )
				)
			);
		}

		return Result::ok(
			new self( $key, $value, $at, trim( $venue_id ), $method, $adapter_id )
		);
	}

	/**
	 * Canonical KPI key.
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * Measured value, or null when unavailable.
	 *
	 * @return int|float|null
	 */
	public function value() {
		return $this->value;
	}

	/**
	 * Whether a value was produced.
	 *
	 * @return bool
	 */
	public function has_value(): bool {
		return null !== $this->value;
	}

	/**
	 * Measurement timestamp.
	 *
	 * @return int
	 */
	public function at(): int {
		return $this->at;
	}

	/**
	 * Venue identifier.
	 *
	 * @return string
	 */
	public function venue_id(): string {
		return $this->venue_id;
	}

	/**
	 * Counting method, when applicable.
	 *
	 * @return string|null
	 */
	public function method(): ?string {
		return $this->method;
	}

	/**
	 * Producing adapter identifier.
	 *
	 * @return string
	 */
	public function source_adapter(): string {
		return $this->source_adapter;
	}

	/**
	 * Canonical serialisation. Key order is fixed for host parity.
	 *
	 * @return array{key:string, value:int|float|null, at:int, venue_id:string, method:string|null, source_adapter:string, de_name:string|null, unit:string|null}
	 */
	public function to_array(): array {
		$definition = Kpi_Schema::definition( $this->key );

		return array(
			'key'            => $this->key,
			'value'          => $this->value,
			'at'             => $this->at,
			'venue_id'       => $this->venue_id,
			'method'         => $this->method,
			'source_adapter' => $this->source_adapter,
			'de_name'        => null === $definition ? null : $definition['de_name'],
			'unit'           => null === $definition ? null : $definition['unit'],
		);
	}
}
