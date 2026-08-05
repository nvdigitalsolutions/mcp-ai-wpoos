# Implementation Plan: SGI Transparency & AI Content Labelling Compliance

**Based on:** Proposal 017 (`docs/project/proposals/017-sgi-transparency-compliance.md`)
**Date:** 2026-08-05
**Status:** In Progress
**Target releases:** v1.1.45 (Phase 1), v1.2.0 (Phase 2), v1.3.0 (Phase 3)

---

## Executive Summary

Build a three-layer Transparency Infrastructure (labelling, consent, provenance) across 5 new files and 7 modified files. Phase 1 delivers visible disclosure + consent by the August 2, 2026 deadline; Phases 2–3 add provenance logging and watermarking.

**Files created:** 5 new
**Files modified:** 7 existing
**Estimated LOC:** ~1,200 new + ~200 modified

---

## Phase 1 — Visible Disclosure & Consent (v1.1.45)

### Task 1.1 — Create Transparency Service (central controller)

**New file:** `includes/class-wp-mcp-ai-transparency-service.php`

**Purpose:** Central controller coordinating AI disclosure, consent, and provenance. Singleton pattern matching existing plugin conventions.

**Methods:**
- `get_instance()` — Singleton accessor
- `add_disclosure_headers( $response )` — Hook into `rest_post_dispatch` to add `X-AI-Generated`, `X-AI-Model`, `X-AI-Provider`, `X-AI-Transparency` headers
- `get_disclosure_banner_html()` — Return ARIA-accessible AI disclosure banner markup
- `get_consent_modal_html()` — Return consent modal markup
- `is_transparency_enabled()` — Check admin setting
- `is_consent_required()` — Check admin setting
- `get_disclosure_message()` — Return configured disclosure text with fallback default

**Acceptance criteria:**
- Headers present on all REST chat responses
- Banner HTML is ARIA-compliant and translatable
- Settings-controlled enable/disable works

---

### Task 1.2 — Create Consent Manager

**New file:** `includes/class-wp-mcp-ai-consent-manager.php`

**Purpose:** Collect, record, and verify user consent before AI interaction. Stores consent state in browser via JS + server-side in security audit log.

**Methods:**
- `get_instance()` — Singleton accessor
- `render_consent_interface()` — Render consent modal HTML for frontend
- `record_consent( $user_id, $context )` — Log consent event to security audit log
- `has_user_consented( $user_id )` — Check consent state (user-meta flag for logged-in users)
- `revoke_consent( $user_id )` — Handle consent revocation
- `register_consent_endpoint()` — REST endpoint for client-side consent recording

**REST endpoint:** `POST /mcp-ai/v1/transparency/consent`
- Records consent with timestamp, IP, user agent
- Returns `{ success: true, consented_at: 'ISO8601' }`

**Acceptance criteria:**
- Consent recorded in security audit log as `ai_consent_granted` / `ai_consent_revoked`
- Logged-in users persist consent across sessions
- Guest users see modal per-browser-session (localStorage)

---

### Task 1.3 — Create Generation Provenance System

**New file:** `includes/class-wp-mcp-ai-generation-provenance.php`

**Purpose:** Immutable, hash-chain-verifiable generation log for all AI interactions. Implements append-only provenance with cryptographic chain integrity.

**Database table:** `wp_mcp_ai_gen_log`

