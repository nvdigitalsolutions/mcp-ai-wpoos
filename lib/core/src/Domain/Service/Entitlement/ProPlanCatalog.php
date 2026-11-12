<?php

/**
 * Pro plan catalogue — the plan → feature policy table.
 *
 * Single source of truth for which features each commercial plan unlocks. It
 * lives in `lib/core` so both runtimes answer the question identically: the
 * WordPress plugin (`WP_MCP_AI_Pro_License::has_feature()`) and the Laravel
 * adapter (`LicensedProEntitlement`) both read this table instead of keeping
 * their own copies, which is what let the two drift apart.
 *
 * Feature keys are commercial surface names, not tool slugs. They describe
 * what a customer bought, so an adapter can map a tool or route onto them
 * without either runtime owning the policy.
 *
 * @package Nvoos\Core
 * @since   1.3.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Service\Entitlement;

/**
 * Plan → feature policy table.
 *
 * Plans are cumulative: every `professional` feature is also an `enterprise`
 * feature, and so on. An unknown plan entitles nothing — the catalogue fails
 * closed rather than falling back to a default tier.
 *
 * @since 1.3.0
 */
final class ProPlanCatalog
{
    /**
     * Ordered plans, smallest first, each with its full feature list.
     *
     * @var array<string, list<string>>
     */
    private const PLANS = array(
        'compliance' => array(
            'dashboard',
            'controls_management',
            'reports_basic',
            'audit_trail',
            'evidence_collection',
        ),
        'professional' => array(
            'dashboard',
            'controls_management',
            'reports_basic',
            'reports_advanced',
            'audit_trail',
            'evidence_collection',
            'risk_matrix',
            'multi_framework',
            'siem_integration',
        ),
        'enterprise' => array(
            'dashboard',
            'controls_management',
            'reports_basic',
            'reports_advanced',
            'audit_trail',
            'evidence_collection',
            'risk_matrix',
            'multi_framework',
            'siem_integration',
            'white_label',
            'priority_support',
            'custom_development',
        ),
    );

    /**
     * Every known plan label, smallest first.
     *
     * @return list<string>
     */
    public static function plans(): array
    {
        return array_keys(self::PLANS);
    }

    /**
     * Whether the label names a plan in the catalogue.
     */
    public static function hasPlan(string $plan): bool
    {
        return isset(self::PLANS[$plan]);
    }

    /**
     * The features a plan entitles, or an empty list for an unknown plan.
     *
     * @return list<string>
     */
    public static function features(string $plan): array
    {
        return self::PLANS[$plan] ?? array();
    }

    /**
     * Whether the plan entitles the feature.
     *
     * Unknown plans and unknown features both return false.
     */
    public static function allows(string $plan, string $feature): bool
    {
        return in_array($feature, self::features($plan), true);
    }

    /**
     * Position of the plan in the catalogue, or -1 when unknown.
     *
     * Higher is a larger plan. Useful for "at least this tier" checks without
     * hard-coding the plan names at the call site.
     */
    public static function rank(string $plan): int
    {
        $index = array_search($plan, self::plans(), true);

        return false === $index ? -1 : (int) $index;
    }
}
