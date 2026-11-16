<?php
/**
 * Use-case: read a venue's controller telemetry as canonical KPI samples.
 *
 * This method is the whole Phase 0 deliverable. Both hosts call it and both must
 * receive byte-identical output (Proposal 150 implementation plan T-0.11), so
 * every ordering decision here is deliberate:
 *
 *   - samples are keyed by canonical KPI key and `ksort`-ed, so adapter map
 *     order cannot leak into the payload;
 *   - `skipped` is sorted by key for the same reason;
 *   - all arithmetic is on scalars supplied by the adapter, so there is no
 *     floating-point drift from host-specific formatting.
 *
 * The service knows nothing about WordPress, HTTP, options or controllers. It
 * receives three ports and returns a domain `Result`.
 *
 * @package NvoosWifi
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace NvoosWifi\Services;

use NvoosWifi\Domain\Kpi_Sample;
use NvoosWifi\Domain\Kpi_Schema;
use NvoosWifi\Domain\Result;
use NvoosWifi\Domain\Venue;
use NvoosWifi\Domain\Window;
use NvoosWifi\Interfaces\Interface_WiFi_Clock;
use NvoosWifi\Interfaces\Interface_WiFi_Controller_Adapter;
use NvoosWifi\Interfaces\Interface_WiFi_Kpi_Sink;

/**
 * Reads controller telemetry and normalises it to the canonical schema.
 *
 * @since 0.1.0
 */
final class Class_Kpi_Service {

	/**
	 * Controller telemetry source.
	 *
	 * @var Interface_WiFi_Controller_Adapter
	 */
	private Interface_WiFi_Controller_Adapter $adapter;

	/**
	 * Persistence for normalised samples.
	 *
	 * @var Interface_WiFi_Kpi_Sink
	 */
	private Interface_WiFi_Kpi_Sink $sink;

	/**
	 * Time source.
	 *
	 * @var Interface_WiFi_Clock
	 */
	private Interface_WiFi_Clock $clock;

	/**
	 * Construct the service from its ports.
	 *
	 * @param Interface_WiFi_Controller_Adapter $adapter Telemetry source.
	 * @param Interface_WiFi_Kpi_Sink           $sink    Sample persistence.
	 * @param Interface_WiFi_Clock              $clock   Time source.
	 */
	public function __construct(
		Interface_WiFi_Controller_Adapter $adapter,
		Interface_WiFi_Kpi_Sink $sink,
		Interface_WiFi_Clock $clock
	) {
		$this->adapter = $adapter;
		$this->sink    = $sink;
		$this->clock   = $clock;
	}

	/**
	 * Adapter reachability and metadata.
	 *
	 * @return Result `ok( array )` or `fail( <code> )`.
	 */
	public function health(): Result {
		$health = $this->adapter->health();

		if ( $health->is_error() ) {
			return $health;
		}

		return Result::ok(
			array(
				'adapter' => array(
					'id'              => $this->adapter->id(),
					'label'           => $this->adapter->label(),
					'identity_method' => $this->adapter->identity_method(),
				),
				'health'  => $health->value(),
			)
		);
	}

	/**
	 * Read, normalise, persist and return a venue's KPIs for a window.
	 *
	 * @param Venue  $venue  Venue.
	 * @param Window $window Time window.
	 * @return Result `ok( array )` or `fail( <code> )`.
	 */
	public function for_venue( Venue $venue, Window $window ): Result {
		$stats = $this->adapter->read_radio_stats( $venue, $window );

		if ( $stats->is_error() ) {
			return $stats;
		}

		$payload = $stats->value();

		// Guard against a provider returning a shape it promised not to return
		// (implementation plan §7, row 3: string-assumed provider responses).
		if (
			! is_array( $payload )
			|| ! isset( $payload['at'], $payload['values'] )
			|| ! is_array( $payload['values'] )
		) {
			return Result::fail(
				'adapter_shape',
				sprintf(
					'Adapter "%s" returned a malformed stats payload; expected {at:int, values:array}.',
					$this->adapter->id()
				)
			);
		}

		$at           = (int) $payload['at'];
		$native       = $payload['values'];
		$map          = $this->adapter->kpi_map();
		$identity     = $this->adapter->identity_method();
		$by_key       = array();
		$skipped      = array();

		foreach ( $map as $native_key => $canonical_key ) {
			if ( ! Kpi_Schema::has( $canonical_key ) ) {
				$skipped[ $canonical_key ] = 'unknown_schema_key';
				continue;
			}

			if ( ! array_key_exists( $native_key, $native ) ) {
				$skipped[ $canonical_key ] = 'not_reported';
				continue;
			}

			$requires_method = Kpi_Schema::requires_method( $canonical_key );
			$method          = $requires_method ? $identity : null;

			// Never emit an unqualified entity count. Skipping is the honest
			// outcome; a number without a method is a liability (research §6.1).
			if ( $requires_method && null === $method ) {
				$skipped[ $canonical_key ] = 'no_identity_method';
				continue;
			}

			$raw   = $native[ $native_key ];
			$value = is_int( $raw ) || is_float( $raw ) ? $raw : null;

			$sample = Kpi_Sample::make(
				$canonical_key,
				$value,
				$at,
				$venue->id(),
				$method,
				$this->adapter->id()
			);

			if ( $sample->is_error() ) {
				$skipped[ $canonical_key ] = $sample->code();
				continue;
			}

			// First mapping wins when two native keys target the same KPI, so a
			// duplicate is visible in `skipped` rather than silently replacing.
			if ( isset( $by_key[ $canonical_key ] ) ) {
				$skipped[ $canonical_key ] = 'duplicate_native_key';
				continue;
			}

			$by_key[ $canonical_key ] = $sample->value();
		}

		ksort( $by_key, SORT_STRING );
		ksort( $skipped, SORT_STRING );

		$written = $this->sink->write( array_values( $by_key ) );

		if ( $written->is_error() ) {
			return $written;
		}

		$skip_entries = array();
		foreach ( $skipped as $key => $reason ) {
			$skip_entries[] = array(
				'key'    => (string) $key,
				'reason' => (string) $reason,
			);
		}

		$samples = array();
		foreach ( $by_key as $sample ) {
			$samples[] = $sample->to_array();
		}

		return Result::ok(
			array(
				'venue'        => $venue->to_array(),
				'window'       => $window->to_array(),
				'adapter'      => array(
					'id'              => $this->adapter->id(),
					'label'           => $this->adapter->label(),
					'identity_method' => $identity,
				),
				'samples'      => $samples,
				'skipped'      => $skip_entries,
				'generated_at' => $this->clock->now(),
			)
		);
	}

	/**
	 * Read previously persisted samples for a venue and window.
	 *
	 * @param Venue  $venue  Venue.
	 * @param Window $window Time window.
	 * @return Result `ok( array<int, array> )` or `fail( <code> )`.
	 */
	public function stored( Venue $venue, Window $window ): Result {
		$read = $this->sink->read( $venue->id(), $window );

		if ( $read->is_error() ) {
			return $read;
		}

		$samples = array();
		foreach ( $read->value() as $sample ) {
			$samples[] = $sample->to_array();
		}

		return Result::ok(
			array(
				'venue'   => $venue->to_array(),
				'window'  => $window->to_array(),
				'samples' => $samples,
			)
		);
	}
}
