# CRM Freelance Sourcing Enhancement — Implementation Plan

**Date:** July 12, 2026
**Proposal:** [CRM-FREELANCE-SOURCING-ENHANCEMENT.md](../proposals/CRM-FREELANCE-SOURCING-ENHANCEMENT.md)
**Estimated Effort:** 3–5 days (1 developer)
**Status:** ⏳ IN PROGRESS

---

## Phase Overview

| Phase | Description | Files | Est. Hours |
|-------|-------------|-------|------------|
| 1 | Engine defaults + sanitization | `class-wp-mcp-ai-crm-engine.php` | 1 |
| 2 | Admin UI rendering | `class-wp-mcp-ai-crm-settings-page.php` | 3 |
| 3 | Upwork search tool wiring | `class-wp-mcp-ai-tool-search-upwork-jobs.php` | 2 |
| 4 | LinkedIn search tool wiring | `class-wp-mcp-ai-tool-search-linkedin-jobs.php` | 1.5 |
| 5 | Scoring tool wiring (optional) | Score tool files | 1 |
| 6 | Command Center integration | `class-wp-mcp-ai-crm-command-center-page.php` | 1 |
| 7 | Validation: lint + manual test | `composer run lint` | 0.5 |

---

## Phase 1: Engine Defaults

**File:** `addons/pro/includes/tools/crm/class-wp-mcp-ai-crm-engine.php`

### 1.1 Extend `external_sourcing` defaults in `get_toolkit_settings()`

Add the new keys to the `external_sourcing` array (inside the `$defaults`
array, replacing the existing `external_sourcing` block).

#### Upwork block — add after `use_profile_context`:

```php
'default_search_keywords'    => '',
'default_location'           => '',
'default_job_type'           => '',   // 'hourly', 'fixed', or ''
'default_experience_level'   => '',   // 'entry', 'intermediate', 'expert', or ''
'default_categories'         => '',   // comma-separated
'search_interval_minutes'    => 0,    // 0 = manual only
'max_results_per_search'     => 20,
'excluded_keywords'          => '',
```

#### LinkedIn block — add after `default_location`:

```php
'default_job_type'           => '',   // 'full_time', 'part_time', 'contract', 'temporary', 'volunteer', 'internship', or ''
'default_experience_level'   => '',   // 'entry', 'mid_level', 'senior', 'executive', or ''
'default_remote'             => false,
'search_interval_minutes'    => 0,
'max_results_per_search'     => 20,
'excluded_keywords'          => '',
```

#### Shared block — add after `excluded_keywords`:

```php
'result_format'             => array(
    'description_length'    => 200,   // words (0 = full)
    'include_email'         => false, // email detail toggle
    'include_client_info'   => true,
    'include_budget'        => true,
    'include_skills'        => true,
    'include_applicants'    => true,
    'compact_mode'          => false,
),
'notification'              => array(
    'enabled'               => false,
    'email'                 => '',    // notification recipient
    'min_score_alert'       => 80,    // only alert ≥ this
    'max_alerts_per_cycle'  => 5,
),
'deduplication'             => array(
    'enabled'               => true,
    'strategy'              => 'title_url', // 'title_url', 'url_only', 'none'
    'lookback_days'         => 90,
),
```

---

## Phase 2: Admin UI Rendering

**File:** `addons/pro/includes/admin/class-wp-mcp-ai-crm-settings-page.php`

### 2.1 Upwork section — add after "Use My Profile for AI Grounding"

Render the following form rows inside the existing `<table class="form-table">`:

1. **Default Search Keywords** — `<input type="text">` (regular-text)
2. **Default Location** — `<input type="text">` (regular-text)
3. **Default Job Type** — `<select>` with: Any, Hourly, Fixed-Price
4. **Default Experience Level** — `<select>` with: Any, Entry, Intermediate, Expert
5. **Default Categories** — `<input type="text">` (regular-text, comma-separated)
6. **Upwork Excluded Keywords** — `<textarea rows="3">` (per-platform override)
7. **Max Results Per Search** — `<input type="number" min="1" max="50">` (small-text)

### 2.2 LinkedIn section — add after "Min Score to Auto-Import"

1. **Default Job Type** — `<select>` with: Any, Full-Time, Part-Time, Contract, Temporary, Internship
2. **Default Experience Level** — `<select>` with: Any, Entry, Mid-Level, Senior, Executive
3. **Default Remote** — `<input type="checkbox">` "Filter to remote-only positions"
4. **LinkedIn Excluded Keywords** — `<textarea rows="3">` (per-platform override)
5. **Max Results Per Search** — `<input type="number" min="1" max="50">` (small-text)

### 2.3 Shared section — add after "Excluded Keywords"

New subsection: **"Result Format & Notifications"**

**Result Format:**
1. **Description Length (words)** — `<input type="number" min="0" max="2000">` (0 = full)
2. **Include Email in Results** — `<input type="checkbox">` (the email detail toggle)
3. **Include Client Info** — `<input type="checkbox">`
4. **Include Budget** — `<input type="checkbox">`
5. **Include Skills** — `<input type="checkbox">`
6. **Include Applicant Count** — `<input type="checkbox">`
7. **Compact Mode** — `<input type="checkbox">` "Strip non-essential fields to save tokens"

**Notifications:**
8. **Enable Email Notifications** — `<input type="checkbox">`
9. **Notification Email** — `<input type="email">` (only shown when enabled)
10. **Min Score to Alert** — `<input type="number" min="0" max="100">`
11. **Max Alerts Per Cycle** — `<input type="number" min="1" max="50">`

