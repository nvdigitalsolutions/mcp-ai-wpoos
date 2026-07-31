# Ideal Customer Profile (ICP) System — Implementation Proposal

**Date:** July 31, 2026
**Status:** 🔮 PROPOSAL — Awaiting Review
**Estimated Effort:** 12–18 days (1–2 developers)
**Priority:** HIGH
**Feature Area:** CRM Toolkit → Lead Intelligence & Scoring

## Table of Contents

- [1. Executive Summary](#1-executive-summary)
- [2. Problem Statement](#2-problem-statement)
- [3. Research Foundation](#3-research-foundation)
- [4. Architecture & Components](#4-architecture--components)
  - [4.1 ICP Profile Data Store](#41-icp-profile-data-store)
  - [4.2 ICP Scoring Engine](#42-icp-scoring-engine)
  - [4.3 MCP Tools](#43-mcp-tools)
  - [4.4 Admin UI](#44-admin-ui)
  - [4.5 Integration Points](#45-integration-points)
- [5. Implementation Phases](#5-implementation-phases)
- [6. Affected Files](#6-affected-files)
- [7. Testing Strategy](#7-testing-strategy)
- [8. Success Metrics](#8-success-metrics)
- [9. Decision Required](#9-decision-required)

---

## 1. Executive Summary

**What:** A structured, data-driven Ideal Customer Profile (ICP) system with
7-dimension scoring, fit+intent separation, behavioral decay rules, negative
scoring, and tiered routing — all integrated into the NV CRM Toolkit.

**Why:** The current CRM Toolkit provides lead scoring via a simple weighted
composite (fit × 0.35 + intent × 0.30 + engagement × 0.20 + recency × 0.15)
and a free-text "Ideal Client Profile" textarea used exclusively as AI context
for Upwork/LinkedIn job scoring. This is insufficient for systematic ICP
management. Industry data shows companies with defined ICPs achieve **68%
higher win rates** (Forrester/SiriusDecisions), **36% higher customer
retention** (Gartner), and **34% higher average contract value** (TOPOne
Research).

**Expected Impact:**

- 🎯 **Precision Targeting** — Structured 7-dimension profiles replace
  ambiguous free-text, giving the scoring engine and AI tools a machine-readable
  definition of "ideal."
- 📊 **Dual Scoring** — Separate `fit_score` and `intent_score` outputs
  replace the single blended score, enabling differentiated routing (high-fit +
  low-intent = nurture; high-fit + high-intent = immediate sales).
- ⏱️ **Behavioral Decay** — Scores degrade 10–20% per 30 days of inactivity,
  preventing stale leads from clogging the pipeline.
- 🚫 **Negative Scoring** — Explicit disqualifiers (wrong industry, sub-minimum
  revenue, competitor tech stack) apply hard deductions.
- 🏢 **Account-Level Scoring** — Individual lead scores roll up to an
  account-level ICP score stored on the Company CPT.
- 📈 **Pipeline Analysis** — Compare current pipeline and customer base against
  ICP definitions to identify coverage gaps and misaligned accounts.

---

## 2. Problem Statement

### 2.1 The Free-Text Gap

The current `external_sourcing.ideal_client_profile` field is a single
`<textarea>` storing unstructured prose. It is injected as AI prompt context
into `score_upwork_job` and `score_linkedin_job` tools only. It cannot be:

- Queried programmatically ("show me all leads matching ICP #3")
- Used in deterministic scoring calculations
- Compared against pipeline data for gap analysis
- Evolved independently of the sourcing tools
- Shared across multiple named profiles (e.g., "Enterprise SaaS" vs.
  "Mid-Market E-commerce")

### 2.2 Scoring Model Limitations

The current `WP_MCP_AI_CRM_Engine::calculate_lead_score()` produces a single
0–100 composite. It does not:

- Distinguish **fit** (are they the right kind of company?) from **intent**
  (are they actively buying?)
- Apply **behavioral decay** — a lead who visited the pricing page 6 months
  ago scores identically to one who visited yesterday
- Support **negative scoring** — a lead from a disqualified industry can still
  score above the warm threshold if engagement signals are high
- Aggregate scores to the **account level** for company-wide ICP evaluation

### 2.3 No Tiered Routing

Leads are scored but not automatically routed or tiered based on ICP fit.
A high-fit enterprise SaaS prospect and a low-fit sole proprietor receive the
same `lifecycle_stage` progression logic.

---

## 3. Research Foundation

### 3.1 TK Kader's 3-Part ICP Framework

The system models Kader's widely-adopted ICP structure (from *Unstoppable
SaaS* and Go-To-Market strategies):

| Layer | Description | ICP Dimensions Covered |
|-------|-------------|----------------------|
| **Firmographics** | Who they are — objective, observable attributes | Industry, company size, revenue, geography, funding stage |
| **Triggers** | What changed — events that create buying urgency | Hiring surges, funding rounds, leadership changes, M&A activity, regulatory shifts |
| **Macro Trends** | What's happening in their world — external forces shaping demand | Market growth/contraction, technology adoption waves, regulatory tailwinds/headwinds |

### 3.2 The 7-Dimension Scoring Model

Each ICP profile scores prospects across seven dimensions, each with a
configurable weight:

| # | Dimension | What It Measures | Source |
|---|-----------|------------------|--------|
| 1 | Industry Fit | Does the prospect operate in a target industry? | Company CPT meta, Clearbit enrichment |
| 2 | Company Size | Employee count, revenue band alignment | Company CPT meta, enrichment |
| 3 | Geography | Target region / country / timezone match | Company CPT meta |
| 4 | Technographics | Tech stack compatibility (WordPress, WooCommerce, etc.) | BuiltWith/Wappalyzer data, enrichment |
| 5 | Funding & Maturity | Funding stage, years in business | Crunchbase, enrichment |
| 6 | Intent Signals | Active buying behavior (pricing page, demo request, trial) | Lead CPT activity, webhooks |
| 7 | Trigger Events | Recent hiring, funding, leadership change | News monitoring, enrichment |

### 3.3 Industry Statistics

- **Win Rate:** Companies with documented ICPs achieve 68% higher win rates
  (Forrester / SiriusDecisions, 2023 B2B Sales Benchmark).
- **Retention:** 36% higher customer retention when sales targets align with
  ICP (Gartner, "The B2B Buying Journey," 2024).
- **ACV:** 34% higher average contract value from ICP-aligned accounts (TOPOne
  Research, "Ideal Customer Profile Trends," 2024).
- **Pipeline Efficiency:** Organizations using ICP scoring reduce average sales
  cycle by 23% (HubSpot Sales Data, 2024).

### 3.4 Key Design Principles

| Principle | Implementation |
|-----------|---------------|
| **Fit vs. Intent Separation** | Two independent scores; routing logic uses both |
| **Behavioral Decay** | 10–20% score decay per 30 days of inactivity; configurable half-life |
| **Negative Scoring** | Disqualifier dimensions apply hard deductions (minimum -30 per dimension) |
| **Account-Level Aggregation** | Company CPT stores `icp_fit_score` computed from all associated leads |
| **Quarterly Recalibration** | Scheduled task prompts admin to review and update ICP profiles; tracked in audit log |

---

## 4. Architecture & Components

### 4.1 ICP Profile Data Store

**Class:** `WP_MCP_AI_ICP_Profile`

Stores structured ICP definitions as WordPress options under a single option
key `wp_mcp_ai_icp_profiles`. Supports multiple named profiles (e.g.,
"Enterprise SaaS," "Mid-Market E-commerce," "Agency Partner").

**Option schema:**

```php
array(
    'version'    => 1,
    'profiles'   => array(
        'profile_slug' => array(
            'name'           => 'Enterprise SaaS',
            'description'    => 'B2B SaaS companies with 100–1000 employees...',
            'is_active'      => true,
            'created_at'     => '2026-07-31T00:00:00Z',
            'updated_at'     => '2026-07-31T00:00:00Z',
            'dimensions'     => array(
                'industry' => array(
                    'enabled'    => true,
                    'weight'     => 0.20,
                    'targets'    => array( 'SaaS', 'FinTech', 'HealthTech' ),
                    'excludes'   => array( 'Gambling', 'Adult' ),
                    'min_score'  => 60,
                ),
                'company_size' => array(
                    'enabled'    => true,
                    'weight'     => 0.15,
                    'min_employees' => 50,
                    'max_employees' => 1000,
                    'min_revenue'   => 5000000,   // $5M USD
                    'max_revenue'   => 100000000,  // $100M USD
                ),
                'geography' => array(
                    'enabled'    => true,
                    'weight'     => 0.10,
                    'targets'    => array( 'US', 'CA', 'UK', 'AU' ),
                    'excludes'   => array(),
                ),
                'technographics' => array(
                    'enabled'    => true,
                    'weight'     => 0.15,
                    'required'   => array( 'WordPress' ),
                    'preferred'  => array( 'WooCommerce', 'React' ),
                    'excludes'   => array( 'Squarespace', 'Wix' ),
                ),
                'funding_maturity' => array(
                    'enabled'    => true,
                    'weight'     => 0.10,
                    'stages'     => array( 'Series A', 'Series B', 'Series C' ),
                ),
                'intent_signals' => array(
                    'enabled'     => true,
                    'weight'      => 0.20,
                    'signals'     => array(
                        'pricing_page_visit' => 15,
                        'demo_request'       => 25,
                        'trial_signup'       => 20,
                        'contact_form'       => 10,
                    ),
                    'decay_days'  => 30,
                    'decay_rate'  => 0.15,      // 15% decay per decay_days
                ),
                'trigger_events' => array(
                    'enabled'    => true,
                    'weight'     => 0.10,
                    'events'     => array(
                        'funding_round'       => 20,
                        'leadership_change'   => 15,
                        'hiring_surge'        => 10,
                        'new_product_launch'  => 15,
                    ),
                    'lookback_days' => 90,
                ),
            ),
            'negative_signals' => array(
                'competitor_stack' => -30,
                'wrong_industry'   => -50,
                'below_min_revenue' => -40,
                'do_not_contact'    => -100,
            ),
            'routing' => array(
                'tiers' => array(
                    array( 'min_fit' => 75, 'min_intent' => 60, 'action' => 'assign_sales',   'label' => 'Hot — Immediate Outreach' ),
                    array( 'min_fit' => 75, 'min_intent' => 30, 'action' => 'nurture_marketing', 'label' => 'Warm — Nurture Campaign' ),
                    array( 'min_fit' => 50, 'min_intent' => 60, 'action' => 'review_manual',  'label' => 'Review — Possible Misfit' ),
                    array( 'min_fit' => 0,  'min_intent' => 0,  'action' => 'disqualify',     'label' => 'Cold — Disqualified' ),
                ),
            ),
        ),
    ),
)
```

**CRUD methods on `WP_MCP_AI_ICP_Profile`:**

| Method | Description |
|--------|-------------|
| `get_all()` | Return all profiles indexed by slug |
| `get( $slug )` | Return single profile or null |
| `save( $slug, array $profile )` | Create or update a profile; returns true or `WP_Error` |
| `delete( $slug )` | Remove a profile |
| `get_active()` | Return only profiles where `is_active === true` |
| `validate( array $profile )` | Validate profile structure; returns `true` or `WP_Error` with field-level errors |

### 4.2 ICP Scoring Engine

**Class:** `WP_MCP_AI_ICP_Scorer`

Computes 0–100 scores using the 7-dimension model. Called directly by MCP
tools and by the CRM Engine's lead-scoring pipeline. Stateless — all
configuration comes from `WP_MCP_AI_ICP_Profile`.

**Public API:**

```php
class WP_MCP_AI_ICP_Scorer {

    /**
     * Score a single company/lead against one ICP profile.
     *
     * @param string $profile_slug ICP profile slug.
     * @param array  $company_data Flat map of company attributes.
     * @param array  $lead_data    Optional lead-level intent data.
     * @param int    $days_since_last_activity Days since last engagement touchpoint.
     * @return array{fit_score: int, intent_score: int, composite: int, breakdown: array, tier: string}|WP_Error
     */
    public static function score( $profile_slug, array $company_data, array $lead_data = array(), $days_since_last_activity = 0 );

    /**
     * Score a company against all active ICP profiles.
     *
     * @param array $company_data Flat map of company attributes.
     * @param array $lead_data    Optional lead-level intent data.
     * @param int   $days_since_last_activity
     * @return array[] Indexed by profile_slug, each entry as score() return shape.
     */
    public static function score_all( array $company_data, array $lead_data = array(), $days_since_last_activity = 0 );

    /**
     * Analyze a set of companies against an ICP profile.
     * Returns aggregate statistics: distribution, coverage gaps, average scores.
     *
     * @param string $profile_slug
     * @param array[] $companies Array of company data maps.
     * @return array{totals: array, distribution: array, gaps: array}|WP_Error
     */
    public static function analyze( $profile_slug, array $companies );

    /**
     * Apply behavioral decay to an intent score.
     *
     * @param int   $score          Current score (0–100).
     * @param int   $days_inactive  Days since last activity.
     * @param int   $decay_days     Decay period (default 30).
     * @param float $decay_rate     Decay fraction per period (default 0.15).
     * @return int Decayed score (0–100).
     */
    public static function apply_decay( $score, $days_inactive, $decay_days = 30, $decay_rate = 0.15 );
}
```

**Scoring algorithm:**

1. For each enabled dimension, compute a raw 0–100 sub-score by comparing
   `$company_data` against the dimension's `targets`/`excludes`/thresholds.
2. Apply the dimension's `weight` to produce a weighted sub-score.
3. Sum weighted sub-scores to produce `fit_score` (from firmographic
   dimensions: industry, size, geography, technographics, funding) and
   `intent_score` (from behavioral dimensions: intent signals, trigger events).
4. Apply behavioral decay to `intent_score` using `apply_decay()`.
5. Apply negative signal deductions — each matching disqualifier subtracts its
   penalty value from `fit_score`.
6. Clamp both scores to 0–100.
7. Identify the matching routing tier from `profile.routing.tiers` by comparing
   against `fit_score` and `intent_score`.

**Account-level scoring:**

When a lead is scored through `WP_MCP_AI_ICP_Scorer::score()`, the system also
updates the associated Company CPT's `icp_fit_score` post meta by averaging all
associated lead fit scores against each active ICP profile. This enables
account-level ICP reporting and filtering in the Command Center.

### 4.3 MCP Tools

Three new MCP tools registered in the CRM toolkit.

#### 4.3.1 `compute_icp_score`

**File:** `addons/pro/includes/tools/crm/leads/class-wp-mcp-ai-tool-compute-icp-score.php`

**Purpose:** Calculate ICP score for a company or lead with detailed
dimension-by-dimension breakdown.

**Arguments:**

| Argument | Type | Required | Description |
|----------|------|----------|-------------|
| `company_id` | int | * | Post ID of the Company CPT to score |
| `lead_id` | int | * | Post ID of the Lead CPT to score |
| `profile_slug` | string | No | Specific ICP profile to use (default: all active) |
| `include_breakdown` | bool | No | Include per-dimension breakdown in response (default: true) |

> \* Exactly one of `company_id` or `lead_id` must be provided.

**Response envelope (success):**

```json
{
    "success": true,
    "data": {
        "entity_type": "company",
        "entity_id": 42,
        "entity_name": "Acme SaaS Inc.",
        "scores": {
            "enterprise-saas": {
                "fit_score": 82,
                "intent_score": 65,
                "composite": 74,
                "tier": "Hot — Immediate Outreach",
                "breakdown": {
                    "industry": { "score": 90, "weight": 0.20, "weighted": 18.0 },
                    "company_size": { "score": 85, "weight": 0.15, "weighted": 12.75 },
                    "geography": { "score": 70, "weight": 0.10, "weighted": 7.0 },
                    "technographics": { "score": 95, "weight": 0.15, "weighted": 14.25 },
                    "funding_maturity": { "score": 60, "weight": 0.10, "weighted": 6.0 },
                    "intent_signals": { "score": 65, "weight": 0.20, "weighted": 13.0, "decay_applied": 0 },
                    "trigger_events": { "score": 40, "weight": 0.10, "weighted": 4.0 }
                },
                "negative_deductions": [],
                "decay_info": null
            }
        },
        "top_match": "enterprise-saas",
        "scored_at": "2026-07-31T12:00:00Z"
    }
}
```

**Required capability:** `edit_posts`

#### 4.3.2 `manage_icp_profile`

**File:** `addons/pro/includes/tools/crm/class-wp-mcp-ai-tool-manage-icp-profile.php`

**Purpose:** CRUD operations on ICP profiles. Allows AI assistants to help
users define, refine, and evolve their ICP definitions conversationally.

**Arguments:**

| Argument | Type | Required | Description |
|----------|------|----------|-------------|
| `action` | string | Yes | `list`, `get`, `create`, `update`, `delete`, `activate`, `deactivate` |
| `profile_slug` | string | * | Profile slug (required for all actions except `list`) |
| `profile_data` | object | * | Full profile definition (required for `create`, optional for `update`) |

**Required capability:** `manage_options`

#### 4.3.3 `analyze_icp_fit`

**File:** `addons/pro/includes/tools/crm/analytics/class-wp-mcp-ai-tool-analyze-icp-fit.php`

**Purpose:** Analyze the current pipeline or customer base against an ICP
profile. Returns aggregate statistics, distribution histograms, coverage gaps,
and misalignment warnings.

**Arguments:**

| Argument | Type | Required | Description |
|----------|------|----------|-------------|
| `profile_slug` | string | Yes | ICP profile to analyze against |
| `dataset` | string | No | `pipeline`, `customers`, or `all` (default: `all`) |
| `lifecycle_stage` | string | No | Filter to a specific lifecycle stage |

**Required capability:** `edit_posts`

### 4.4 Admin UI

#### 4.4.1 ICP Profiles Admin Page

**Class:** `WP_MCP_AI_ICP_Admin_Page`

**File:** `addons/pro/includes/admin/class-wp-mcp-ai-icp-admin-page.php`

Registered as a submenu under `WP_MCP_AI_CRM_Admin_Menu::PARENT_SLUG`
(`nvoos-crm-dashboard`).

**URL:** `admin.php?page=wp-mcp-ai-icp-profiles`

**Features:**

- **Profile list table** — Name, dimensions enabled, active status, last
  modified, quick actions (edit, duplicate, deactivate, delete).
- **Profile editor** — Tabbed interface with one tab per dimension. Each tab
  renders dimension-specific controls (multi-select for industries, range
  sliders for revenue/employee counts, tag input for tech stack).
- **Weight visualization** — Horizontal stacked bar showing relative weight of
  each enabled dimension; drag handles to adjust weights (JavaScript).
- **Preview scorer** — Enter sample company attributes and see live scoring
  output without saving.
- **Routing matrix** — Editable tier table with min fit/intent thresholds,
  action labels, and drag-to-reorder priority.

#### 4.4.2 ICP Score on Company/Lead Edit Screens

A meta box added to the Company and Lead CPT edit screens showing:

- ICP fit score against the best-matching active profile
- Dimension heatmap (color-coded grid showing per-dimension score)
- "Recalculate" button (triggers `compute_icp_score` via AJAX)

#### 4.4.3 Pipeline ICP Fit Dashboard

A tab added to the CRM Command Center (`render_icp_tab()`) displaying:

- **Distribution chart** — Histogram of ICP fit scores across the pipeline
- **Coverage gap table** — Target industries/segments with zero or low
  representation in the pipeline
- **Misalignment warnings** — High-value deals with below-threshold ICP scores
- **Trend sparklines** — Average ICP fit over time (30/60/90 day)

### 4.5 Integration Points

#### 4.5.1 CRM Engine Integration

Extend `WP_MCP_AI_CRM_Engine::calculate_lead_score()`:

- Add a `$context` parameter accepting `company_id` and `lead_id`.
- When ICP profiles are active, call `WP_MCP_AI_ICP_Scorer::score_all()` and
  blend the top ICP match's `fit_score` into the existing `fit` factor (or
  replace it entirely if the admin opts in).
- Store `icp_fit_score`, `icp_intent_score`, and `icp_profile_match` as Lead
  CPT post meta.

New action hooks:

```php
do_action( 'wp_mcp_ai_icp_score_calculated', $lead_id, $scores, $matched_profile );
do_action( 'wp_mcp_ai_icp_tier_assigned', $lead_id, $tier, $previous_tier );
```

#### 4.5.2 Company CPT Meta Fields

New post meta registered on `mcp_ai_company`:

| Meta Key | Type | Description |
|----------|------|-------------|
| `icp_fit_score` | int (0–100) | Aggregated ICP fit score from associated leads |
| `icp_matched_profile` | string | Slug of best-matching active ICP profile |
| `icp_last_scored` | datetime | ISO 8601 timestamp of last scoring run |
| `company_industry` | string | (existing — reused) |
| `company_size` | string | (existing — reused) |
| `company_revenue` | string | (existing — reused) |
| `company_tech_stack` | string | (new) Comma-separated tech stack identifiers |

#### 4.5.3 Lead CPT Meta Fields

New post meta registered on `mcp_ai_lead`:

| Meta Key | Type | Description |
|----------|------|-------------|
| `icp_fit_score` | int (0–100) | ICP fit score |
| `icp_intent_score` | int (0–100) | ICP intent score |
| `icp_composite_score` | int (0–100) | Weighted composite |
| `icp_profile_match` | string | Slug of best-matching ICP profile |
| `icp_tier` | string | Routing tier label |
| `icp_last_activity` | datetime | ISO 8601 timestamp of last engagement |
| `icp_scored_at` | datetime | ISO 8601 timestamp of last scoring |

#### 4.5.4 Settings Page Extension

Modify the existing "Shared: Ideal Client Profile & Search Filters" section in
`class-wp-mcp-ai-crm-settings-page.php`:

- **Replace** the free-text `<textarea>` with a profile selector dropdown
  listing all active ICP profiles.
- Add a link: "Manage ICP Profiles" → opens the ICP admin page.
- Retain the free-text field as a "Profile Override Notes" field (optional
  per-profile annotation the AI can use alongside structured data).

#### 4.5.5 Existing Tool Updates

- `score_upwork_job` — Read structured ICP profile dimensions (instead of
  free-text) when building the AI scoring prompt.
- `score_linkedin_job` — Same update.
- `research_company` — Auto-enrich tech stack data when available; store in
  `company_tech_stack` meta.
- `search_upwork_jobs` — Optional `profile_slug` argument to filter search
  keywords from the ICP's technology and industry targets.

---

## 5. Implementation Phases

### Phase 1 — Core Data Model + Scoring Engine + Tools (Days 1–7)

| Task | File(s) | Days |
|------|---------|------|
| Create `WP_MCP_AI_ICP_Profile` class with CRUD + validation | `addons/pro/includes/tools/crm/class-wp-mcp-ai-icp-profile.php` | 2 |
| Create `WP_MCP_AI_ICP_Scorer` class with `score()`, `score_all()`, `analyze()`, `apply_decay()` | `addons/pro/includes/tools/crm/class-wp-mcp-ai-icp-scorer.php` | 3 |
| Implement `compute_icp_score` tool | `addons/pro/includes/tools/crm/leads/class-wp-mcp-ai-tool-compute-icp-score.php` | 1 |
| Implement `manage_icp_profile` tool | `addons/pro/includes/tools/crm/class-wp-mcp-ai-tool-manage-icp-profile.php` | 1 |
| Register all three tools in CRM toolkit bootstrapper | `addons/pro/includes/tools/crm/init.php` | — |
| Unit tests for scoring engine edge cases (decay, negative, zero data) | `tests/icp/test-icp-scorer.php` | Included |

**Phase 1 delivers:** Working scoring engine callable via MCP tools. No admin
UI yet — profiles managed via the `manage_icp_profile` tool.

### Phase 2 — Admin UI + CRM Integration (Days 8–13)

| Task | File(s) | Days |
|------|---------|------|
| Create ICP Profiles admin page (list + editor) | `addons/pro/includes/admin/class-wp-mcp-ai-icp-admin-page.php` | 2 |
| Enqueue admin CSS/JS for profile editor | `assets/js/icp-admin.js`, `assets/css/icp-admin.css` | 1 |
| Add ICP meta box to Company and Lead edit screens | `addons/pro/includes/class-wp-mcp-ai-company-cpt.php`, `addons/pro/includes/class-wp-mcp-ai-lead-cpt.php` | 1 |
| Extend `WP_MCP_AI_CRM_Engine::calculate_lead_score()` to blend ICP scores | `addons/pro/includes/tools/crm/class-wp-mcp-ai-crm-engine.php` | 1 |
| Register new Company/Lead CPT post meta fields | Both CPT classes | 0.5 |
| Update CRM Settings page (replace free-text with profile selector) | `addons/pro/includes/admin/class-wp-mcp-ai-crm-settings-page.php` | 0.5 |

**Phase 2 delivers:** Full admin UI for creating and managing ICP profiles.
CRM Engine scores leads against ICPs automatically. Settings page migration.

### Phase 3 — Pipeline Analysis + Dashboards (Days 14–18)

| Task | File(s) | Days |
|------|---------|------|
| Implement `analyze_icp_fit` tool | `addons/pro/includes/tools/crm/analytics/class-wp-mcp-ai-tool-analyze-icp-fit.php` | 1 |
| Add ICP dashboard tab to Command Center | `addons/pro/includes/admin/class-wp-mcp-ai-crm-command-center-page.php` | 2 |
| Scheduled task for quarterly ICP recalibration reminder | `addons/pro/includes/tools/crm/class-wp-mcp-ai-crm-engine.php` (action hook) | 1 |
| Integration tests for end-to-end scoring pipeline | `tests/icp/test-icp-integration.php` | 1 |
| Documentation: tool reference, admin guide, developer hooks | `docs/tool-reference.md`, `docs/icp-system.md` | — |

**Phase 3 delivers:** Complete system with pipeline analytics, dashboards,
and proactive recalibration prompts.

---

## 6. Affected Files

### New Files (to create)

| File | Description |
|------|-------------|
| `addons/pro/includes/tools/crm/class-wp-mcp-ai-icp-profile.php` | ICP profile CRUD + validation class |
| `addons/pro/includes/tools/crm/class-wp-mcp-ai-icp-scorer.php` | 7-dimension scoring engine |
| `addons/pro/includes/tools/crm/leads/class-wp-mcp-ai-tool-compute-icp-score.php` | `compute_icp_score` MCP tool |
| `addons/pro/includes/tools/crm/class-wp-mcp-ai-tool-manage-icp-profile.php` | `manage_icp_profile` MCP tool |
| `addons/pro/includes/tools/crm/analytics/class-wp-mcp-ai-tool-analyze-icp-fit.php` | `analyze_icp_fit` MCP tool |
| `addons/pro/includes/admin/class-wp-mcp-ai-icp-admin-page.php` | ICP admin page (list + editor) |
| `assets/js/icp-admin.js` | ICP admin page JavaScript |
| `assets/css/icp-admin.css` | ICP admin page styles |
| `tests/icp/test-icp-scorer.php` | Unit tests for scoring engine |
| `tests/icp/test-icp-profile.php` | Unit tests for profile CRUD |
| `tests/icp/test-icp-integration.php` | Integration tests |
| `docs/icp-system.md` | User-facing documentation |

### Modified Files

| File | Change |
|------|--------|
| `addons/pro/includes/tools/crm/init.php` | Register 3 new ICP tools |
| `addons/pro/includes/tools/crm/class-wp-mcp-ai-crm-engine.php` | Extend `calculate_lead_score()` to blend ICP; add action hooks |
| `addons/pro/includes/admin/class-wp-mcp-ai-crm-settings-page.php` | Replace free-text ICP with profile selector |
| `addons/pro/includes/class-wp-mcp-ai-company-cpt.php` | Register new post meta; add ICP meta box |
| `addons/pro/includes/class-wp-mcp-ai-lead-cpt.php` | Register new post meta; add ICP meta box |
| `addons/pro/includes/admin/class-wp-mcp-ai-crm-command-center-page.php` | Add ICP dashboard tab |
| `addons/pro/includes/tools/crm/upwork/class-wp-mcp-ai-tool-score-upwork-job.php` | Read structured ICP for AI prompt |
| `addons/pro/includes/tools/crm/linkedin/class-wp-mcp-ai-tool-score-linkedin-job.php` | Read structured ICP for AI prompt |
| `addons/pro/includes/tools/crm/upwork/class-wp-mcp-ai-tool-search-upwork-jobs.php` | Optional `profile_slug` arg |
| `addons/pro/includes/tools/crm/class-wp-mcp-ai-tool-research-company.php` | Enrich tech stack; store in meta |
| `mcp-ai-wpoos.php` | Load new ICP classes on plugin bootstrap |

---

## 7. Testing Strategy

### Unit Tests

| Test Class | What It Covers |
|------------|---------------|
| `Test_ICP_Profile` | CRUD operations, validation edge cases, active/inactive filtering, malformed input, option serialization |
| `Test_ICP_Scorer` | 7-dimension scoring math, weight application, behavioral decay formula, negative deductions, score clamping, dimension disablement, missing company data, `score_all()` multi-profile output |
| `Test_ICP_Tool_Compute_Score` | Tool argument parsing, `company_id` vs `lead_id` mutual exclusivity, capability check, canonical return envelope, breakdown format |
| `Test_ICP_Tool_Manage_Profile` | Tool argument parsing, CRUD actions via tool, capability check (requires `manage_options`), validation errors returned as `WP_Error` |
| `Test_ICP_Tool_Analyze_Fit` | Tool argument parsing, aggregate statistics correctness, gap detection logic |

### Integration Tests

| Test | What It Covers |
|------|---------------|
| `test_full_scoring_pipeline` | Create ICP profile → create Company CPT → create Lead CPT → score lead → verify meta stored → verify account-level aggregation |
| `test_decay_over_time` | Score lead → advance time 60 days → re-score → verify intent_score decayed |
| `test_routing_tier_assignment` | Score leads at various fit/intent levels → verify correct tier assignments |
| `test_crm_engine_blend` | Configure active ICP profile → call `calculate_lead_score()` with ICP blending enabled → verify blended score differs from non-ICP score |
| `test_settings_migration` | Simulate existing free-text ICP → update to profile-based → verify stored option |

### WordPress Standards

- `composer run lint` passes with severity 1 on all new and modified files
- All tool `execute()` methods return the canonical envelope (success array or `WP_Error`)
- All `$arguments` values sanitized at entry (Gate 1); all response values escaped (Gate 2)
- `permission_callback` set on any REST endpoints (if added later)
- Nonces used for state-changing admin AJAX calls

---

## 8. Success Metrics

- **Scoring accuracy:** Top-quartile ICP-fit leads convert at ≥2× the rate of
  bottom-quartile leads (measured after 90 days of data).
- **Pipeline efficiency:** Average sales cycle length decreases ≥15% for
  ICP-aligned deals vs. non-aligned.
- **Admin adoption:** ≥1 active ICP profile configured within 30 days of
  feature release for new CRM toolkit users.
- **Tool usage:** `compute_icp_score` invoked ≥average of the top 10 CRM tools
  within 60 days.
- **Code quality:** 100% of new code covered by unit tests for the scoring
  engine; all PHPCS checks pass.

---

## 9. Decision Required

- **Approve** the full 3-phase ICP system implementation as described above.
- **Phase-gate approach:** Approve Phase 1 now (core data model + scoring
  engine + tools); review Phase 1 before committing to Phase 2.
- **Defer:** Consider deferring the pipeline analysis dashboard (Phase 3) until
  after sufficient ICP scoring data has accumulated (≥60 days in production).

---

*Proposal prepared for the NV oOS Pro CRM Toolkit. Questions or feedback:
open an issue or discuss in the CRM Toolkit channel.*
