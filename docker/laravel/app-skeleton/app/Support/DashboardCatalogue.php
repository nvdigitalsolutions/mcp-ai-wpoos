<?php

namespace App\Support;

/**
 * The console's dashboard catalogue for this runtime.
 *
 * WordPress builds this catalogue from ten dashboards, each gated by a plugin
 * settings flag and fed by its own admin-page gatherer. This runtime owns one
 * of those stores — the classroom/ECA family (090-e) — so exactly one
 * dashboard is served and the rest are reported as `disabled`, carrying the
 * toolkit flag that would enable them. That is the same shape WordPress uses
 * for a switched-off toolkit, so the console's empty state works unchanged.
 *
 * Adding a dashboard is two jobs, and the second is the real work:
 *   1. an entry below, and
 *   2. a gatherer in {@see \App\Http\Controllers\Pro\DashboardsController}
 *      against a store this runtime actually owns.
 *
 * Labels and descriptions are shared with `WP_MCP_AI_Pro_Dashboards_REST_Controller`
 * so a dashboard reads the same on both runtimes.
 */
final class DashboardCatalogue
{
    /**
     * Dashboard id => metadata, plus the render spec for the served entries.
     *
     * `filters`, `metrics`, `charts` and `lists` are only meaningful for a
     * served dashboard; the disabled payload carries metadata alone.
     *
     * @var array<string, array<string, mixed>>
     */
    private const DASHBOARDS = [
        'cre' => [
            'label' => 'CRE Debt Portfolio',
            'description' => 'Commercial real-estate loan portfolio: balances, averages and distributions with loan-type and status filters.',
            'icon' => '🏦',
            'toolkit_flag' => 'enable_cre_debt_toolkit',
        ],
        'law' => [
            'label' => 'Law Firm',
            'description' => 'Active matters, clients, time entries and trust transactions.',
            'icon' => '⚖️',
            'toolkit_flag' => 'enable_law_firm_toolkit',
        ],
        'registration' => [
            'label' => 'Registration',
            'description' => 'Product registrations by status and country.',
            'icon' => '📋',
            'toolkit_flag' => 'enable_regulatory_registration_toolkit',
        ],
        'eca' => [
            'label' => 'ECA',
            'description' => 'Extra-curricular activities: enrollments, capacity, participation and attendance.',
            'icon' => '🏫',
            'toolkit_flag' => 'enable_eca_management',
            'filters' => [
                [
                    'key' => 'day',
                    'label' => 'Day',
                    'type' => 'select',
                    'options' => [
                        '' => 'All Days',
                        'Monday' => 'Monday',
                        'Tuesday' => 'Tuesday',
                        'Wednesday' => 'Wednesday',
                        'Thursday' => 'Thursday',
                        'Friday' => 'Friday',
                        'Saturday' => 'Saturday',
                        'Sunday' => 'Sunday',
                    ],
                ],
                [
                    'key' => 'eca_type',
                    'label' => 'Type',
                    'type' => 'select',
                    'options' => [
                        '' => 'All Types',
                        'club' => 'Club',
                        'society' => 'Society',
                        'sport_squad' => 'Sport Squad',
                        'sport_academy' => 'Sport Academy',
                        'activity' => 'Activity',
                    ],
                ],
            ],
            'metrics' => [
                [
                    'key' => 'total_activities',
                    'label' => 'Activities',
                    'format' => 'number',
                ],
                [
                    'key' => 'total_enrolments',
                    'label' => 'Confirmed',
                    'format' => 'number',
                ],
                [
                    'key' => 'total_waitlisted',
                    'label' => 'Waitlisted',
                    'format' => 'number',
                ],
                [
                    'key' => 'capacity_usage',
                    'label' => 'Capacity Used',
                    'format' => 'percent',
                ],
            ],
            'charts' => [
                [
                    'key' => 'by_day',
                    'title' => 'Enrolments by Day',
                    'type' => 'bar',
                ],
                [
                    'key' => 'by_type',
                    'title' => 'Activities by Type',
                    'type' => 'donut',
                ],
            ],
            'lists' => [
                [
                    'key' => 'activity_list',
                    'title' => 'Activities',
                    'columns' => [
                        ['key' => 'title', 'label' => 'Activity'],
                        ['key' => 'day', 'label' => 'Day'],
                        ['key' => 'type', 'label' => 'Type'],
                        ['key' => 'enrolled', 'label' => 'Enrolled', 'format' => 'number'],
                        ['key' => 'capacity', 'label' => 'Capacity', 'format' => 'number'],
                        ['key' => 'waitlisted', 'label' => 'Waitlist', 'format' => 'number'],
                        ['key' => 'status', 'label' => 'Status'],
                    ],
                ],
            ],
        ],
        'health' => [
            'label' => 'Health & Wellness',
            'description' => 'Daily health metrics history per member (steps, water, sleep, calories, mood).',
            'icon' => '🫀',
            'toolkit_flag' => 'enable_health_wellness_management',
        ],
        'vitals' => [
            'label' => 'Medical Vitals',
            'description' => 'Vital signs history per member (heart rate, temperature, blood pressure, weight).',
            'icon' => '🩺',
            'toolkit_flag' => 'enable_medical_vitals',
        ],
        'ezuite' => [
            'label' => 'EZuite Inventory',
            'description' => 'EZuite ERP inventory health: stock buckets, cached rows, total value and top warehouses.',
            'icon' => '🏭',
            'toolkit_flag' => 'enable_ezuite_toolkit',
        ],
        'shopify' => [
            'label' => 'Shopify Sync',
            'description' => 'Shopify GraphQL cost budget, cached items and sync health per connection.',
            'icon' => '🛍️',
            'toolkit_flag' => 'enable_shopify_sync_toolkit',
        ],
        'flowhub' => [
            'label' => 'FlowHub Inventory',
            'description' => 'FlowHub POS inventory health: stock buckets, cached rows, total value and top locations. Read-mostly — compliance reporting stays in FlowHub.',
            'icon' => '🌿',
            'toolkit_flag' => 'enable_flowhub_toolkit',
        ],
        'observability' => [
            'label' => 'AI Observability',
            'description' => 'Chat annotation totals and recent GenAI span counts from the audit activity log.',
            'icon' => '📡',
            'toolkit_flag' => '',
        ],
    ];

    /**
     * Ids this runtime can actually feed.
     *
     * Only the classroom/ECA family has a store here (090-e). Everything else
     * stays `disabled` until its CPT family is ported.
     *
     * @var string[]
     */
    private const AVAILABLE = ['eca'];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return self::DASHBOARDS;
    }

    public static function isAvailable(string $id): bool
    {
        return in_array($id, self::AVAILABLE, true);
    }

    /**
     * The settings flag that would enable a dashboard.
     *
     * Empty for an ungated dashboard (`observability`), matching WordPress's
     * `effective_flag_key()`.
     */
    public static function flag(string $id): string
    {
        return (string) (self::DASHBOARDS[$id]['toolkit_flag'] ?? '');
    }
}