**Deduplication:**
12. **Enable Deduplication** — `<input type="checkbox">`
13. **Dedup Strategy** — `<select>` with: Title + URL, URL Only, None
14. **Lookback Days** — `<input type="number" min="1" max="365">`

### 2.4 Sanitization — extend `sanitize_settings()`

Add sanitization cases for all new fields following the existing pattern
(e.g., `sanitize_text_field` for text, `absint` for numbers, `min`/`max`
clamping, `in_array` for enums).

---

## Phase 3: Upwork Search Tool Wiring

**File:** `addons/pro/includes/tools/crm/upwork/class-wp-mcp-ai-tool-search-upwork-jobs.php`

### 3.1 Read defaults from CRM settings

In the `execute()` method, after the capability check and before the
API/fallback decision, resolve missing search arguments from the CRM
toolkit settings:

```php
// Resolve defaults from CRM toolkit settings.
if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
    $crm_settings = WP_MCP_AI_CRM_Engine::get_toolkit_settings();
    $upwork_cfg   = isset( $crm_settings['external_sourcing']['upwork'] )
        ? $crm_settings['external_sourcing']['upwork'] : array();

    if ( empty( $arguments['query'] ) && ! empty( $upwork_cfg['default_search_keywords'] ) ) {
        $arguments['query'] = $upwork_cfg['default_search_keywords'];
    }
    // Similar for: job_type, experience_level, category2, budget_min, budget_max
}
```

### 3.2 Apply result format

After building the `$jobs` array, apply the `result_format` settings to
filter the output:

```php
$fmt = isset( $crm_settings['external_sourcing']['result_format'] )
    ? $crm_settings['external_sourcing']['result_format'] : array();

foreach ( $jobs as &$job ) {
    // Description length.
    if ( isset( $fmt['description_length'] ) ) {
        $len = (int) $fmt['description_length'];
        if ( $len === 0 ) {
            // Keep full description — already present.
        } else {
            $job['description'] = wp_trim_words( $job['description'], $len );
        }
    }

    // Conditional field removal.
    if ( empty( $fmt['include_email'] ) ) {
        unset( $job['email'] );
    }
    if ( empty( $fmt['include_client_info'] ) ) {
        unset( $job['client'] );
    }
    if ( empty( $fmt['include_budget'] ) ) {
        unset( $job['budget'], $job['hourly_budget'] );
    }
    if ( empty( $fmt['include_skills'] ) ) {
        unset( $job['skills'] );
    }
    if ( empty( $fmt['include_applicants'] ) ) {
        unset( $job['applicants'] );
    }

    // Compact mode: strip null/empty fields.
    if ( ! empty( $fmt['compact_mode'] ) ) {
        $job = array_filter( $job, function ( $v ) {
            return $v !== null && $v !== '' && $v !== array();
        } );
    }
}
unset( $job );
```

### 3.3 Add email to the API result mapping (when include_email is on)

In the Upwork GraphQL query, the email is typically not available in
public job postings. For API mode, add a note. For fallback/web-search
mode, the email may appear in the snippet — include it if present.

---

## Phase 4: LinkedIn Search Tool Wiring

**File:** `addons/pro/includes/tools/crm/linkedin/class-wp-mcp-ai-tool-search-linkedin-jobs.php`

### 4.1 Read defaults from CRM settings

Same pattern as Upwork — resolve `default_search_keywords`, `default_location`,
`default_job_type`, `default_experience_level`, `default_remote`,
`excluded_keywords` from `$crm_settings['external_sourcing']['linkedin']`.

### 4.2 Apply result format

Same pattern as Upwork — apply `result_format` to the `$jobs` array before
returning.

---

## Phase 5: Scoring Tool Wiring (Optional / Follow-Up)

**Files:**
- `addons/pro/includes/tools/crm/upwork/class-wp-mcp-ai-tool-score-upwork-job.php`
- `addons/pro/includes/tools/crm/linkedin/class-wp-mcp-ai-tool-score-linkedin-job.php`

Read the per-platform `excluded_keywords`, `ideal_client_profile`, and
scoring defaults from CRM settings when explicit arguments are omitted.

---

## Phase 6: Command Center Integration

**File:** `addons/pro/includes/admin/class-wp-mcp-ai-crm-command-center-page.php`

### 6.1 Read new settings in `ajax_refresh_all_sources`

The Upwork and LinkedIn auto-import pipeline sections already read
`external_sourcing.upwork` and `external_sourcing.linkedin`. Extend them
to also read the new `default_search_keywords`, `excluded_keywords`,
`result_format`, and `deduplication` settings.

---

## Phase 7: Validation

```bash
# Run PHPCS on changed files only
composer run lint

# Run full test suite (if available)
composer run test
```

**Acceptance criteria:**
- Zero PHPCS errors at severity 1 on the changed files.
- All 22 new fields render in the admin UI and save/persist correctly.
- Search tools use defaults when arguments are omitted.
- Result format controls work: email toggle, description length, compact mode.
- Existing tests pass.

---

## Rollback Plan

All new settings are additive with safe defaults. Reverting is a matter of
rolling back the four source files. No database migration is required —
existing stored settings without the new keys will be filled with defaults
by `array_replace_recursive`.

---

## Dependencies

- Composer + PHPCS (already installed)
- WordPress 6.0+ (no new WP functions used)
- No new Composer packages required
