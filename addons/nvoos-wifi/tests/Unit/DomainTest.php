<?php
/**
 * Unit tests for the NV oOS WiFi domain layer.
 *
 * These extend `PHPUnit\Framework\TestCase`, **not** `WP_UnitTestCase`. That is
 * intentional and load-bearing: a domain test that needs the WordPress test case
 * would prove the domain is not host-agnostic, which is exactly what Phase 0
 * exists to demonstrate (Proposal 150 implementation plan T-0.5).
 *
 * Mirrors `tests/standalone-domain-checks.php`, which runs the same assertions
 * with no PHPUnit and no vendor/ at all.
 *
 * @package NvoosWifi
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

namespace NvoosWifi\Tests\Unit;

use NvoosWifi\Domain\Device_Identity;
use NvoosWifi\Domain\Kpi_Sample;
use NvoosWifi\Domain\Kpi_Schema;
use NvoosWifi\Domain\Venue;
use NvoosWifi\Domain\Window;
use PHPUnit\Framework\TestCase;

// ---------------------------------------------------------------------------
// Self-contained loading.
//
// The addon's own composer.json is NOT installed into the repository's vendor/
// directory (it is a plugin directory, and `type: library` keeps it out of
// wp-content/plugins). The root autoloader is a classmap over `includes/` only.
// So nothing autoloads `NvoosWifi\*` during a CI run, and without these requires
// every assertion below would fail with "class not found".
//
// Requiring the domain files directly also keeps this test honest: it runs with
// no host, which is exactly the property Phase 0 exists to prove.
// ---------------------------------------------------------------------------
$nvoos_wifi_includes = dirname( __DIR__, 2 ) . '/includes/';

require_once $nvoos_wifi_includes . 'Domain/Result.php';
require_once $nvoos_wifi_includes . 'Domain/Window.php';
require_once $nvoos_wifi_includes . 'Domain/Venue.php';
require_once $nvoos_wifi_includes . 'Domain/Device_Identity.php';
require_once $nvoos_wifi_includes . 'Domain/Kpi_Schema.php';
require_once $nvoos_wifi_includes . 'Domain/Kpi_Sample.php';

/**
 * Domain layer behaviour.
 *
 * @since 0.1.0
 */
final class DomainTest extends TestCase {

	/**
	 * Domain classes load without a WordPress install.
	 *
	 * @return void
	 */
	public function test_domain_classes_are_loadable_without_wordpress(): void {
		$this->assertTrue( class_exists( Window::class ) );
		$this->assertTrue( class_exists( Venue::class ) );
		$this->assertTrue( class_exists( Device_Identity::class ) );
		$this->assertTrue( class_exists( Kpi_Schema::class ) );
		$this->assertTrue( class_exists( Kpi_Sample::class ) );
	}

	/**
	 * A window must be positive-length.
	 *
	 * @return void
	 */
	public function test_window_rejects_zero_and_negative_spans(): void {
		$this->assertSame( 'invalid_window', Window::make( 10, 10 )->code() );
		$this->assertSame( 'invalid_window', Window::make( 20, 10 )->code() );
		$this->assertSame( 'invalid_window', Window::make( -1, 10 )->code() );
	}

	/**
	 * A valid window exposes duration and half-open containment.
	 *
	 * @return void
	 */
	public function test_window_is_half_open(): void {
		$window = Window::make( 100, 200 )->value();

		$this->assertSame( 100, $window->duration() );
		$this->assertTrue( $window->contains( 100 ) );
		$this->assertTrue( $window->contains( 199 ) );
		$this->assertFalse( $window->contains( 200 ) );
	}

	/**
	 * Venue validation rejects bad input with stable codes.
	 *
	 * @return void
	 */
	public function test_venue_validation(): void {
		$ok = Venue::from_array(
			array(
				'id'        => 'venue-1',
				'label'     => 'Waterfront',
				'timezone'  => 'America/Jamaica',
				'residency' => 'jm',
			)
		);

		$this->assertTrue( $ok->is_ok() );
		$this->assertSame( 'America/Jamaica', $ok->value()->timezone() );

		$this->assertSame( 'invalid_venue', Venue::from_array( array( 'id' => '', 'label' => 'X' ) )->code() );
		$this->assertSame(
			'invalid_timezone',
			Venue::from_array(
				array(
					'id'       => 'v',
					'label'    => 'V',
					'timezone' => 'Mars/Olympus',
				)
			)->code()
		);
		$this->assertSame(
			'invalid_residency',
			Venue::from_array(
				array(
					'id'        => 'v',
					'label'     => 'V',
					'residency' => 'atlantis',
				)
			)->code()
		);
	}

