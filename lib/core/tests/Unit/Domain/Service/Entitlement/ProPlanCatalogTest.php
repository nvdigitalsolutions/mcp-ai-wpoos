<?php
/**
 * Tests for the shared Pro plan catalogue.
 *
 * The catalogue is the parity mechanism between the WordPress plugin and the
 * Laravel adapter, so these tests pin both its content and its fail-closed
 * behaviour (Proposal 090-B, Phase 5.1).
 *
 * @package Nvoos\Core\Tests
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Domain\Service\Entitlement;

use Nvoos\Core\Domain\Service\Entitlement\ProPlanCatalog;
use PHPUnit\Framework\TestCase;

final class ProPlanCatalogTest extends TestCase {

	public function testPlansAreOrderedSmallestFirst(): void {
		$this->assertSame(
			array( 'compliance', 'professional', 'enterprise' ),
			ProPlanCatalog::plans()
		);
	}

	public function testUnknownPlanIsNotInCatalogue(): void {
		$this->assertTrue( ProPlanCatalog::hasPlan( 'compliance' ) );
		$this->assertFalse( ProPlanCatalog::hasPlan( 'platinum' ) );
	}

	public function testUnknownPlanEntitlesNothingAndHasNoFeatures(): void {
		$this->assertSame( array(), ProPlanCatalog::features( 'platinum' ) );
		$this->assertFalse( ProPlanCatalog::allows( 'platinum', 'dashboard' ) );
	}

	public function testUnknownFeatureIsDenied(): void {
		$this->assertFalse( ProPlanCatalog::allows( 'enterprise', 'time_travel' ) );
	}

	public function testPlansAreCumulative(): void {
		$compliance   = ProPlanCatalog::features( 'compliance' );
		$professional = ProPlanCatalog::features( 'professional' );
		$enterprise   = ProPlanCatalog::features( 'enterprise' );

		$this->assertSame( array(), array_diff( $compliance, $professional ) );
		$this->assertSame( array(), array_diff( $professional, $enterprise ) );
	}

	public function testTierSpecificFeatures(): void {
		$this->assertFalse( ProPlanCatalog::allows( 'compliance', 'reports_advanced' ) );
		$this->assertTrue( ProPlanCatalog::allows( 'professional', 'reports_advanced' ) );

		$this->assertFalse( ProPlanCatalog::allows( 'professional', 'white_label' ) );
		$this->assertTrue( ProPlanCatalog::allows( 'enterprise', 'white_label' ) );

		$this->assertTrue( ProPlanCatalog::allows( 'compliance', 'dashboard' ) );
	}

	public function testRankOrdersPlansAndRejectsUnknown(): void {
		$this->assertSame( 0, ProPlanCatalog::rank( 'compliance' ) );
		$this->assertGreaterThan( ProPlanCatalog::rank( 'compliance' ), ProPlanCatalog::rank( 'professional' ) );
		$this->assertGreaterThan( ProPlanCatalog::rank( 'professional' ), ProPlanCatalog::rank( 'enterprise' ) );
		$this->assertSame( -1, ProPlanCatalog::rank( 'platinum' ) );
	}
}
