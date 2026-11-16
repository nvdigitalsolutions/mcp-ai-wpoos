<?php
/**
 * Standalone domain checks for NV oOS WiFi — no WordPress, no PHPUnit, no vendor/.
 *
 * Run with plain PHP:
 *
 *   php addons/nvoos-wifi/tests/standalone-domain-checks.php
 *   php addons/nvoos-wifi/tests/standalone-domain-checks.php --write-fixture
 *
 * Why this exists alongside the PHPUnit suites: the Phase 0 acceptance criterion
 * is that the domain runs **without a host**. A PHPUnit suite that boots
 * WordPress cannot demonstrate that, because a WP symbol would still resolve.
 * This script requires only the addon's own `includes/` tree, so if it runs, the
 * domain is genuinely host-agnostic.
 *
 * `--write-fixture` regenerates `tests/fixtures/kpis-venue-1-window-1.json`,
 * which is the shared fixture the WordPress and Laravel parity tests compare
 * against (implementation plan T-0.11).
 *
 * @package NvoosWifi
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use NvoosWifi\Domain\Device_Identity;
use NvoosWifi\Domain\Kpi_Sample;
use NvoosWifi\Domain\Kpi_Schema;
use NvoosWifi\Domain\Result;
use NvoosWifi\Domain\Venue;
use NvoosWifi\Domain\Window;
use NvoosWifi\Infrastructure\Providers\Class_Mock_Controller_Adapter;
use NvoosWifi\Interfaces\Interface_WiFi_Clock;
use NvoosWifi\Interfaces\Interface_WiFi_Controller_Adapter;
use NvoosWifi\Interfaces\Interface_WiFi_Kpi_Sink;
use NvoosWifi\Services\Class_Kpi_Service;

require_once __DIR__ . '/../includes/Domain/Result.php';
require_once __DIR__ . '/../includes/Domain/Window.php';
require_once __DIR__ . '/../includes/Domain/Venue.php';
require_once __DIR__ . '/../includes/Domain/Device_Identity.php';
require_once __DIR__ . '/../includes/Domain/Kpi_Schema.php';
require_once __DIR__ . '/../includes/Domain/Kpi_Sample.php';
require_once __DIR__ . '/../includes/Interfaces/Interface_WiFi_Controller_Adapter.php';
require_once __DIR__ . '/../includes/Interfaces/Interface_WiFi_Clock.php';
require_once __DIR__ . '/../includes/Interfaces/Interface_WiFi_Id_Generator.php';
require_once __DIR__ . '/../includes/Interfaces/Interface_WiFi_Kpi_Sink.php';
require_once __DIR__ . '/../includes/Services/Class_Kpi_Service.php';
require_once __DIR__ . '/../includes/Infrastructure/Providers/Class_Mock_Controller_Adapter.php';

/**
 * Fixed clock for reproducible output.
 *
 * @since 0.1.0
 */
final class Frozen_Clock implements Interface_WiFi_Clock {

	/**
	 * Returned timestamp.
	 *
	 * @var int
	 */
	private int $at;

	/**
	 * Construct with a timestamp.
	 *
	 * @param int $at Timestamp to return.
	 */
	public function __construct( int $at ) {
		$this->at = $at;
	}

	/**
	 * {@inheritdoc}
	 */
	public function now(): int {
		return $this->at;
	}
}

/**
 * In-memory sink, so the checks need no database.
 *
 * @since 0.1.0
 */
final class Array_Sink implements Interface_WiFi_Kpi_Sink {

