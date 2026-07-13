# CRM Freelance Platforms & External Sourcing Enhancement

**Date:** July 12, 2026
**Status:** 🔮 PROPOSAL — Awaiting Implementation
**Estimated Effort:** 3–5 days (1 developer)
**Priority:** HIGH
**Feature Area:** CRM Toolkit → External Sourcing (Upwork & LinkedIn)

## Executive Summary

The "Freelance Platforms & External Sourcing" section in the CRM Toolkit
(`admin.php?page=wp-mcp-ai-crm-toolkit-settings&tab=configuration`) currently
provides a minimal set of configuration controls for Upwork and LinkedIn job
search, scoring, and auto-import. Users cannot configure what is searched
(search defaults per platform), how results are returned (field-level detail
control, description verbosity, email visibility), or receive proactive
notifications about high-score matches.

This proposal adds 22 new configuration fields across three categories —
Search Defaults, Result Format, and Notification Rules — giving users
fine-grained control over the sourcing pipeline. The changes are
backward-compatible, layered on top of the existing `external_sourcing`
settings structure, and wire into the existing search and scoring tool
classes without breaking their API.

**Expected Impact:**
- 🔍 **Better Search Precision** — Users define per-platform keywords, location,
  job type, experience level, and category defaults that tools apply
  automatically.
- 📋 **Controlled Result Detail** — Toggle email visibility, description
  length, client info, budget, skills, and applicant count. A compact mode
  reduces token consumption for AI assistants.
- 🔔 **Proactive Alerts** — Optional email notification when the auto-import
  pipeline discovers jobs scoring above a configurable threshold.
- 🧹 **Deduplication** — Configurable dedup strategy prevents re-importing
  identical jobs.
- 📐 **Parity** — Upwork receives the same search-default controls that
  LinkedIn already has (keywords, location), closing the asymmetry gap.

---

## Problem Statement

### 1. Asymmetry Between Platforms

LinkedIn has `default_search_keywords` and `default_location` in the settings
UI and engine defaults. Upwork has neither, despite supporting all of those
filters (and more) through its GraphQL API. Users must manually pass every
search parameter to the `search_upwork_jobs` tool.

### 2. No Result Format Control

- Description length is hardcoded to 60 words (`wp_trim_words(…, 60)`).
- Email addresses are never returned in search results, even when users
  explicitly request them ("email detail").
- The full fixed schema is always returned; users cannot opt into compact
  mode to save AI tokens.

### 3. Missing Search Defaults Per Platform

| Control                  | Upwork | LinkedIn |
|--------------------------|:------:|:--------:|
| Default keywords         | ❌     | ✅       |
| Default location         | ❌     | ✅       |
| Default job type         | ❌     | ❌       |
| Default experience level | ❌     | ❌       |
| Default remote filter    | ❌     | ❌       |
| Default categories       | ❌     | ❌       |
| Search interval (cron)   | ❌     | ❌       |
| Max results per search   | ❌     | ❌       |
| Per-platform excluded kw | ❌     | ❌       |

### 4. No Notification or Dedup Controls

The auto-import pipeline runs silently. Users have no way to receive alerts
when high-scoring jobs are found, and there is no deduplication
configuration to prevent re-importing the same job across search cycles.

---

## Proposed Solution

### Layer 1 — Search Defaults (What Is Searched)

Add per-platform search defaults that the `search_upwork_jobs` and
`search_linkedin_jobs` tools read when parameters are omitted from the
tool call.

**Upwork additions** (stored under `external_sourcing.upwork`):
| Field                       | Type     | Default |
|-----------------------------|----------|---------|
| `default_search_keywords`   | string   | `''`    |
| `default_location`          | string   | `''`    |
| `default_job_type`          | enum     | `''`    |
| `default_experience_level`  | enum     | `''`    |
| `default_categories`        | string   | `''`    |
| `search_interval_minutes`   | int      | `0`     |
| `max_results_per_search`    | int      | `20`    |
| `excluded_keywords`         | textarea | `''`    |

