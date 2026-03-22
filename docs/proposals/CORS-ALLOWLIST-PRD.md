# CORS Origin Allowlist Settings — Product Requirements Document

**Date:** March 2026
**Phase:** 2 — Planning
**Status:** Approved
**Author:** NV Digital Solutions / Agent: nv-oos-product-manager
**Brief Reference:** `docs/proposals/CORS-ALLOWLIST-PROJECT-BRIEF.md`

---

## Goals & Success Metrics

| Goal | Metric | Target |
|------|--------|--------|
| Admin can restrict CORS without writing code | Settings field exists in Security tab | 100% |
| Wildcard default preserved | Existing installs unaffected | 0 regressions |
| Single allowed-origin mode works | `Access-Control-Allow-Origin` header matches setting | 100% |
| Localhost allowed in debug mode | Dev environments unaffected | `WP_DEBUG = true` case passes |

---

## Functional Requirements

### FR-1: CORS Allowed Origins Field

- **Description:** Add a textarea field labelled **"CORS Allowed Origin"** to the
  **Security** settings tab, under a new **"CORS & Cross-Origin Control"**
  heading, after the existing **"Rate Limiting"** heading.
- **Priority:** Must Have
- **Acceptance Criteria:**
  - [x] Field key: `cors_allowed_origin` (singular — HTTP spec allows one value)
  - [x] Input: a single URL (e.g. `https://app.example.com`) or blank for wildcard
  - [x] Placeholder shows an example: `https://app.example.com`
  - [x] Description explains: blank = `*`; one URL only; `WP_DEBUG` adds localhost
  - [x] Field is saved to `wp_mcp_ai_settings['cors_allowed_origin']`
  - [x] Field is displayed in Security tab under a "CORS & Cross-Origin Control"
        heading

### FR-2: Settings → Filter Hook

- **Description:** After settings are loaded, hook `wp_mcp_ai_cors_allow_origin`
  to return the saved value (or `*` if empty).
- **Priority:** Must Have
- **Acceptance Criteria:**
  - [x] `WP_MCP_AI_Security_Manager` registers an `add_filter` in `__construct`
  - [x] When `cors_allowed_origin` setting is empty → filter returns `'*'`
  - [x] When `cors_allowed_origin` is a valid URL → filter returns that URL
  - [x] When `WP_DEBUG` is `true` and request origin is `http://localhost:*` →
        filter returns `http://localhost:[port]` (dynamic localhost allowance for
        development; does not override a configured production origin)
  - [x] When `WP_DEBUG` is `false` → localhost origin is not special-cased

### FR-3: Input Sanitization & Validation

- **Priority:** Must Have
- **Acceptance Criteria:**
  - [x] Value is sanitized with `esc_url_raw()` on save
  - [x] If the sanitized value differs from input (invalid URL), the field is
        stored empty (falls back to wildcard) and no error surfaces to avoid
        lockout
  - [x] Trailing slashes stripped to avoid mismatch

### FR-4: No New REST Endpoints

- **Priority:** Must Have
- **Acceptance Criteria:**
  - [x] Zero new REST routes registered by this feature
  - [x] Zero changes to existing REST permission callbacks

---

## Non-Functional Requirements

- **Performance:** Filter runs once per request via `apply_filters`; result
  may be cached in a local variable. Zero database reads at request time
  (settings are already loaded from WordPress object cache).
- **Security:** `manage_options` required to view or save the field (inherits
  Security tab capability gate). Input sanitized as URL.
- **Accessibility:** WCAG 2.1 AA — inherits existing settings UI.
- **Compatibility:** PHP 7.4+ (base plugin target). No new PHP 8-only features.
- **Backward Compatibility:** Default blank value → `*` → unchanged from current
  behaviour.

---

## Tool Definitions

No new NV oOS tools required.

---

## REST API Endpoints

No new REST endpoints required.

---

## Epics & Stories

### Epic 1: Settings UI

- **Story 1.1:** As a site administrator, I want a "CORS Allowed Origin" field
  in the Security tab, so that I can restrict which origins may call NV oOS
  endpoints without editing code.

### Epic 2: Filter Integration

- **Story 2.1:** As a developer, I want the plugin to automatically read the
  admin setting and apply it to the `wp_mcp_ai_cors_allow_origin` filter, so
  that the Security tab controls take effect without a custom snippet.
- **Story 2.2:** As a developer running `WP_DEBUG = true`, I want localhost
  origins to be allowed automatically, so that local development is not blocked.

### Epic 3: Tests

- **Story 3.1:** As a QA engineer, I want PHPUnit tests verifying that the filter
  returns `*` when the setting is empty, the configured URL when set, and
  localhost when in debug mode, so that regressions are caught automatically.

---

## Story Sequencing

1. Story 1.1 (settings field) — no dependencies
2. Story 2.1 + 2.2 (filter hook) — depends on 1.1
3. Story 3.1 (tests) — depends on 2.1 and 2.2

---

## PRD Validation Checklist

- [x] All goals have measurable success metrics
- [x] All requirements have acceptance criteria
- [x] Security requirements documented (sanitization with `esc_url_raw`)
- [x] Tool definitions follow NV oOS patterns (N/A — no new tools)
- [x] REST endpoints have permission callbacks (N/A — no new endpoints)
- [x] Stories are independent and testable
- [x] Dependencies identified
- [x] Base vs Pro gating specified — **Base plugin** (all stories in `includes/`)

---

*Next step: Architect (nv-oos-architect) creates the Architecture Spec →
`docs/proposals/CORS-ALLOWLIST-ARCHITECTURE.md`*
