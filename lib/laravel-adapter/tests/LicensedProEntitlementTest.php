<?php
/**
 * Tests for the licensed Pro entitlement adapter.
 *
 * Covers the Phase 5.1 commercial source: the happy path, plan mapping,
 * operator overrides, caching, and the fail-closed / stale-while-error policy
 * that keeps a paid surface online through a licence-server outage without
 * ever inventing an entitlement. Proposal 090-B, ADR-7.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Nvoos\Core\Domain\Contract\ProEntitlementInterface;
use Nvoos\Laravel\Adapter\LicensedProEntitlement;

final class LicensedProEntitlementTest extends TestCase {

	private const LICENSE_KEY = 'NVOOS-TEST-KEY';
	private const INSTANCE_ID = 'https://console.test';
	private const SERVER_URL  = 'https://licences.test/api/licenses';

	/**
	 * Build an adapter against the shared test identity.
	 *
	 * @param array<string, bool> $featureMap Operator overrides.
	 */
	private function entitlement( array $featureMap = array() ): LicensedProEntitlement {
		return new LicensedProEntitlement(
			licenseKey: self::LICENSE_KEY,
			serverUrl: self::SERVER_URL,
			instanceId: self::INSTANCE_ID,
			featureMap: $featureMap,
		);
	}

	/**
	 * Bind the licensed driver through the container so the provider's
	 * wiring is exercised, not just the class.
	 */
	private function bindLicensedDriver(): void {
		$this->app['config']->set(
			'oos.pro_entitlement',
			array(
				'driver'      => 'license',
				'license_key' => self::LICENSE_KEY,
				'server_url'  => self::SERVER_URL,
				'instance_id' => self::INSTANCE_ID,
				'product'     => 'mcp-ai-wpoos-pro',
			)
		);

		$this->app->forgetInstance( ProEntitlementInterface::class );
	}

	private function fakeValid( string $plan = 'professional', string $expires = '+1 year' ): void {
		Http::fake(
			array(
				'*' => Http::response( self::validBody( $plan, $expires ) ),
			)
		);
	}

	/**
	 * A licence-server success payload.
	 *
	 * @return array<string,string>
	 */
	private static function validBody( string $plan, string $expires = '+1 year' ): array {
		return array(
			'status'  => 'valid',
			'plan'    => $plan,
			'expires' => gmdate( 'Y-m-d', strtotime( $expires ) ),
		);
	}

	public function test_the_provider_binds_the_licensed_driver(): void {
		$this->bindLicensedDriver();
		$this->fakeValid();

		$entitlement = $this->app->make( ProEntitlementInterface::class );

		$this->assertInstanceOf( LicensedProEntitlement::class, $entitlement );
		$this->assertSame( 'license', $entitlement->source() );
	}

	public function test_a_valid_licence_resolves_its_plan_features(): void {
		$this->fakeValid( 'professional' );

		$entitlement = $this->entitlement();

		$this->assertSame( 'professional', $entitlement->plan() );
		$this->assertTrue( $entitlement->allows( 'reports_advanced' ) );
		$this->assertFalse( $entitlement->allows( 'white_label' ) );
		$this->assertTrue( $entitlement->features()['risk_matrix'] );
	}

	public function test_the_request_carries_the_instance_identity(): void {
		$this->fakeValid();

		$this->entitlement()->allows( 'dashboard' );

		Http::assertSent(
			static function ( Request $request ): bool {
				return str_ends_with( $request->url(), '/check' )
					&& self::LICENSE_KEY === $request['license_key']
					&& self::INSTANCE_ID === $request['site_url']
					&& 'mcp-ai-wpoos-pro' === $request['product'];
			}
		);
	}

	public function test_an_invalid_status_denies_and_is_cached(): void {
		Http::fake( array( '*' => Http::response( array( 'status' => 'expired' ) ) ) );

		$entitlement = $this->entitlement();

		$this->assertFalse( $entitlement->allows( 'dashboard' ) );
		$this->assertSame( '', $entitlement->plan() );
		$this->assertSame( array(), $entitlement->features() );

		// Second read is served from the short negative cache.
		$this->assertFalse( $entitlement->allows( 'dashboard' ) );
		Http::assertSentCount( 1 );
	}

	public function test_an_unknown_plan_fails_closed(): void {
		$this->fakeValid( 'platinum' );

		$entitlement = $this->entitlement();

		$this->assertFalse( $entitlement->allows( 'dashboard' ) );
		$this->assertSame( 'license-unknown-plan:platinum', $entitlement->status()['error'] );
	}

	public function test_an_expired_date_denies_even_when_the_status_is_valid(): void {
		$this->fakeValid( 'enterprise', '-1 day' );

		$entitlement = $this->entitlement();

		$this->assertFalse( $entitlement->allows( 'dashboard' ) );
	}

	public function test_a_missing_key_denies_without_calling_the_server(): void {
		Http::fake();

		$entitlement = new LicensedProEntitlement(
			licenseKey: '',
			serverUrl: self::SERVER_URL,
			instanceId: self::INSTANCE_ID,
		);

		$this->assertFalse( $entitlement->allows( 'dashboard' ) );
		$this->assertSame( 'no-license-key', $entitlement->status()['error'] );
		Http::assertNothingSent();
	}

	public function test_a_missing_server_denies_without_calling_out(): void {
		Http::fake();

		$entitlement = new LicensedProEntitlement(
			licenseKey: self::LICENSE_KEY,
			serverUrl: '',
			instanceId: self::INSTANCE_ID,
		);

		$this->assertFalse( $entitlement->allows( 'dashboard' ) );
		$this->assertSame( 'no-license-server', $entitlement->status()['error'] );
		Http::assertNothingSent();
	}

	public function test_a_transient_failure_with_no_history_denies(): void {
		Http::fake( array( '*' => Http::response( '', 503 ) ) );

		$entitlement = $this->entitlement();

		$this->assertFalse( $entitlement->allows( 'dashboard' ) );
		$this->assertFalse( $entitlement->status()['stale'] );
		$this->assertSame( 'license-http-503', $entitlement->status()['error'] );
	}

	public function test_a_transient_failure_serves_the_last_known_good_answer(): void {
		// `Http::fake()` merges URL stubs rather than replacing them, so the
		// fresh and failing answers have to come from one sequence.
		Http::fake(
			array(
				'*' => Http::sequence()
					->push( self::validBody( 'enterprise' ) )
					->push( '', 500 ),
			)
		);

		$entitlement = $this->entitlement();
		$this->assertTrue( $entitlement->allows( 'white_label' ) );

		// Let the valid answer go stale; the next check hits the 500.
		$this->travel( 13 )->hours();

		$this->assertTrue( $entitlement->allows( 'white_label' ) );

		$status = $entitlement->status();
		$this->assertTrue( $status['stale'] );
		$this->assertSame( 'license-http-500', $status['error'] );
		$this->assertSame( 'enterprise', $status['plan'] );
	}

	public function test_a_fresh_answer_is_served_from_cache(): void {
		$this->fakeValid();

		$entitlement = $this->entitlement();
		$this->assertTrue( $entitlement->allows( 'dashboard' ) );
		$this->assertTrue( $entitlement->allows( 'audit_trail' ) );

		Http::assertSentCount( 1 );
	}

	public function test_a_stale_answer_is_refreshed_once_the_ttl_elapses(): void {
		Http::fake(
			array(
				'*' => Http::sequence()
					->push( self::validBody( 'compliance' ) )
					->push( self::validBody( 'enterprise' ) ),
			)
		);

		$entitlement = $this->entitlement();
		$this->assertTrue( $entitlement->allows( 'dashboard' ) );
		$this->assertFalse( $entitlement->allows( 'white_label' ) );

		$this->travel( 13 )->hours();

		$this->assertTrue( $entitlement->allows( 'white_label' ) );
		$this->assertSame( 'enterprise', $entitlement->plan() );
	}

	public function test_operator_overrides_win_in_both_directions(): void {
		$this->fakeValid( 'compliance' );

		$entitlement = $this->entitlement(
			array(
				'reports_advanced' => true,
				'dashboard'        => false,
			)
		);

		$this->assertTrue( $entitlement->allows( 'reports_advanced' ) );
		$this->assertFalse( $entitlement->allows( 'dashboard' ) );
	}

	public function test_operator_overrides_cannot_resurrect_a_denied_licence(): void {
		Http::fake( array( '*' => Http::response( array( 'status' => 'expired' ) ) ) );

		$entitlement = $this->entitlement( array( 'dashboard' => true ) );

		$this->assertFalse( $entitlement->allows( 'dashboard' ) );
	}
}
