<?php
/**
 * Verify the Laravel host's NV oOS WiFi adapters without booting Laravel.
 *
 * Proposal 150, T-0.10 / T-0.11. Answers three questions that would otherwise
 * need the Docker container:
 *
 *   1. Do `App\Wifi\*` classes actually satisfy the portable ports?
 *      (A signature mismatch is a fatal error at class-load time, and PHP would
 *      only surface it when the route is first hit.)
 *   2. Does the Laravel host produce the *same payload* as the committed fixture?
 *      This is the parity claim Phase 0 exists to prove.
 *   3. Does the Laravel `CacheKpiSink` round-trip, including idempotency?
 *
 * `Illuminate\Contracts\Cache\Repository` is stubbed because only its method
 * shapes matter here. The real container run additionally exercises routing,
 * Sanctum and the `can:manage_options` gate — `tests/Feature/WifiKpisTest.php`
 * covers those.
 *
 * Usage:
 *   php bin/check-wifi-host-adapters.php
 *
 * @package WP_MCP_AI
 * @since   2.7.0
 */

declare( strict_types=1 );

namespace Illuminate\Contracts\Cache {
	/**
	 * Minimal stub of the Laravel cache contract.
	 *
	 * Declared only because `App\Wifi\CacheKpiSink` type-hints it. No Laravel
	 * behaviour is emulated beyond `get`/`put`.
	 */
	interface Repository {
		/**
		 * Read a cache entry.
		 *
		 * @param string $key     Entry key.
		 * @param mixed  $default Fallback.
		 * @return mixed
		 */
		public function get( $key, $default = null );

		/**
		 * Write a cache entry.
		 *
		 * @param string $key   Entry key.
		 * @param mixed  $value Value.
		 * @param mixed  $ttl   TTL.
		 * @return bool
		 */
		public function put( $key, $value, $ttl = null );
	}
}

namespace {

	$root = dirname( __DIR__ );

	/**
	 * Minimal stub of Laravel's `config()` helper.
	 *
	 * Supplies the venue records that `config/wifi.php` would provide.
	 *
	 * @param string $key     Config key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	function config( $key, $default = null ) {
		if ( 'wifi.venues' === $key ) {
			return array(
				'venue-1' => array(
					'label'     => 'Kingston Waterfront',
					'timezone'  => 'America/Jamaica',
					'residency' => 'jm',
				),
			);
		}

		return $default;
	}

	$includes = $root . '/addons/nvoos-wifi/includes/';

	require_once $includes . 'Domain/Result.php';
	require_once $includes . 'Domain/Window.php';
	require_once $includes . 'Domain/Venue.php';
	require_once $includes . 'Domain/Device_Identity.php';
	require_once $includes . 'Domain/Kpi_Schema.php';
	require_once $includes . 'Domain/Kpi_Sample.php';
	require_once $includes . 'Interfaces/Interface_WiFi_Controller_Adapter.php';
	require_once $includes . 'Interfaces/Interface_WiFi_Clock.php';
	require_once $includes . 'Interfaces/Interface_WiFi_Id_Generator.php';
	require_once $includes . 'Interfaces/Interface_WiFi_Kpi_Sink.php';
	require_once $includes . 'Infrastructure/Providers/Class_Mock_Controller_Adapter.php';
	require_once $includes . 'Services/Class_Kpi_Service.php';

	$app = $root . '/docker/laravel/app-skeleton/app/Wifi/';

	if ( ! is_dir( $app ) ) {
		fwrite( STDERR, "No Laravel WiFi host found at {$app}\n" );
		exit( 1 );
	}

	require_once $app . 'Clock.php';
	require_once $app . 'IdGenerator.php';
	require_once $app . 'CacheKpiSink.php';
	require_once $app . 'VenueRepository.php';

	$checks = 0;
	$failed = 0;

	/**
	 * Record a check result.
	 *
	 * @param bool   $condition Assertion.
	 * @param string $label     Description.
	 * @return void
	 */
	function check( bool $condition, string $label ): void {
		global $checks, $failed;

		++$checks;

		if ( $condition ) {
			echo "  \u{2713} {$label}\n";

			return;
		}

		++$failed;
		echo "  \u{2717} {$label}\n";
	}

	echo "NV oOS WiFi — Laravel host adapter conformance\n\n";
	echo "Ports\n";

