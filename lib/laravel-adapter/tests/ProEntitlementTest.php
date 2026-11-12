<?php
/**
 * Tests for the Laravel ProEntitlement adapter.
 *
 * Verifies the three Phase 0 drivers (allow, deny, config), the fail-closed
 * default for unknown drivers, and the diagnostics surface. Proposal 090-B,
 * ADR-7.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.2.0
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests\Adapter;

use Nvoos\Core\Domain\Contract\ProEntitlementInterface;
use Nvoos\Laravel\Adapter\ProEntitlement;
use PHPUnit\Framework\TestCase;

class ProEntitlementTest extends TestCase {

	public function test_implements_interface(): void {
		$this->assertInstanceOf(
			ProEntitlementInterface::class,
			new ProEntitlement(),
		);
	}

	public function test_allow_driver_entitles_every_feature(): void {
		$entitlement = new ProEntitlement( driver: 'allow', plan: 'agency' );

		$this->assertTrue( $entitlement->allows( 'commerce' ) );
		$this->assertTrue( $entitlement->allows( 'anything_else' ) );
		$this->assertSame( 'agency', $entitlement->plan() );
		$this->assertSame( 'allow', $entitlement->source() );
	}

	public function test_deny_driver_entitles_nothing(): void {
		$entitlement = new ProEntitlement( driver: 'deny' );

		$this->assertFalse( $entitlement->allows( 'commerce' ) );
		$this->assertSame( 'deny', $entitlement->source() );
	}

	public function test_config_driver_honours_the_feature_map(): void {
		$entitlement = new ProEntitlement(
			driver: 'config',
			plan: 'compliance',
			featureMap: array(
				'commerce' => true,
				'media_ops' => false,
			),
		);

		$this->assertTrue( $entitlement->allows( 'commerce' ) );
		$this->assertFalse( $entitlement->allows( 'media_ops' ) );
		$this->assertFalse( $entitlement->allows( 'unknown_feature' ) );
		$this->assertSame(
			array( 'commerce' => true, 'media_ops' => false ),
			$entitlement->features(),
		);
	}

	public function test_unknown_driver_fails_closed(): void {
		$entitlement = new ProEntitlement( driver: 'gateway-not-yet-wired' );

		$this->assertFalse( $entitlement->allows( 'commerce' ) );
		$this->assertSame( 'gateway-not-yet-wired', $entitlement->source() );
	}
}