	/**
	 * MAC-shaped identifiers are refused (802.11bh / RFC 9724).
	 *
	 * @return void
	 */
	public function test_device_identity_rejects_mac_addresses(): void {
		$this->assertSame(
			'mac_not_allowed',
			Device_Identity::make( 'aa:bb:cc:dd:ee:ff', Device_Identity::METHOD_DEVICE_ID )->code()
		);
		$this->assertSame(
			'mac_not_allowed',
			Device_Identity::make( 'AA-BB-CC-DD-EE-FF', Device_Identity::METHOD_DEVICE_ID )->code()
		);
		$this->assertSame(
			'mac_not_allowed',
			Device_Identity::make( 'aabbccddeeff', Device_Identity::METHOD_DEVICE_ID )->code()
		);
	}

	/**
	 * Opaque identifiers are accepted; unknown methods are not.
	 *
	 * @return void
	 */
	public function test_device_identity_accepts_opaque_ids(): void {
		$this->assertTrue(
			Device_Identity::make( 'dev_9f2c1a7b', Device_Identity::METHOD_DEVICE_ID )->is_ok()
		);
		$this->assertSame(
			'invalid_device_identity',
			Device_Identity::make( 'dev_9f2c1a7b', 'fingerprint' )->code()
		);
		$this->assertSame(
			'invalid_device_identity',
			Device_Identity::make( '', Device_Identity::METHOD_DEVICE_ID )->code()
		);
	}

	/**
	 * Probe-hash identities are flagged as the weaker method.
	 *
	 * @return void
	 */
	public function test_probe_hash_is_flagged_weak(): void {
		$identity = Device_Identity::make( 'ph_abc123', Device_Identity::METHOD_PROBE_HASH )->value();

		$this->assertTrue( $identity->is_weak() );

		$device = Device_Identity::make( 'dev_abc123', Device_Identity::METHOD_DEVICE_ID )->value();
		$this->assertFalse( $device->is_weak() );
	}

	/**
	 * Entity counts must declare a method.
	 *
	 * @return void
	 */
	public function test_entity_counts_require_a_method(): void {
		$this->assertSame(
			'method_required',
			Kpi_Sample::make( 'wifi.clients.unique', 5, 1763424000, 'venue-1', null, 'mock' )->code()
		);
		$this->assertTrue(
			Kpi_Sample::make( 'wifi.clients.unique', 5, 1763424000, 'venue-1', 'device_id', 'mock' )->is_ok()
		);
		$this->assertTrue(
			Kpi_Sample::make( 'wifi.quality.latency', 12, 1763424000, 'venue-1', null, 'mock' )->is_ok()
		);
	}

	/**
	 * Values outside the schema are refused.
	 *
	 * @return void
	 */
	public function test_sample_validation(): void {
		$this->assertSame(
			'unknown_kpi_key',
			Kpi_Sample::make( 'wifi.invented.metric', 1, 1763424000, 'venue-1', null, 'mock' )->code()
		);
		$this->assertSame(
			'invalid_kpi_value',
			Kpi_Sample::make( 'wifi.quality.latency', 'twelve', 1763424000, 'venue-1', null, 'mock' )->code()
		);
		$this->assertSame(
			'invalid_method',
			Kpi_Sample::make( 'wifi.quality.latency', 1, 1763424000, 'venue-1', 'wishful', 'mock' )->code()
		);
	}

	/**
	 * A null value is preserved as absence, never coerced to zero.
	 *
	 * @return void
	 */
	public function test_null_value_is_not_zero(): void {
		$sample = Kpi_Sample::make( 'wifi.quality.latency', null, 1763424000, 'venue-1', null, 'mock' )->value();

		$this->assertNull( $sample->value() );
		$this->assertFalse( $sample->has_value() );
	}

	/**
	 * The schema is self-consistent and exposes Data Elements names.
	 *
	 * @return void
	 */
	public function test_schema_integrity(): void {
		$this->assertSame( count( Kpi_Schema::keys() ), count( Kpi_Schema::DEFINITIONS ) );

		foreach ( Kpi_Schema::DEFINITIONS as $key => $definition ) {
			$this->assertTrue( Kpi_Schema::has( $key ) );
			$this->assertNotEmpty( $definition['de_name'] );
			$this->assertContains( $definition['type'], array( 'gauge', 'counter' ) );
			$this->assertContains( $definition['unit'], array( 'count', 'percent', 'dbm', 'ms', 'mbps' ) );
		}
	}

	/**
	 * Entity-counting KPIs are flagged identity-sensitive.
	 *
	 * @return void
	 */
	public function test_identity_sensitive_flags(): void {
		$this->assertTrue( Kpi_Schema::requires_method( 'wifi.clients.connected' ) );
		$this->assertTrue( Kpi_Schema::requires_method( 'wifi.clients.unique' ) );
		$this->assertTrue( Kpi_Schema::requires_method( 'wifi.sessions.started' ) );
		$this->assertFalse( Kpi_Schema::requires_method( 'wifi.radio.retry_rate' ) );
		$this->assertFalse( Kpi_Schema::requires_method( 'wifi.quality.latency' ) );
	}
}