```sql
CREATE TABLE {$prefix}wp_mcp_ai_gen_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_key VARCHAR(96) NOT NULL,
    assistant_id VARCHAR(255) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    model VARCHAR(100) NOT NULL DEFAULT '',
    provider VARCHAR(50) NOT NULL DEFAULT '',
    prompt_hash CHAR(64) NOT NULL,
    response_hash CHAR(64) NOT NULL,
    previous_hash CHAR(64) NOT NULL DEFAULT '',
    row_hash CHAR(64) NOT NULL,
    message_count INT UNSIGNED NOT NULL DEFAULT 0,
    response_length INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    metadata JSON DEFAULT NULL,
    INDEX idx_session (session_key),
    INDEX idx_user (user_id),
    INDEX idx_assistant (assistant_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

**Methods:**
- `get_instance()` — Singleton accessor
- `create_table()` — dbDelta schema creation (called on activation)
- `log_generation( $session_key, $assistant_id, $user_id, $messages, $response, $model, $provider )` — Insert immutable provenance row with hash chain
- `verify_chain_integrity()` — Walk entire chain and verify all hashes; return `true|WP_Error`
- `get_logs( $args )` — Query generation logs with pagination and filtering
- `get_log_count()` — Total log count
- `prune_old_logs( $retention_days )` — Delete logs older than retention period
- `schedule_prune_cron()` — Register daily prune cron job
- `get_last_hash()` — Get the most recent row_hash for chain continuity

**Hash chain algorithm:**
```
row_hash = sha256( prompt_hash || response_hash || previous_hash || created_at || id )
```
For the first row, `previous_hash` is empty string. Each subsequent row includes the previous row's hash, creating a tamper-evident chain. Verifying walks from oldest to newest and recalculates every hash.

**Acceptance criteria:**
- Table created on plugin activation
- Every chat interaction produces a provenance row
- Hash chain is verifiable — modifying any record breaks verification
- Prune cron scheduled and runs daily
- Log viewer accessible in admin

---

### Task 1.4 — Create Admin Transparency Settings Page

**New file:** `includes/admin/class-wp-mcp-ai-admin-transparency-settings.php`

**Purpose:** Admin UI for configuring all transparency and compliance settings. Registered as a tab in the existing Settings Dashboard.

**Settings registered:**
| Setting Key | Type | Default | Description |
|---|---|---|---|
| `enable_ai_disclosure` | bool | `true` | Show AI disclosure badge in chat |
| `ai_disclosure_message` | string | `''` | Custom disclosure message (empty = default) |
| `ai_disclosure_position` | string | `banner` | `banner`, `header`, or `both` |
| `enable_consent_modal` | bool | `true` | Require consent before first chat |
| `consent_message` | string | `''` | Custom consent message |
| `enable_transparency_headers` | bool | `true` | Add AI provenance headers to REST responses |
| `enable_generation_logging` | bool | `true` | Record generation provenance records |
| `generation_log_retention_days` | int | `365` | Days to retain generation logs |

**Acceptance criteria:**
- Settings appear under "Transparency & Compliance" tab in Settings Dashboard
- All settings save and load correctly
- Defaults match values in `get_default_settings()`

---

### Task 1.5 — Modify Admin Settings Base (new defaults)

**File:** `includes/admin/class-wp-mcp-ai-admin-settings-base.php`

**Change:** Add transparency settings to `get_default_settings()` method. Locate the Security defaults section and add a new Transparency section after it.

**Acceptance criteria:**
- New defaults present in settings array
- Settings sanitized correctly through existing sanitization pipeline

---

### Task 1.6 — Modify REST Controller (transparency headers)

**File:** `includes/class-wp-mcp-ai-rest.php`

**Change:** Hook `WP_MCP_AI_Transparency_Service::add_disclosure_headers()` into `rest_post_dispatch` for chat endpoints. Add the hook registration in the constructor or `register_routes()` method, gated on the transparency service being loaded and enabled.

**Acceptance criteria:**
- Headers appear on `/mcp-ai/v1/chat` and `/mcp-ai/v1/chat-client` responses
- Headers include: `X-AI-Generated: true`, `X-AI-Provider: {provider_slug}`, `X-AI-Model: {model_slug}`
- Headers do NOT appear on non-chat endpoints (tools, assistants, transcripts)
- Headers are disabled when `enable_transparency_headers` is false

---

### Task 1.7 — Modify Chat Transcript Recorder (provenance hook)

**File:** `includes/class-wp-mcp-ai-chat-transcript-recorder.php`

**Change:** In the `record()` method, after successful transcript save, call `WP_MCP_AI_Generation_Provenance::log_generation()` to write the immutable provenance record. Gate on `enable_generation_logging` setting and provenance class being loaded.

**Acceptance criteria:**
- Provenance row written for every successful transcript save
- No row written when generation_logging is disabled
- Failure in provenance logging does not block transcript recording (fail-open with log warning)

---

### Task 1.8 — Modify Shortcode (AI disclosure injection)

**File:** `includes/class-wp-mcp-ai-shortcode.php`

**Change:** In `render_shortcode()`, prepend the AI disclosure banner HTML before the chat container when transparency is enabled. Also inject the consent modal HTML.

**Acceptance criteria:**
- Disclosure banner visible in shortcode chat output
- Banner uses ARIA `role="status"` for screen readers
- Banner is styled with existing chat theme
- Disabled when `enable_ai_disclosure` is false

---

### Task 1.9 — Modify Chat Bubble Frontend (AI disclosure)

**File:** `includes/class-wp-mcp-ai-chat-bubble-frontend.php`

**Change:** In `render_bubble()`, add the AI disclosure markup before the chat container. Mirror the shortcode approach.

**Acceptance criteria:**
- Disclosure visible in bubble chat
- Consistent with shortcode behavior

---

### Task 1.10 — Modify JavaScript Chat UI (disclosure + consent)

**File:** `assets/js/chat.js`

**Change:** Add two new functions:
1. `renderAIDisclosureBanner(container)` — Creates and inserts the AI disclosure badge banner
2. `renderConsentModal(container, instanceId)` — Creates and inserts consent modal, blocks chat until consent given

The disclosure banner is rendered unconditionally when the container has `data-ai-disclosure="true"`.
The consent modal checks localStorage for prior consent and shows modal on first visit.

**Acceptance criteria:**
- AI disclosure badge visible on all chat instances
- Consent modal appears on first visit per browser
- Modal is accessible (keyboard-navigable, ARIA-labelled)
- Consent persisted in localStorage for guest users
- Consent sent to server REST endpoint for logged-in users

---

### Task 1.11 — Create Transparency CSS

**New file:** `assets/css/chat-transparency.css`

**Purpose:** Styles for AI disclosure banner, badge, and consent modal. Matches existing chat theme CSS conventions.

**Acceptance criteria:**
- Badge/banner integrates visually with existing chat themes
- Consent modal is centered, responsive overlay
- Follows existing CSS class naming conventions (`wp-mcp-ai-*`)

---

### Task 1.12 — Update Privacy Policy

**File:** `includes/class-wp-mcp-ai-privacy.php`

**Change:** Update `get_privacy_policy_content()` to include:
- Section on AI-generated content labelling
- Section on generation provenance logging
- Section on data sent to AI providers
- User rights regarding AI interaction data

**Acceptance criteria:**
- Policy reflects new data collection (provenance logs, consent records)
- Policy mentions the standard AI disclosure notice
- Content is translatable

---

### Task 1.13 — Register in Loader

**File:** `includes/bootstrap/loader.php`

**Change:** Add require statements for the three new transparency classes in the security/transparency section, adjacent to the existing security class loading block.

**Acceptance criteria:**
- Classes load in correct order (Transparency Service → Consent Manager → Generation Provenance)
- Classes loaded before REST and chat components
- Autoload guard pattern followed

---

## Phase 2 — Provenance Logging (v1.2.0)

### Task 2.1 — Provenance Admin Log Viewer

**New/modified file:** `includes/admin/class-wp-mcp-ai-admin-transparency-settings.php` (extend)

**Change:** Add a "Generation Logs" sub-tab with:
- Paginated table of provenance records
- Filter by date range, assistant, user
- Chain integrity verification button
- Manual prune action

### Task 2.2 — REST Audit Endpoint

**New file:** `includes/rest/class-wp-mcp-ai-rest-transparency-controller.php`

**Purpose:** REST endpoint for external compliance audit tools:
- `GET /mcp-ai/v1/transparency/logs` — List generation logs (admin only)
- `GET /mcp-ai/v1/transparency/verify` — Verify hash chain integrity
- `GET /mcp-ai/v1/transparency/stats` — Compliance statistics

---

## Phase 3 — Watermarking (v1.3.0, target Dec 2026)

### Task 3.1 — C2PA Metadata Embedding

### Task 3.2 — Machine-Readable Output Marking

### Task 3.3 — Public Detection Endpoint

*(Detailed tasks to be specified based on Code of Practice finalisation expected June 2026)*

---

## Testing

### Unit Tests
- `tests/test-transparency-service.php` — Test header injection, disclosure HTML generation, settings gating
- `tests/test-consent-manager.php` — Test consent flow, recording, revocation
- `tests/test-generation-provenance.php` — Test hash chain creation, verification, tamper detection, pruning

### Integration Tests
- Test complete chat flow with disclosure + consent
- Verify REST headers on live responses
- Verify provenance row written after transcript save

---

## Rollback Plan

All new features are gated on admin settings:
- `enable_ai_disclosure` — set to `false` to disable all disclosure UI
- `enable_consent_modal` — set to `false` to disable consent
- `enable_transparency_headers` — set to `false` to disable REST headers
- `enable_generation_logging` — set to `false` to disable provenance logging

Setting all to `false` restores pre-transparency behavior with zero runtime overhead.
