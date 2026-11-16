<?php
/**
 * Mock controller adapter — deterministic, offline, no hardware.
 *
 * This ships **before** any real controller adapter, deliberately (Proposal 150
 * implementation plan T-0.6). The pattern is borrowed from
 * `@subashgautam/router-mcp`, which ships pluggable adapters alongside a mock
 * backend: without it, every test, demo and CI run needs a physical AP.
 *
 * Values are derived deterministically from
 * `(seed, venue id, native key)` so that:
 *
 *   - two runs produce identical output (the parity fixture depends on this);
 *   - a test can assert exact numbers rather than ranges.
 *
 * @package NvoosWifi
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace NvoosWifi\Infrastructure\Providers;

use NvoosWifi\Domain\Device_Identity;
use NvoosWifi\Domain\Result;
use NvoosWifi\Domain\Venue;
use NvoosWifi\Domain\Window;
use NvoosWifi\Interfaces\Interface_WiFi_Controller_Adapter;

/**
 * A deterministic in-memory controller adapter.
 *
 * @since 0.1.0
 */
final class Class_Mock_Controller_Adapter implements Interface_WiFi_Controller_Adapter {

	/**
	 * Seed mixed into every derived value.
	 *
	 * @var string
	 */
	private string $seed;

	/**
	 * Fixed measurement timestamp returned for every read.
	 *
	 * A constant, not `time()`, so output is reproducible across runs. This is
	 * what allows the Phase 0 parity fixture to be committed.
	 *
	 * @var int
	 */
	private int $fixed_at;

	/**
	 * Construct the adapter.
	 *
	 * @param string $seed     Seed for deterministic derivation.
	 * @param int    $fixed_at Measurement timestamp to report (epoch seconds).
	 */
	public function __construct( string $seed = 'mock-default', int $fixed_at = 1763424000 ) {
		$this->seed     = $seed;
		$this->fixed_at = $fixed_at;
	}

	/**
	 * {@inheritdoc}
	 */
	public function id(): string {
		return 'mock';
	}

	/**
	 * {@inheritdoc}
	 */
	public function label(): string {
		return 'Mock controller (no hardware)';
	}