	/**
	 * Stored rows keyed by venue.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	public array $rows = array();

	/**
	 * {@inheritdoc}
	 */
	public function write( array $samples ): Result {
		$written = 0;

		foreach ( $samples as $sample ) {
			if ( ! $sample instanceof Kpi_Sample ) {
				return Result::fail( 'invalid_sample', 'Not a Kpi_Sample.' );
			}

			$row = $sample->to_array();
			$id  = $row['key'] . '|' . $row['at'] . '|' . $row['source_adapter'];

			$this->rows[ $sample->venue_id() ][ $id ] = $row;
			++$written;
		}

		return Result::ok( array( 'written' => $written ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function read( string $venue_id, Window $window ): Result {
		$out = array();

		foreach ( $this->rows[ $venue_id ] ?? array() as $row ) {
			$sample = Kpi_Sample::make(
				$row['key'],
				$row['value'],
				$row['at'],
				$row['venue_id'],
				$row['method'],
				$row['source_adapter']
			);

			if ( $sample->is_ok() && $window->contains( (int) $row['at'] ) ) {
				$out[] = $sample->value();
			}
		}

		return Result::ok( $out );
	}
}

/**
 * Adapter with no qualifying identity, to prove identity KPIs are skipped.
 *
 * @since 0.1.0
 */
final class No_Identity_Adapter implements Interface_WiFi_Controller_Adapter {

	/**
	 * {@inheritdoc}
	 */
	public function id(): string {
		return 'no-identity';
	}

	/**
	 * {@inheritdoc}
	 */
	public function label(): string {
		return 'Adapter without device identity';
	}

	/**
	 * {@inheritdoc}
	 */
	public function health(): Result {
		return Result::ok(
			array(
				'reachable'  => true,
				'latency_ms' => 1,
				'detail'     => 'fixture',
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function identity_method(): ?string {
		return null;
	}

	/**
	 * {@inheritdoc}
	 */
	public function kpi_map(): array {
		return array(
			'native_client_count' => 'wifi.clients.connected',
			'native_latency_ms'   => 'wifi.quality.latency',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function read_radio_stats( Venue $venue, Window $window ): Result {
		unset( $venue, $window );

		return Result::ok(
			array(
				'at'     => 1763424000,
				'values' => array(
					'native_client_count' => 99,
					'native_latency_ms'   => 21,
				),
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function list_clients( Venue $venue ): Result {
		unset( $venue );

		return Result::ok( array() );
	}
}

// ---------------------------------------------------------------------------
// Harness.
// ---------------------------------------------------------------------------

$failures = array();
$checks   = 0;

/**
 * Assert a condition.
 *
 * @param bool   $condition Condition.
 * @param string $label     Human label.
 * @return void
 */
function check( bool $condition, string $label ): void {
	global $failures, $checks;

	++$checks;

	if ( ! $condition ) {
		$failures[] = $label;
		echo "  ✗ {$label}\n";
		return;
	}

	echo "  ✓ {$label}\n";
}

/**
 * Build the canonical Phase 0 payload.
 *
 * @param Interface_WiFi_Controller_Adapter $adapter Adapter to use.
 * @param int                               $clock   Frozen timestamp.
 * @return array<string, mixed>
 */
function build_payload( Interface_WiFi_Controller_Adapter $adapter, int $clock ): array {
	$venue_result = Venue::from_array(
		array(
			'id'        => 'venue-1',
			'label'     => 'Kingston Waterfront',
			'timezone'  => 'America/Jamaica',
			'residency' => 'jm',
		)
	);

	$venue = $venue_result->value();

	$window = Window::make( 1763337600, 1763424000 )->value();

	$service = new Class_Kpi_Service( $adapter, new Array_Sink(), new Frozen_Clock( $clock ) );
	$result  = $service->for_venue( $venue, $window );

	if ( $result->is_error() ) {
		fwrite( STDERR, "Service failed: {$result->code()} — {$result->message()}\n" );
		exit( 1 );
	}

	return $result->value();
}

echo "NV oOS WiFi — standalone domain checks\n\n";

echo "Validation invariants\n";

check( Window::make( 10, 10 )->code() === 'invalid_window', 'Window rejects a zero-length span' );
check( Window::make( -1, 10 )->code() === 'invalid_window', 'Window rejects negative bounds' );

check(
	Venue::from_array( array( 'id' => 'v', 'label' => 'V', 'timezone' => 'Mars/Olympus' ) )->code() === 'invalid_timezone',
	'Venue rejects an unknown timezone'
);
check(
	Venue::from_array( array( 'id' => '', 'label' => 'V' ) )->code() === 'invalid_venue',
	'Venue rejects an empty id'
);

echo "\nDevice identity (802.11bh / RFC 9724 — no MACs)\n";

check(
	Device_Identity::make( 'aa:bb:cc:dd:ee:ff', Device_Identity::METHOD_DEVICE_ID )->code() === 'mac_not_allowed',
	'Rejects a colon-form MAC'
);
check(
	Device_Identity::make( 'AABBCCDDEEFF', Device_Identity::METHOD_DEVICE_ID )->code() === 'mac_not_allowed',
	'Rejects a bare 12-hex MAC'
);
check(
	Device_Identity::make( 'dev_9f2c1a', Device_Identity::METHOD_DEVICE_ID )->is_ok(),
	'Accepts an opaque device id'
);
check(
	Device_Identity::make( 'dev_9f2c1a', 'fingerprint' )->code() === 'invalid_device_identity',
	'Rejects an unknown identity method'
);
check(
	Device_Identity::make( 'hash_1', Device_Identity::METHOD_PROBE_HASH )->value()->is_weak(),
	'Flags probe-hash identities as weak'
);

echo "\nKPI sample invariants (method required for entity counts)\n";

check(
	Kpi_Sample::make( 'wifi.clients.unique', 5, 1763424000, 'venue-1', null, 'mock' )->code() === 'method_required',
	'Rejects an entity count with no method'
);
check(
	Kpi_Sample::make( 'wifi.clients.unique', 5, 1763424000, 'venue-1', 'device_id', 'mock' )->is_ok(),
	'Accepts an entity count with a method'
);
check(
	Kpi_Sample::make( 'wifi.quality.latency', 12, 1763424000, 'venue-1', null, 'mock' )->is_ok(),
	'Accepts a non-entity KPI without a method'
);
check(
	Kpi_Sample::make( 'wifi.invented.metric', 1, 1763424000, 'venue-1', null, 'mock' )->code() === 'unknown_kpi_key',
	'Rejects a KPI key outside the schema'
);
check(
	Kpi_Sample::make( 'wifi.quality.latency', 'twelve', 1763424000, 'venue-1', null, 'mock' )->code() === 'invalid_kpi_value',
	'Rejects a non-numeric value'
);

echo "\nSchema integrity\n";

check( count( Kpi_Schema::keys() ) === count( Kpi_Schema::DEFINITIONS ), 'keys() matches DEFINITIONS' );
check( count( Kpi_Schema::keys() ) === 18, 'Phase 0 schema ships 18 KPIs (got ' . count( Kpi_Schema::keys() ) . ')' );

$all_string_enums = true;
foreach ( Kpi_Schema::DEFINITIONS as $key => $definition ) {
	if ( ! is_string( $key ) || ! is_string( $definition['de_name'] ) || ! is_string( $definition['unit'] ) ) {
		$all_string_enums = false;
		break;
	}
}
check( $all_string_enums, 'Every schema key, de_name and unit is a string' );

echo "\nService wiring (mock adapter)\n";

$payload = build_payload( new Class_Mock_Controller_Adapter(), 1763424100 );

check( isset( $payload['samples'], $payload['venue'], $payload['window'], $payload['adapter'] ), 'Payload has the canonical sections' );
check( count( $payload['samples'] ) === 11, 'Mock adapter normalises 11 of 11 mapped KPIs (got ' . count( $payload['samples'] ) . ')' );

$sorted = true;
$prev   = null;
foreach ( $payload['samples'] as $sample ) {
	if ( null !== $prev && strcmp( (string) $prev, (string) $sample['key'] ) >= 0 ) {
		$sorted = false;
		break;
	}
	$prev = $sample['key'];
}
check( $sorted, 'Samples are sorted by canonical key' );

$identity_ok = true;
foreach ( $payload['samples'] as $sample ) {
	if ( Kpi_Schema::requires_method( $sample['key'] ) && null === $sample['method'] ) {
		$identity_ok = false;
		break;
	}
}
check( $identity_ok, 'Every entity-count sample carries a method' );

$in_window = true;
foreach ( $payload['samples'] as $sample ) {
	if ( ! window_contains( $payload['window'], (int) $sample['at'] ) ) {
		$in_window = false;
		break;
	}
}
check( $in_window, 'Every sample timestamp falls inside the reported window' );

$unique_sample = null;
foreach ( $payload['samples'] as $sample ) {
	if ( 'wifi.clients.unique' === $sample['key'] ) {
		$unique_sample = $sample;
		break;
	}
}
check( null !== $unique_sample, 'wifi.clients.unique is present' );
check(
	null !== $unique_sample && 'device_id' === $unique_sample['method'],
	'wifi.clients.unique declares method=device_id'
);
check(
	null !== $unique_sample && is_string( $unique_sample['de_name'] ),
	'wifi.clients.unique exposes its Data Elements name'
);

echo "\nDeterminism (parity fixture prerequisite)\n";

$payload_b = build_payload( new Class_Mock_Controller_Adapter(), 1763424100 );
check(
	wp_json_like( $payload ) === wp_json_like( $payload_b ),
	'Two independent runs produce identical JSON'
);

/**
 * Stable JSON encoding for comparison (no WP dependency).
 *
 * @param mixed $value Value.
 * @return string
 */
function wp_json_like( $value ): string {
	return (string) json_encode( $value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
}

/**
 * Whether a timestamp falls inside a serialised window (half-open).
 *
 * @param array<string, mixed> $window Window array with `from` and `to`.
 * @param int                  $at     Timestamp.
 * @return bool
 */
function window_contains( array $window, int $at ): bool {
	return $at >= (int) $window['from'] && $at < (int) $window['to'];
}

echo "\nHonest degradation (adapter without identity)\n";

$no_identity = build_payload( new No_Identity_Adapter(), 1763424100 );

$has_entity_sample = false;
foreach ( $no_identity['samples'] as $sample ) {
	if ( 'wifi.clients.connected' === $sample['key'] ) {
		$has_entity_sample = true;
		break;
	}
}
check( ! $has_entity_sample, 'Entity-count KPI is withheld when identity is unavailable' );

$skip_reason = null;
foreach ( $no_identity['skipped'] as $entry ) {
	if ( 'wifi.clients.connected' === $entry['key'] ) {
		$skip_reason = $entry['reason'];
		break;
	}
}
check( 'no_identity_method' === $skip_reason, 'The skip is reported as no_identity_method' );

$latency_present = false;
foreach ( $no_identity['samples'] as $sample ) {
	if ( 'wifi.quality.latency' === $sample['key'] ) {
		$latency_present = true;
		break;
	}
}
check( $latency_present, 'Non-entity KPIs still flow when identity is unavailable' );

// ---------------------------------------------------------------------------
// Optional fixture emission.
// ---------------------------------------------------------------------------
if ( in_array( '--write-fixture', $argv, true ) ) {
	$fixture = __DIR__ . '/fixtures/kpis-venue-1-window-1.json';

	if ( empty( $failures ) ) {
		$json = wp_json_like( $payload ) . "\n";
		file_put_contents( $fixture, $json ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Local test fixture, CLI only.
		echo "\n· Wrote fixture: {$fixture}\n";
	} else {
		echo "\n· Fixture not written — checks failed.\n";
	}
}

// ---------------------------------------------------------------------------
// Result.
// ---------------------------------------------------------------------------
$failed = count( $failures );

printf( "\n%s %d check(s), %d failure(s)\n", 0 === $failed ? '✓' : '✗', $checks, $failed );

if ( $failed > 0 ) {
	foreach ( $failures as $failure ) {
		echo "  - {$failure}\n";
	}
	exit( 1 );
}

exit( 0 );