	check(
		is_subclass_of( App\Wifi\Clock::class, NvoosWifi\Interfaces\Interface_WiFi_Clock::class ),
		'Clock implements Interface_WiFi_Clock'
	);
	check( is_int( ( new App\Wifi\Clock() )->now() ), 'Clock::now() returns int' );

	check(
		is_subclass_of( App\Wifi\IdGenerator::class, NvoosWifi\Interfaces\Interface_WiFi_Id_Generator::class ),
		'IdGenerator implements Interface_WiFi_Id_Generator'
	);

	$generator = new App\Wifi\IdGenerator();

	check( str_starts_with( $generator->new_device_id(), 'dev_' ), 'device ids are dev_ prefixed' );
	check( str_starts_with( $generator->new_session_id(), 'ses_' ), 'session ids are ses_ prefixed' );
	check( 36 === strlen( $generator->new_device_id() ), 'device ids are 36 characters' );
	check( $generator->new_device_id() !== $generator->new_device_id(), 'device ids are unique' );

	check(
		is_subclass_of( App\Wifi\CacheKpiSink::class, NvoosWifi\Interfaces\Interface_WiFi_Kpi_Sink::class ),
		'CacheKpiSink implements Interface_WiFi_Kpi_Sink'
	);

	check(
		class_exists( App\Wifi\VenueRepository::class ),
		'VenueRepository loads'
	);

	echo "\nPayload parity\n";

	$cache = new class implements Illuminate\Contracts\Cache\Repository {
		/**
		 * In-memory store.
		 *
		 * @var array<string, mixed>
		 */
		private array $store = array();

		/**
		 * {@inheritdoc}
		 */
		public function get( $key, $default = null ) {
			return $this->store[ $key ] ?? $default;
		}

		/**
		 * {@inheritdoc}
		 */
		public function put( $key, $value, $ttl = null ) {
			$this->store[ $key ] = $value;

			return true;
		}
	};

	$clock = new class implements NvoosWifi\Interfaces\Interface_WiFi_Clock {
		/**
		 * {@inheritdoc}
		 */
		public function now(): int {
			return 1763424100;
		}
	};

	$service = new NvoosWifi\Services\Class_Kpi_Service(
		new NvoosWifi\Infrastructure\Providers\Class_Mock_Controller_Adapter(),
		new App\Wifi\CacheKpiSink( $cache ),
		$clock
	);

	$venue = NvoosWifi\Domain\Venue::from_array(
		array(
			'id'        => 'venue-1',
			'label'     => 'Kingston Waterfront',
			'timezone'  => 'America/Jamaica',
			'residency' => 'jm',
		)
	);

	$window = NvoosWifi\Domain\Window::make( 1763337600, 1763424000 );

	check( $venue->is_ok() && $window->is_ok(), 'Venue and window construct' );

	$result = $service->for_venue( $venue->value(), $window->value() );

	check( $result->is_ok(), 'Laravel-host service call succeeds' );

	if ( $result->is_ok() ) {
		$fixture_path = $root . '/docker/laravel/app-skeleton/tests/fixtures/wifi/kpis-venue-1-window-1.json';
		$fixture      = json_decode( (string) file_get_contents( $fixture_path ), true );

		check(
			$fixture === $result->value(),
			'Laravel payload is identical (including key order) to its fixture'
		);

		$stored = $service->stored( $venue->value(), $window->value() );

		check(
			$stored->is_ok() && count( $stored->value()['samples'] ) === count( $result->value()['samples'] ),
			'CacheKpiSink persists and reads back every sample'
		);
	}

	echo "\nRepository\n";

	$repository = new App\Wifi\VenueRepository();
	$found      = $repository->find( 'venue-1' );

	check(
		$found->is_ok() && 'Kingston Waterfront' === $found->value()->label(),
		'VenueRepository finds a configured venue'
	);

	$missing = $repository->find( 'venue-missing' );

	check(
		$missing->is_error() && 'venue_not_found' === $missing->code(),
		'VenueRepository returns venue_not_found for an unknown venue'
	);

	printf( "\n%s %d check(s), %d failure(s)\n", 0 === $failed ? "\u{2713}" : "\u{2717}", $checks, $failed );

	exit( 0 === $failed ? 0 : 1 );
}