	/**
	 * {@inheritdoc}
	 *
	 * The mock is always reachable, so a CI run never depends on the network.
	 */
	public function health(): Result {
		return Result::ok(
			array(
				'reachable'  => true,
				'latency_ms' => 0,
				'detail'     => 'Mock adapter: no network was contacted.',
			)
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * The mock claims 802.11bh-style Device ID support, because that is the
	 * capability a modern controller should expose. A real adapter that can only
	 * see MACs must declare `probe_hash` instead.
	 */
	public function identity_method(): ?string {
		return Device_Identity::METHOD_DEVICE_ID;
	}

	/**
	 * {@inheritdoc}
	 */
	public function kpi_map(): array {
		return array(
			'native_client_count'       => 'wifi.clients.connected',
			'native_unique_client_count' => 'wifi.clients.unique',
			'native_retry_pct'          => 'wifi.radio.retry_rate',
			'native_airtime_pct'        => 'wifi.radio.airtime_utilization',
			'native_channel_pct'        => 'wifi.radio.channel_utilization',
			'native_noise_dbm'          => 'wifi.radio.noise_floor',
			'native_rssi_median_dbm'    => 'wifi.radio.rssi_median',
			'native_down_mbps'          => 'wifi.quality.throughput_down',
			'native_up_mbps'            => 'wifi.quality.throughput_up',
			'native_latency_ms'         => 'wifi.quality.latency',
			'native_dns_failure_pct'    => 'wifi.quality.dns_failure_rate',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function read_radio_stats( Venue $venue, Window $window ): Result {
		// A measurement must fall *inside* the window it reports for. `Window` is
		// half-open `[from, to)`, so the latest in-window second is `to - 1` —
		// clamping here is what stops a sample being stamped on the exclusive
		// boundary and then filtered out by every later read.
		$at = min( max( $this->fixed_at, $window->from() ), $window->to() - 1 );

		$values = array(
			'native_client_count'        => $this->derive_int( $venue->id(), 'client_count', 12, 240 ),
			'native_unique_client_count' => $this->derive_int( $venue->id(), 'unique_client_count', 20, 400 ),
			'native_retry_pct'           => $this->derive_float( $venue->id(), 'retry_pct', 0.5, 22.0 ),
			'native_airtime_pct'         => $this->derive_float( $venue->id(), 'airtime_pct', 5.0, 92.0 ),
			'native_channel_pct'         => $this->derive_float( $venue->id(), 'channel_pct', 5.0, 95.0 ),
			'native_noise_dbm'           => $this->derive_int( $venue->id(), 'noise_dbm', -98, -78 ),
			'native_rssi_median_dbm'     => $this->derive_int( $venue->id(), 'rssi_median_dbm', -78, -42 ),
			'native_down_mbps'           => $this->derive_float( $venue->id(), 'down_mbps', 8.0, 480.0 ),
			'native_up_mbps'             => $this->derive_float( $venue->id(), 'up_mbps', 2.0, 220.0 ),
			'native_latency_ms'          => $this->derive_int( $venue->id(), 'latency_ms', 4, 180 ),
			'native_dns_failure_pct'     => $this->derive_float( $venue->id(), 'dns_failure_pct', 0.0, 9.0 ),
		);

		return Result::ok(
			array(
				'at'     => $at,
				'values' => $values,
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function list_clients( Venue $venue ): Result {
		$count   = $this->derive_int( $venue->id(), 'client_list_size', 2, 8 );
		$clients = array();

		for ( $i = 0; $i < $count; $i++ ) {
			$identity = Device_Identity::make(
				$this->derive_token( $venue->id(), 'device-' . $i ),
				Device_Identity::METHOD_DEVICE_ID
			);

			if ( $identity->is_error() ) {
				return $identity;
			}

			$clients[] = array(
				'identity'     => $identity->value(),
				'connected_at' => $this->fixed_at - ( $i + 1 ) * 600,
				'rssi'         => $this->derive_int( $venue->id(), 'rssi-' . $i, -80, -40 ),
			);
		}

		return Result::ok( $clients );
	}

	/**
	 * Deterministically derive an integer in `[$min, $max]`.
	 *
	 * @param string $venue_id Venue identifier.
	 * @param string $key      Native key.
	 * @param int    $min      Inclusive minimum.
	 * @param int    $max      Inclusive maximum.
	 * @return int
	 */
	private function derive_int( string $venue_id, string $key, int $min, int $max ): int {
		$span = $max - $min + 1;

		return $min + ( $this->derive_hash( $venue_id, $key ) % $span );
	}

	/**
	 * Deterministically derive a float in `[$min, $max]`, to one decimal place.
	 *
	 * @param string $venue_id Venue identifier.
	 * @param string $key      Native key.
	 * @param float  $min      Inclusive minimum.
	 * @param float  $max      Inclusive maximum.
	 * @return float
	 */
	private function derive_float( string $venue_id, string $key, float $min, float $max ): float {
		$bucket = $this->derive_hash( $venue_id, $key ) % 1000;
		$ratio  = $bucket / 999;

		return round( $min + ( $max - $min ) * $ratio, 1 );
	}

	/**
	 * Deterministically derive a stable opaque token.
	 *
	 * @param string $venue_id Venue identifier.
	 * @param string $key      Discriminator.
	 * @return string
	 */
	private function derive_token( string $venue_id, string $key ): string {
		return 'dev_' . substr( $this->derive_hex( $venue_id, $key ), 0, 16 );
	}

	/**
	 * 32-bit non-negative hash of the seed, venue and key.
	 *
	 * @param string $venue_id Venue identifier.
	 * @param string $key      Discriminator.
	 * @return int
	 */
	private function derive_hash( string $venue_id, string $key ): int {
		// crc32 is stable across PHP versions and platforms, unlike some hash
		// algorithm outputs that have changed defaults historically.
		$hash = crc32( $this->seed . '|' . $venue_id . '|' . $key );

		return (int) ( $hash & 0x7FFFFFFF );
	}

	/**
	 * Hex digest used for token derivation.
	 *
	 * @param string $venue_id Venue identifier.
	 * @param string $key      Discriminator.
	 * @return string
	 */
	private function derive_hex( string $venue_id, string $key ): string {
		// md5 is used here purely as a deterministic token generator in a mock;
		// it carries no security meaning and never leaves test/demo contexts.
		return md5( $this->seed . '|' . $venue_id . '|' . $key );
	}
}
