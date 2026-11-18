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

final class ProPlanCatalogTest extends TestCase
{
    public function testPlansAreOrderedSmallestFirst(): void
    {
        $this->assertSame(
            array( 'compliance', 'professional', 'enterprise' ),
            ProPlanCatalog::plans()
        );
    }

    public function testUnknownPlanIsNotInCatalogue(): void
    {
        $this->assertTrue(ProPlanCatalog::hasPlan('compliance'));
        $this->assertFalse(ProPlanCatalog::hasPlan('platinum'));
    }

    public function testUnknownPlanEntitlesNothingAndHasNoFeatures(): void
    {
        $this->assertSame(array(), ProPlanCatalog::features('platinum'));
        $this->assertFalse(ProPlanCatalog::allows('platinum', 'dashboard'));
    }

    public function testUnknownFeatureIsDenied(): void
    {
        $this->assertFalse(ProPlanCatalog::allows('enterprise', 'time_travel'));
    }

    public function testPlansAreCumulative(): void
    {
        $compliance   = ProPlanCatalog::features('compliance');
        $professional = ProPlanCatalog::features('professional');
        $enterprise   = ProPlanCatalog::features('enterprise');

        $this->assertSame(array(), array_diff($compliance, $professional));
        $this->assertSame(array(), array_diff($professional, $enterprise));
    }

    public function testTierSpecificFeatures(): void
    {
        $this->assertFalse(ProPlanCatalog::allows('compliance', 'reports_advanced'));
        $this->assertTrue(ProPlanCatalog::allows('professional', 'reports_advanced'));

        $this->assertFalse(ProPlanCatalog::allows('professional', 'white_label'));
        $this->assertTrue(ProPlanCatalog::allows('enterprise', 'white_label'));

        $this->assertTrue(ProPlanCatalog::allows('compliance', 'dashboard'));
    }

    public function testRankOrdersPlansAndRejectsUnknown(): void
    {
        $this->assertSame(0, ProPlanCatalog::rank('compliance'));
        $this->assertGreaterThan(ProPlanCatalog::rank('compliance'), ProPlanCatalog::rank('professional'));
        $this->assertGreaterThan(ProPlanCatalog::rank('professional'), ProPlanCatalog::rank('enterprise'));
        $this->assertSame(-1, ProPlanCatalog::rank('platinum'));
    }

    /**
     * Plans are cumulative by construction — each list repeats the previous —
     * which means a feature added to one list and not the next silently
     * downgrades the larger plan. This pins the invariant so the repetition
     * cannot drift.
     */
    public function testEachPlanContainsEveryFeatureOfTheSmallerPlans(): void
    {
        $plans = ProPlanCatalog::plans();

        for ($i = 1; $i < count($plans); $i++) {
            $smaller = ProPlanCatalog::features($plans[$i - 1]);
            $larger = ProPlanCatalog::features($plans[$i]);

            foreach ($smaller as $feature) {
                $this->assertContains(
                    $feature,
                    $larger,
                    "Plan '{$plans[$i]}' is missing '{$feature}' from the smaller plan '{$plans[$i - 1]}'."
                );
            }
        }
    }

    /**
     * Regression (Proposal 149 T-0.8): `schedules`, `records`, `commerce` and
     * `documents` are gated by live routes and by the toolkit manifests, but
     * were absent from the catalogue. `allows()` therefore returned false for
     * every plan, which means a LICENSED install denied its own Pro surface —
     * invisible under the `allow` dev driver. `crm` joined the list when the
     * CRM toolkit manifest declared it as its entitlement hook, and the Wave 3
     * authoring pass added one key per authored toolkit so each manifest can
     * gate its own surface instead of sharing `records`.
     */
    public function testSurfacesTheRuntimeGatesOnAreEntitledByEveryPlan(): void
    {
        $gated = array(
            'schedules',
            'records',
            'commerce',
            'documents',
            'crm',
            'project-management',
            'law-firm',
            'comic-creation',
            'chat-channels',
            'shopify-sync',
            'ezuite',
            'flowhub',
            'dietpi',
            'analytics',
            'architectural-design',
            'ecommerce',
            'financial-planner',
            'media',
            'multilingual',
            'regulatory-registration',
            'social-media',
            'video-production',
        );

        foreach (ProPlanCatalog::plans() as $plan) {
            foreach ($gated as $feature) {
                $this->assertTrue(
                    ProPlanCatalog::allows($plan, $feature),
                    "The runtime gates on '{$feature}' but plan '{$plan}' does not entitle it."
                );
            }
        }
    }
}