**LinkedIn additions** (stored under `external_sourcing.linkedin`):
| Field                       | Type     | Default |
|-----------------------------|----------|---------|
| `default_job_type`          | enum     | `''`    |
| `default_experience_level`  | enum     | `''`    |
| `default_remote`            | bool     | `false` |
| `search_interval_minutes`   | int      | `0`     |
| `max_results_per_search`    | int      | `20`    |
| `excluded_keywords`         | textarea | `''`    |

### Layer 2 — Result Format (How Data Is Returned)

Add a `result_format` sub-array under `external_sourcing` (shared across
both platforms because the output schemas are structurally similar):

| Field                 | Type  | Default |
|-----------------------|-------|---------|
| `description_length`  | int   | `200`   |
| `include_email`       | bool  | `false` |
| `include_client_info` | bool  | `true`  |
| `include_budget`      | bool  | `true`  |
| `include_skills`      | bool  | `true`  |
| `include_applicants`  | bool  | `true`  |
| `compact_mode`        | bool  | `false` |

The `execute()` methods in `search_upwork_jobs` and `search_linkedin_jobs`
read these fields and conditionally include/exclude output keys before
returning the results envelope.

### Layer 3 — Notification & Deduplication

Add `notification` and `deduplication` sub-arrays under `external_sourcing`:

**Notification:**
| Field                  | Type   | Default |
|------------------------|--------|---------|
| `enabled`              | bool   | `false` |
| `email`                | string | `''`    |
| `min_score_alert`      | int    | `80`    |
| `max_alerts_per_cycle` | int    | `5`     |

**Deduplication:**
| Field           | Type   | Default        |
|-----------------|--------|----------------|
| `enabled`       | bool   | `true`         |
| `strategy`      | enum   | `'title_url'`  |
| `lookback_days` | int    | `90`           |

---

## Affected Files

| File | Change |
|------|--------|
| `addons/pro/includes/tools/crm/class-wp-mcp-ai-crm-engine.php` | Add new defaults to `external_sourcing` array |
| `addons/pro/includes/admin/class-wp-mcp-ai-crm-settings-page.php` | Render new form fields in `render_configuration_tab()`; extend `sanitize_settings()` |
| `addons/pro/includes/tools/crm/upwork/class-wp-mcp-ai-tool-search-upwork-jobs.php` | Read defaults from CRM settings; apply `result_format` fields |
| `addons/pro/includes/tools/crm/linkedin/class-wp-mcp-ai-tool-search-linkedin-jobs.php` | Read defaults from CRM settings; apply `result_format` fields |
| `addons/pro/includes/tools/crm/upwork/class-wp-mcp-ai-tool-score-upwork-job.php` | Read per-platform scoring overrides |
| `addons/pro/includes/admin/class-wp-mcp-ai-crm-command-center-page.php` | Read new settings in `ajax_refresh_all_sources` |

## Backward Compatibility

All new keys are additive. The `array_replace_recursive` merge in
`get_toolkit_settings()` will fill any missing keys with the defaults listed
above. Existing stored settings without these keys continue to work
unchanged. No database migration is needed.

## Success Metrics

- All 22 new settings fields appear in the admin UI and persist correctly.
- `search_upwork_jobs` uses `default_search_keywords`, `default_location`,
  `default_job_type`, and `default_experience_level` from settings when the
  corresponding tool argument is omitted.
- `search_linkedin_jobs` uses the new defaults (job type, experience level,
  remote, excluded keywords) the same way.
- When `include_email` is enabled, result jobs include an `email` field (when
  available from the API); when disabled, it is omitted.
- `description_length` controls the word count; `0` returns the full
  description.
- `compact_mode` strips non-essential fields from the output.
- PHPCS passes: `composer run lint` with severity 1 on the changed files.
- Existing tests (if any) continue to pass.

---

## Decision Required

- **Approve** the 22 new settings fields and begin implementation.
- Consider deferring the cron/scheduled-search and email-notification
  wiring to a follow-up pass (the settings UI can land first; the actual
  cron hooks and `wp_mail` calls can follow).
