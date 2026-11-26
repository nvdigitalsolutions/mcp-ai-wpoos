<?php

namespace App\Support;

/**
 * The console's dashboard catalogue for this runtime.
 *
 * WordPress builds this catalogue from **seven** dashboards (158 D1 merged the
 * original ten: `ezuite` + `shopify` + `flowhub` → `connectors`, and `health` +
 * `vitals` → `members`), each gated by one or more plugin settings flags and fed
 * by its own admin-page gatherer. This runtime owns one of those stores — the
 * classroom/ECA family (090-e) — so exactly one dashboard is served and the rest
 * are reported as `disabled`, carrying the toolkit flags that would enable them.
 * That is the same shape WordPress uses for a switched-off toolkit, so the
 * console's empty state works unchanged.
 *
 * Adding a dashboard is two jobs, and the second is the real work:
 *   1. an entry below, and
 *   2. a gatherer in {@see \App\Http\Controllers\Pro\DashboardsController}
 *      against a store this runtime actually owns.
 *
 * Labels and descriptions are shared with `WP_MCP_AI_Pro_Dashboards_REST_Controller`
 * so a dashboard reads the same on both runtimes. `ALIASES` is shared for the
 * same reason: a catalogue split across two runtimes cannot disagree about what
 * an id means.
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
            'toolkit_flags' => ['enable_cre_debt_toolkit'],
        ],
        'law' => [
            'label' => 'Law Firm',
            'description' => 'Active matters, clients, time entries and trust transactions.',
            'icon' => '⚖️',
            'toolkit_flags' => ['enable_law_firm_toolkit'],
        ],
        'registration' => [
            'label' => 'Registration',
            'description' => 'Product registrations by status and country.',
            'icon' => '📋',
            'toolkit_flags' => ['enable_regulatory_registration_toolkit'],
        ],
        'eca' => [
            'label' => 'ECA',
            'description' => 'Extra-curricular activities: enrollments, capacity, participation and attendance.',
            'icon' => '🏫',
            'toolkit_flags' => ['enable_eca_management'],
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
        // 158 D1 — Member metrics. `health` and `vitals` were one dashboard in
        // two pieces: identical filters, identical structure, differing only in
        // columns and in which toolkit toggle reveals them. The toggles survive
        // as per-section visibility on the WordPress side; this runtime serves
        // neither section, so the merged row is metadata only.
        'members' => [
            'label' => 'Member metrics',
            'description' => 'Health and vital-sign history per member. Each section follows its own toolkit toggle.',
            'icon' => '🫀',
            'toolkit_flags' => ['enable_health_wellness_management', 'enable_medical_vitals'],
        ],
        // 158 D1 — Connector health. `ezuite` and `flowhub` published a
        // byte-identical render spec; `shopify` shared the framing and added its
        // GraphQL cost budget. One surface, one section per connector, keys
        // namespaced by connector.
        'connectors' => [
            'label' => 'Connector health',
            'description' => 'Inventory stock buckets, sync freshness and GraphQL budget, one section per connector.',
            'icon' => '🏭',
            'toolkit_flags' => ['enable_ezuite_toolkit', 'enable_shopify_sync_toolkit', 'enable_flowhub_toolkit'],
        ],
        'observability' => [
            'label' => 'AI Observability',
            'description' => 'Chat annotation totals and recent GenAI span counts from the audit activity log.',
            'icon' => '📡',
            'toolkit_flags' => [],
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
     * Dashboard ids retired by the 10 → 7 merge (158 D1).
     *
     * Published in the schema envelope and honoured by the data endpoint, so a
     * client carrying a saved id resolves instead of 404ing. Must match
     * `WP_MCP_AI_Pro_Dashboards_REST_Controller::aliases()` — the two runtimes
     * answer the same catalogue, and an alias present on one only is a parity
     * break on the first request.
     *
     * @var array<string, string>
     */
    private const ALIASES = [
        'health' => 'members',
        'vitals' => 'members',
        'ezuite' => 'connectors',
        'shopify' => 'connectors',
        'flowhub' => 'connectors',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return self::DASHBOARDS;
    }

    public static function isAvailable(string $id): bool
    {
        return in_array(self::resolveAlias($id), self::AVAILABLE, true);
    }

    /**
     * The dashboards this runtime can actually feed, in catalogue order.
     *
     * The panel iterates this rather than `all()`: a dashboard whose store does
     * not exist here is not "disabled", it is absent. Disabling asserts
     * "available in principle, not right now" — and there is nothing an estate
     * administrator could switch on to make `connectors` exist on this runtime.
     * That is the same line §104 drew for inapplicable settings and S-3 drew for
     * unlicensed toolkits.
     *
     * @return list<string>
     */
    public static function servable(): array
    {
        return array_values(array_filter(
            array_keys(self::DASHBOARDS),
            static fn (string $id): bool => self::isAvailable($id)
        ));
    }

    /**
     * The retired ids and what replaced them.
     *
     * @return array<string, string>
     */
    public static function aliases(): array
    {
        return self::ALIASES;
    }

    /**
     * Resolve a retired id to the surface that replaced it.
     *
     * Idempotent for a live id, and for an id that never existed — the caller
     * still performs its own unknown-id check.
     */
    public static function resolveAlias(string $id): string
    {
        return self::ALIASES[$id] ?? $id;
    }

    /**
     * Every settings flag that would enable a dashboard.
     *
     * A merged dashboard has several, which is why this is a list. Empty means
     * ungated (`observability`).
     *
     * @return list<string>
     */
    public static function flags(string $id): array
    {
        $id = self::resolveAlias($id);

        return array_values((array) (self::DASHBOARDS[$id]['toolkit_flags'] ?? []));
    }

    /**
     * The settings flag that would enable a dashboard.
     *
     * First-of-list, for the single-flag guidance the console's empty state has
     * always rendered; `flags()` answers with all of them. Empty for an ungated
     * dashboard (`observability`), matching WordPress's `effective_flag_key()`.
     */
    public static function flag(string $id): string
    {
        $flags = self::flags($id);

        return $flags[0] ?? '';
    }
}
