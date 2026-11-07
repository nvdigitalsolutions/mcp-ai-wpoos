# 129 — Outreach & CRM Hardening: Implementation Plan

- **Date:** 2026-11-07
- **Status:** Implemented (P0–P5) — 2026-11-07
- **Parent:** [Proposal 129](129-outreach-crm-sellbird-hardening-proposal.md)
- **Research:** [Research notes](129-outreach-crm-sellbird-hardening-research.md)
- **Target:** Pro addon, PHP 8.1+, `enable_crm_toolkit` + `enable_outbound_booking_toolkit`
- **Estimate:** ~29 dev-days (range 25–36 with review/QA), five phases + a baseline phase

---

## Implementation notes (2026-11-07)

All six phases landed on branch `fix/forge-card-image-ratio`. Where the shipped code
differs from the plan above, the difference and its reason are recorded here — the
phase sections keep the original design so the rationale stays readable.

| Decision | Shipped | Why |
| --- | --- | --- |
| Send window default | `enabled => false` in `WP_MCP_AI_CRM_Send_Window::defaults()` | A hard 08:00–17:00 Mon–Fri gate silently changes the timing of sequences that already exist. Warmup, readiness and the gate are safe defaults; a business-hours gate is not. Enabling is per-site via `wp_mcp_ai_crm_send_window_settings`. |
| Signal table install | `dbDelta()` first, then a verified `CREATE TABLE IF NOT EXISTS` fallback on the same statement; `DB_VERSION` bumped to `1.0.1` | `dbDelta()` reports no error and creates nothing on some MySQL/MariaDB combinations. `install()` now checks `SHOW TABLES` and can never record a version it did not deliver. `init()` also repairs a stale `DB_VERSION` whose table is missing. |
| Cohort ranges (meta fallback) | Half-open `>=` / `<=` / `BETWEEN` clauses instead of `BETWEEN min, PHP_INT_MAX` | `(float) PHP_INT_MAX` stringifies as `9.22E+18`, which MySQL reads as `9`, so an open-ended range matched nothing on the meta path. |
| Signal table in tests | Created in `setUpBeforeClass()`, not `setUp()` | MySQL 8 implements atomic DDL: a `CREATE TABLE` inside `WP_UnitTestCase`'s per-test transaction is rolled back with it and is not even visible to `SHOW TABLES` on the same connection. Creating it before the transaction opens gives the indexed path real coverage; the meta path is covered by `test_meta_fallback`. |
| Static settings caches in tests | `test-crm-send-window` flushes in `tearDown()`; `test-oa-engine` flushes in `setUp()` | Both engines cache settings statically, so a window enabled by one test class leaked into the next. |

### Validation (Docker, MySQL 8.0.46)

| Gate | Result |
| --- | --- |
| New CRM/outbound tests (WP 6.9, PHP 8.2) | 471 tests, 6,781 assertions, 0 failures |
| Same ten new files on WP 7.1 | 77 tests, 216 assertions, 0 failures |
| Adjacent OA + CRM suites | 389 tests, 0 failures |
| `test-assistant-tool-presets` + `test-tool-registry-coverage` | 15 tests, 5,757 assertions, 0 failures |
| phpcs (`phpcs.xml.dist`, errors + warnings) | 0 violations across 33 new/modified files |

---

## 0. Guiding rules

1. **Additive only.** No existing tool slug, CPT, meta key, hook or option shape is removed or renamed. New step keys are optional with fallbacks.
2. **Feature-flagged.** Each phase lands behind a filter/setting (`wp_mcp_ai_crm_gate_enabled`, `wp_mcp_ai_crm_deliverability_enabled`, `wp_mcp_ai_crm_signals_enabled`), default off in code, enabled per toolkit setting.
3. **One choke point.** Every outbound channel goes through `WP_MCP_AI_CRM_Pre_Send_Gate::check()`. No surface may send around it.
4. **Deterministic.** The gate, window, readiness and truth checks make no model calls.
5. **Two-gate sanitisation.** Sanitize `$arguments[...]` at entry; escape every value at exit; return the canonical envelope (`success` array or `WP_Error`, never `array( 'success' => false )`).
6. **Explain every block.** `WP_Error` codes are stable and namespaced; each carries a human reason and a machine code.
7. **No data migration writes.** Missing meta means `unknown`; do not backfill across a fleet.

---

## Phase 0 — Baseline, fixtures and flags (2 days)

### Tasks

1. Add fixture leads in `addons/pro/tests/tools/crm/fixtures/`:
   - verified / risky / guess / invalid addresses;
   - DNC address + domain + plus-tag variants;
   - timezone fixtures (New York, Berlin, London, Colombo, Sydney) with a DST boundary date;
   - reply fixtures (EN/ES/HE opt-out, legal threat, quoted footer, unrelated "no gracias").
2. Add a DNS resolver seam:
   ```php
   $records = apply_filters( 'wp_mcp_ai_crm_dns_records', null, $host, $type );
   ```
   Tests inject records; production uses `dns_get_record()` with a 2s timeout and a 6h transient cache.
3. Add settings defaults + sanitisers for the three new options (no autoload).
4. Add `WP_MCP_AI_CRM_Pre_Send_Gate::REASONS` constants (stable codes) so tests and the UI agree.
5. Add feature flags and a `is_available()` guard to each new tool.

### Files

| Action | File |
|---|---|
| New | `addons/pro/includes/tools/crm/class-wp-mcp-ai-crm-gate-reasons.php` (or constants inside the gate) |
| New | `addons/pro/tests/tools/crm/fixtures/*.php` |
| Modify | `addons/pro/includes/tools/crm/init.php` (load new engine files) |

### Exit criteria

- `composer run lint` clean.
- A test can inject DNS records and read the new settings defaults.
- No behaviour change when all flags are off.

---

## Phase 1 — Pre-send gate + held status (4 days)

### 1.1 `WP_MCP_AI_CRM_Pre_Send_Gate`

**File:** `addons/pro/includes/tools/crm/class-wp-mcp-ai-crm-pre-send-gate.php`

**Public surface:**

```php
final class WP_MCP_AI_CRM_Pre_Send_Gate {
    const REASON_MERGE_TAG       = 'merge_tag_unrendered';
    const REASON_BANNED_PHRASE   = 'banned_phrase';
    const REASON_SUBJECT_LENGTH  = 'subject_too_long';
    const REASON_BODY_LENGTH     = 'body_too_long';
    const REASON_LINK_BUDGET     = 'too_many_links';
    const REASON_HTML_BODY       = 'html_body';
    const REASON_RECIPIENT       = 'recipient_mismatch';
    const REASON_NOT_DELIVERABLE = 'address_not_deliverable';
    const REASON_DNC             = 'do_not_contact';
    const REASON_POSTAL          = 'postal_address_missing';
    const REASON_EVIDENCE        = 'evidence_missing';
    const REASON_CHANNEL_LENGTH  = 'channel_length';

    /** @return bool|WP_Error True on pass; WP_Error on block (PHP 8.1-compatible). */
    public static function check( array $payload ); // bool|WP_Error
    public static function preview( array $payload ): array; // dry-run: reasons + normalized payload
    public static function defaults(): array;
}
```

**Checks (order matters):**

| Order | Check | Applies to |
|---|---|---|
| 1 | Required keys (`lead_id`, `channel`, `to`, `kind`) | all |
| 2 | Channel known (`email`, `sms`, `whatsapp`, `linkedin`, `instagram`) | all |
| 3 | Recipient syntax | email/SMS |
| 4 | Recipient ↔ lead record match | email; warn for SMS/WA |
| 5 | Address status deliverable (`verified`; `risky` only if `allow_risky`) | email sequence |
| 6 | DNC / suppression (delegate to `WP_MCP_AI_CRM_Engine::check_dnc`) | all |
| 7 | Subject non-empty + length | email |
| 8 | Body non-empty + word ceiling + soft warning | email |
| 9 | Channel length (SMS segments) | SMS |
| 10 | Merge tags (`{{x}}`, `{x}`) | all templated |
| 11 | Banned phrases (configurable list) | all |
| 12 | Link budget (URL count) | email/DM |
| 13 | HTML body / pixel detection | email |
| 14 | Postal address present when required | email cold |
| 15 | Evidence present when required | AI-written email |
| 16 | Unsubscribe line/headers enabled when required | email marketing kinds |

**Return shape:** `true` on pass; `WP_Error( 'wp_mcp_ai_crm_gate_blocked', $message, array( 'reasons' => array( array( 'code' => ..., 'detail' => ... ) ) ) )`. Never an array with `success => false`.

**Banned phrase list:** default to an **internal, independently authored** list. Do not copy Sellbird's list. Seed with broadly accepted spam/AI tells, and expose `wp_mcp_ai_crm_gate_banned_phrases`.

**Merge-tag parser:** must not flag legitimate braces in code samples. Rule: flag only `{{ word }}` and `{word}` where `word` is in the known token map (`first_name`, `company`, `booking_url`, `unsubscribe_url`, …) or matches `^[a-z_]+$` at a word boundary. Provide `wp_mcp_ai_crm_gate_merge_tag_pattern`.

### 1.2 Direct email integration

**Modify:** `addons/pro/includes/tools/crm/outbound/class-wp-mcp-ai-tool-send-lead-email.php`

- After sanitisation (`$subject`, `$body`), call the gate.
- Keep the two existing veto filters unchanged.
- Return the gate `WP_Error` directly.
- Add `kind` resolution: `sequence` when `sequence_id > 0`, else `one_off`.

### 1.3 SMS / WhatsApp / DM integration

**Modify:** `send-lead-sms.php`, `send-lead-whatsapp.php`, `send-lead-dm.php`

- Call the gate with the channel and the rendered body.
- SMS checks: length/segments, opt-out keyword requirement when configured.
- DM checks: link budget, banned phrases (no subject).

### 1.4 Outbound Booking integration + `held`

**Modify:** `addons/pro/includes/tools/outbound-booking/class-wp-mcp-ai-oa-channels.php`

- Before `dispatch()`, build the gate payload from the outbox row + lead.
- On failure: `WP_MCP_AI_OA_Outbox::hold( $outbox_id, $reasons )`, audit, return `WP_Error( 'oa_held', ... )`.
- On success: dispatch as today.

**Modify:** `class-wp-mcp-ai-oa-outbox.php`

- Add `held` to the allowed statuses.
- `hold()` writes `_oa_hold_reason` (JSON) and `_oa_held_at`.
- `approve_held()` moves `held → approved` and re-runs the gate; a second failure keeps it held and appends the new reason.
- `bulk_approve()` / "approve all" explicitly excludes `held`.

**Modify:** `class-wp-mcp-ai-oa-engine.php`

- `process_lead()`: on `WP_Error` from dispatch, do **not** advance `_sequence_step` or `_oa_next_due`.
- Include `held` rows in the pipeline snapshot (separate count, not "pending").

### 1.5 Admin

- Outbox page: a "Held" rail with one email at a time, reason chips, approve/reject.
- Lead edit screen: a "Gate" metabox showing the last dry-run result.
- Settings: gate thresholds + banned phrase editor (textarea, one per line).

### 1.6 Tests

`addons/pro/tests/tools/crm/test-crm-pre-send-gate.php`

| Test | Assertion |
|---|---|
| Each rule individually | Correct reason code |
| Multiple failures | All reasons returned, stable order |
| Pass case | Returns `true`, no side effects |
| DNC plus-tag | `jane+news@` blocked by `jane@` |
| DNC domain boundary | `acme.example` blocks `mail.acme.example`, not `notacme.example` |
| Merge-tag false positive | `{not a token}` not blocked unless configured |
| HTML/pixel body | Blocked; plain text passes |
| `preview()` | Writes nothing; returns normalized payload |

`addons/pro/tests/tools/outbound-booking/test-oa-outbox.php` (extend)

| Test | Assertion |
|---|---|
| Dispatch failure | Status `held`, step not advanced |
| Approve held | Status `approved`, gate re-run |
| Approve all | Held row untouched |
| Pipeline snapshot | `held` count separate |

### Exit criteria

- All outbound paths call the gate.
- A merge-tag draft cannot reach `wp_mail()`.
- Held mail is visible and approvable; approve-all excludes it.
- Tests green; phpcs clean.

---

## Phase 2 — Deliverability state, verification and readiness (5 days)

### 2.1 `WP_MCP_AI_CRM_Deliverability`

**File:** `addons/pro/includes/tools/crm/class-wp-mcp-ai-crm-deliverability.php`

**Public surface:**

```php
final class WP_MCP_AI_CRM_Deliverability {
    public static function get_status( int $lead_id ): array;
    public static function set_status( int $lead_id, string $status, array $meta = array() ): bool;
    public static function is_deliverable( int $lead_id, bool $allow_risky = false ): bool;
    public static function infer_pattern( string $domain, array $published ): array;
    public static function verify( int $lead_id, array $args = array() ): array|WP_Error;
    public static function record_bounce( int $lead_id, string $type, string $diagnostic = '' ): void;
    public static function check_domain( string $domain, string $selector = 'default' ): array;
    public static function readiness(): array;
    public static function daily_cap_for_mailbox( string $mailbox, ?string $date = null ): int;
}
```

**Status lifecycle:** `unknown → guess → risky → verified`; any → `invalid` on hard bounce or failed verification. `invalid` is terminal until an explicit re-verify.

**Pattern inference rules:**

- Learn only from addresses the site publishes next to a named person (same page).
- `jsmith@` + John Smith → `{f}{last}`; `jane.smith@` → `{first}.{last}`; `j.smith@` → `{f}.{last}`.
- A dotless local part with no named owner is ambiguous → teaches nothing.
- An address two named people could own → format evidence only, never a verification candidate.
- No evidence → `first.last@`, or `first@` when a small-business source exists.

**Verification order:**

1. If the site publishes the person's own address → `verified` (source `published`).
2. Else pattern candidate + MX check + provider verifier (filter seam) → `verified` / `risky` / `invalid`.
3. Else `guess` — never sendable.

**Provider seam:**

```php
$result = apply_filters( 'wp_mcp_ai_crm_verify_email', null, $email, $context );
// Expected: array( 'status' => 'verified|risky|invalid|unknown', 'confidence' => 0-100, 'provider' => '...' )
```

No provider is bundled. MX + syntax + pattern is the default.

### 2.2 Domain check

- SPF: TXT at the domain contains `v=spf1`.
- DKIM: TXT at `{selector}._domainkey.{domain}` contains `v=DKIM1` and `p=`.
- DMARC: TXT at `_dmarc.{domain}` contains `v=DMARC1`; parse `p=` policy.
- Alignment: the `From:` domain must match the authenticated domain (warn on mismatch).
- Optional DNSBL: `wp_mcp_ai_crm_dnsbl_zones` filter; default empty.
- Cache: 6h transient keyed by domain+selector.
- Error handling: resolver failure → `unknown`, never a hard block; readiness downgrades with a reason.

### 2.3 Readiness score

Composite 0–100 with hard blockers:

| Blocker | Condition |
|---|---|
| No sending domain configured | `sending_domain` empty |
| SPF missing | no `v=spf1` |
| DKIM missing | no key |
| DMARC missing | no record |
| Warmup not started | `warmup_start_date` empty |
| Warmup incomplete | days elapsed < `warmup_min_days` |
| Verified coverage low | < 90% of active leads |
| Bounce rate high | > `bounce_pause_threshold` |

Auto-send (`channel mode = auto`) refuses to start while any blocker is present. One-off sends may proceed with a warning (operator override), except when the gate blocks.

### 2.4 Bounce loop

- Inbound bounce messages (DSN) are recognised by `Content-Type: multipart/report` or a `From:` at `mailer-daemon@` / `postmaster@`.
- Hard bounce → `_crm_bounce_count++`, status `invalid`, pause sequence, audit.
- Soft bounce → counter + timestamp; 3 soft bounces in 14 days → pause.
- Global bounce rate computed daily; above threshold → pause all auto-send + readiness blocker.

### 2.5 New tools

- `verify_lead_email` — one lead, optional `force`; never brute-forces.
- `check_sending_domain` — domain + selector; returns structured checks.
- `get_sending_readiness` — score, blockers, per-mailbox caps.
- `manage_sending_warmup` — `start|pause|extend|status`; typed confirmation for "skip ramp".

Each tool: `edit_posts` (readiness: `manage_options`), `requires_base_pro`, capability flags `pro`, `outbound-network`, `requires-consent` where relevant.

### 2.6 Files

| Action | File |
|---|---|
| New | `crm/class-wp-mcp-ai-crm-deliverability.php` |
| New | `crm/quality/class-wp-mcp-ai-tool-verify-lead-email.php` |
| New | `crm/quality/README.md` (folder-README convention) |
| New | `crm/compliance/class-wp-mcp-ai-tool-check-sending-domain.php` |
| New | `crm/compliance/class-wp-mcp-ai-tool-get-sending-readiness.php` |
| New | `crm/compliance/class-wp-mcp-ai-tool-manage-sending-warmup.php` |
| Modify | `crm/init.php` (load class) |
| Modify | `addons/pro/mcp-ai-wpoos-pro.php` (tool map) |
| Modify | `crm/outbound/class-wp-mcp-ai-tool-send-lead-email.php` (status gate) |
| Modify | `crm/class-wp-mcp-ai-crm-engine.php` (bounce hooks, readiness helper) |

### 2.7 Tests

`test-crm-deliverability.php`

| Test | Assertion |
|---|---|
| Pattern inference | Each pattern from fixture pages |
| Ambiguous address | Teaches nothing; no verification |
| Two-owner address | Not verified |
| Provider seam | `verified` / `risky` / `invalid` mapped correctly |
| Hard bounce | Status `invalid`, sequence paused |
| Soft bounce ×3 | Paused |
| Domain check | SPF/DKIM/DMARC fixture matrix |
| Resolver error | `unknown`, no block |
| Readiness | Each blocker fires; score monotonic |
| Per-mailbox cap | Ramp day N returns expected cap |

---

## Phase 3 — Send window, warmup ramp, unsubscribe (5 days)

### 3.1 `WP_MCP_AI_CRM_Send_Window`

**File:** `addons/pro/includes/tools/crm/class-wp-mcp-ai-crm-send-window.php`

```php
public static function resolve_timezone( int $lead_id ): array; // [zone, source]
public static function is_open( int $lead_id, string $channel, ?DateTimeImmutable $now = null ): bool;
public static function next_open( int $lead_id, string $channel, ?DateTimeImmutable $now = null ): DateTimeImmutable;
public static function is_reply( string $kind ): bool;
```

Resolution chain: `_crm_timezone` → `_crm_country` (+ state) → phone country code → site timezone. Every resolution logs `source` for debugging.

Rules:

- Default window 08:00–17:00 local, Mon–Fri (`weekdays_only`).
- `kind = reply` is never window-blocked.
- A due email outside the window is rescheduled to `next_open()`, not skipped for an hour.
- Overnight windows supported (start > end).
- DST-safe: use `DateTimeImmutable` with the IANA zone, never fixed offsets.

**Modify:** `WP_MCP_AI_OA_Engine::within_send_window()` → wrapper; `process_lead()` uses `next_open()` for `_oa_next_due`.

### 3.2 Warmup ramp

- `warmup_start_date`, `warmup_min_days`, `warmup_start_per_day`, `warmup_daily_increase`, `max_per_mailbox_per_day`.
- Cap for day N = `min(max, start + increase * max(0, N - min_days_before_cold))`.
- Replies always allowed; transactional always allowed.
- Counters stored per mailbox per UTC day (`wp_mcp_ai_crm_send_counter_{mailbox}_{Ymd}`), not a single global day option.
- Existing `daily_cap` in OA remains a global ceiling; the effective cap is `min(global, mailbox)`.

### 3.3 Unsubscribe

**File:** `addons/pro/includes/tools/crm/class-wp-mcp-ai-crm-unsubscribe.php`

- Token: `base64url( lead_id . ':' . issued_at . ':' . hmac_sha256( lead_id|email|channel|issued_at, wp_salt('auth') . option_secret ) )`.
- TTL: 90 days; expired tokens still revoke (log `expired_token`) to avoid trapping a recipient.
- GET: render a minimal confirmation page (no login).
- POST: RFC 8058 one-click; revoke immediately; return 200.
- Both: rate-limited by IP + token, audited, idempotent.
- Headers added by a `wp_mail` filter only when `kind` is `sequence` or `marketing` and the setting is on.
- `List-Unsubscribe` URL must be absolute, HTTPS, and same-site.

**Modify:** `send-lead-email.php` (header filter + `unsubscribe_url` token available to templates).

### 3.4 Multilingual opt-out detector

**File:** `addons/pro/includes/tools/crm/class-wp-mcp-ai-crm-opt-out-detector.php`

- Rule packs: `en`, `es`, `he` (extendable via `wp_mcp_ai_crm_opt_out_rules`).
- Match whole phrases and bare keywords; ignore quoted text (the prior outbound body, footers).
- Return `array( 'matched' => bool, 'phrase' => '', 'rule' => '', 'lang' => '', 'legal_threat' => bool )`.
- On match: `WP_MCP_AI_CRM_Consent::revoke()`, DNC add, audit, pause sequence, escalate if `legal_threat`.
- Wire into inbound message handling before the classifier.

### 3.5 Files

| Action | File |
|---|---|
| New | `crm/class-wp-mcp-ai-crm-send-window.php` |
| New | `crm/class-wp-mcp-ai-crm-unsubscribe.php` |
| New | `crm/class-wp-mcp-ai-crm-opt-out-detector.php` |
| Modify | `outbound-booking/class-wp-mcp-ai-oa-engine.php` |
| Modify | `outbound-booking/class-wp-mcp-ai-oa-settings.php` (per-mailbox caps) |
| Modify | `crm/outbound/class-wp-mcp-ai-tool-send-lead-email.php` |
| Modify | `crm/inbound/*` (detector call) |
| Modify | `crm/class-wp-mcp-ai-crm-consent.php` (revoke helper if missing) |
| Modify | `addons/pro/includes/class-wp-mcp-ai-rest.php` or CRM REST registrar (unsubscribe route) |

### 3.6 Tests

| Test | Assertion |
|---|---|
| NY 09:00 / Berlin 15:00 / Colombo 18:30 | Window decisions correct |
| DST spring-forward | No off-by-one hour |
| Overnight window | 20:00–06:00 correct |
| Reply at 02:00 local | Not blocked |
| Due outside window | `next_open()` in the recipient zone |
| Ramp day 0/3/20 | Cap matches formula |
| Multi-mailbox | Independent counters |
| Token tamper / expiry | Tamper rejected; expiry still revokes |
| RFC 8058 POST | 200 + consent revoked + DNC + audit |
| EN/ES/HE opt-out | Detected; quoted text ignored |
| Legal threat + opt-out | Opt-out stands + escalation |

---

## Phase 4 — Signals, evidence, cohorts, export (8 days)

### 4.1 Signal table

**New:** `addons/pro/includes/tools/crm/class-wp-mcp-ai-crm-signals-db.php`

- `init()` mirrors `WP_MCP_AI_ECA_Enrollments_DB::init()`.
- `install()` uses `dbDelta()`; schema version option `wp_mcp_ai_crm_signals_db_version`.
- `insert()`, `query()`, `coverage()`, `delete_for_lead()`.
- Table name `{prefix}mcp_ai_crm_signals`; charset collate from `$wpdb`.
- If `dbDelta` fails or the filter `wp_mcp_ai_crm_signals_use_table` is false → meta fallback (`_crm_signal_{key}` + `_crm_signal_{key}_evidence`).

### 4.2 Signal catalogue

**New:** `addons/pro/includes/tools/crm/class-wp-mcp-ai-crm-signals.php`

Ship 12 signals, all own-site/free by default:

| Key | Type | Collector |
|---|---|---|
| `no_website` | bool | Listing/import field |
| `no_online_booking` | bool | Homepage scan |
| `no_schema_markup` | bool | Homepage scan |
| `blog_stale` | bool + last post date | Blog index / sitemap |
| `running_google_ads` | bool | Ad pixel/script detection |
| `running_meta_ads` | bool | Pixel detection |
| `incumbent_agency` | text + confidence | Footer credit parse |
| `hiring_role` | text + url | Careers page / board link |
| `open_roles` | int | Careers page count |
| `tech_stack` | text[] | Generator/script signatures |
| `contact_form_url` | url | Page link scan |
| `review_rating` / `review_count` | num | Provider seam only |

Provider seams: `wp_mcp_ai_crm_signal_serp_rank`, `..._maps_rank`, `..._reviews` — registered only when a provider is configured.

**Confirmation gate:** signals are `proposed` until confirmed in settings. Collectors do not run for unconfirmed keys.

**Evidence JSON shape:**

```json
{
  "value": "no online booking",
  "source_url": "https://acme.example/contact",
  "quote": "Call us on 555-0100 to book",
  "observed_at": "2026-11-07T10:00:00Z",
  "confidence": 90
}
```

**Refresh TTL:** volatile (ads, hiring, rank, reviews) 14 days; structural (tech stack, incumbent, schema) 90 days.

### 4.3 Cohort query

```php
WP_MCP_AI_CRM_Signals::cohort( array(
    'must'      => array( 'incumbent_agency', 'running_google_ads' ),
    'must_not'  => array( 'no_online_booking' ),
    'ranges'    => array( 'serp_rank' => array( 'min' => 11, 'max' => 30 ) ),
    'limit'     => 50,
    'count_only'=> false,
) );
```

- Builds an indexed query against the table; falls back to meta queries.
- Returns `count`, `sample` (lead IDs + evidence), and `coverage` per signal.
- Cache the count for 5 minutes per query hash.

### 4.4 Evidence requirement

- Writer paths write `_crm_personalization_evidence` before send.
- Gate check #15: if `require_evidence` and the evidence is empty or references no confirmed signal → block.
- `explain_prospect_fit` returns the evidence card; the lead screen renders it.

### 4.5 Export

`export_outreach_csv` columns:

```
external_id, first_name, last_name, title, company, website, email, email_status,
email_confidence, timezone, country, consent_basis, signals, evidence_summary,
booking_url, source, observed_at
```

- DNC/invalid are excluded by default (`include_suppressed=false`).
- Streams in batches of 500; uses `fputcsv` to `php://output` or a temp file.
- CSV injection guard: prefix cells starting with `= + - @` with `'`.

### 4.6 Files

| Action | File |
|---|---|
| New | `crm/class-wp-mcp-ai-crm-signals-db.php` |
| New | `crm/class-wp-mcp-ai-crm-signals.php` |
| New | `crm/collectors/class-wp-mcp-ai-crm-signal-collector.php` (base + own-site collectors) |
| New | `crm/collectors/README.md` (folder-README convention) |
| New | `crm/leads/class-wp-mcp-ai-tool-explain-prospect-fit.php` |
| New | `crm/leads/class-wp-mcp-ai-tool-query-signal-cohort.php` |
| New | `crm/leads/class-wp-mcp-ai-tool-export-outreach-csv.php` |
| Modify | `crm/init.php`, `addons/pro/mcp-ai-wpoos-pro.php` |
| Modify | `crm/class-wp-mcp-ai-crm-pre-send-gate.php` (evidence check) |

### 4.7 Tests

| Test | Assertion |
|---|---|
| Table install + idempotent `dbDelta` | No duplicate columns |
| Insert/query round-trip | Evidence preserved |
| Cohort must / must-not / range | Exact fixture counts |
| Coverage counts | Per-signal totals |
| TTL expiry | Expired signal excluded |
| Unconfirmed signal | Collector does not run |
| Evidence gate | Missing evidence blocks; present passes |
| CSV | Columns, DNC excluded, injection guard |
| Meta fallback | Same query results |

---

## Phase 5 — Agent guard, presets, admin polish (5 days)

### 5.1 `WP_MCP_AI_CRM_Agent_Guard`

**File:** `addons/pro/includes/tools/crm/class-wp-mcp-ai-crm-agent-guard.php`

- `fence( string $untrusted ): string` — wrap external text in explicit data markers for prompts.
- `review( string $subject, string $body, array $context ): array` — returns hold reasons:
  - links/email addresses to domains not in the allowed set (sender domain, trained site, recipient domain, booking page, `link_domains`);
  - a run of ≥12 consecutive words matching the plugin's own prompt/skill text (compare against a hashed n-gram index built at runtime);
  - unreadable/obfuscated links (IP literals, dot-forms, zero-width chars).
- `disclosure_template(): string` — truthful AI disclosure reply.
- Allowed domains filter: `wp_mcp_ai_crm_allowed_link_domains`.

Wire: in OA auto mode and in `send_lead_email` when `_crm_ai_generated = 1` and the operator has not approved this specific draft → `held`.

### 5.2 Sequence presets

- Add presets to the sequence CPT/admin: `short` (4/14d), `standard` (6/28d), `breakup` (2/7d).
- Preset fills `purpose`, `wait_hours`, `max_words`, `link_budget`, `require_evidence`.
- Existing sequences remain valid; missing keys fall back to gate settings.

### 5.3 A/B statistical guard

- Replace "min sends only" with a sequential guard:
  - minimum 30 sends per variant **and**
  - a two-proportion z-test at 95% (or a beta-binomial posterior probability ≥ 0.95).
- If not significant, keep the control and extend the test window.
- Store `test_state` (sends, replies, posterior) on the angle CPT.
- Filter: `wp_mcp_ai_crm_ab_promotion_rule`.

### 5.4 Admin polish

- Readiness screen (Phase 2 data).
- Held rail (Phase 1).
- Why-this-prospect card (Phase 4).
- Signal catalogue + confirmation (Phase 4).
- Presets picker (Phase 5).

### 5.5 Tests

| Test | Assertion |
|---|---|
| Foreign link | Held with domain reason |
| Recipient domain link | Allowed |
| Booking page subpath | Allowed; `../` traversal not allowed |
| Prompt-leak run | Held |
| Obfuscated link | Held |
| Preset | Steps match expected shape |
| A/B significant / not | Promote only when significant |

---

## Test matrix (commands)

```bash
# CRM unit + integration
vendor/bin/phpunit addons/pro/tests/tools/crm/

# Outbound booking
vendor/bin/phpunit addons/pro/tests/tools/outbound-booking/

# Targeted new suites
vendor/bin/phpunit --filter Pre_Send_Gate addons/pro/tests/tools/crm/
vendor/bin/phpunit --filter Deliverability addons/pro/tests/tools/crm/
vendor/bin/phpunit --filter Send_Window addons/pro/tests/tools/crm/
vendor/bin/phpunit --filter Signals addons/pro/tests/tools/crm/

# Lint + compat + sniffs
composer run lint
composer run lint:compat
composer run test
```

The two custom PHPCS sniffs (`WPMCPAI.Tools.CanonicalReturnEnvelope`, `WPMCPAI.Tools.SanitizeAtEntry`) must pass for every new tool.

---

## Registration checklist (per new tool)

1. Class file in the correct subfolder.
2. `is_available()` + `get_unavailable_reason()`.
3. `get_slug()`, `get_name()`, `get_description()`, `get_usage_guidance()`.
4. `get_parameters_schema()` with types, enums, max lengths.
5. `get_required_capability()` and `requires_base_pro()`.
6. `get_capability_flags()` (`pro`, `database-write`/`outbound-network`, `requires-capability`, `requires-consent` where applicable).
7. Add to the tool map in `addons/pro/mcp-ai-wpoos-pro.php`.
8. Add to the toolkit README public-surface table.
9. Tests in `addons/pro/tests/tools/crm/` (or `outbound-booking/`).
10. Coverage manifest if the toolkit has one (`composer run docs:check-folder-readmes` for folder READMEs).
11. If the tool introduces a new subdirectory, add that folder's `README.md` (purpose, public surface, neighbours, `.context/` companions) and run `composer run docs:check-folder-readmes`.

---

## Rollback plan

| Layer | Rollback |
|---|---|
| Gate | `wp_mcp_ai_crm_gate_enabled = false` → `check()` returns `true` |
| Deliverability | `wp_mcp_ai_crm_deliverability_enabled = false` → readiness not enforced; status still readable |
| Held status | Existing rows remain; UI hides the rail; `held` treated as `pending` |
| Send window | Legacy `within_send_window()` used when the engine flag is off |
| Unsubscribe | Header filter removed; footer remains |
| Signals | Collectors stop; table remains (no data loss); meta fallback works |
| Agent guard | Review disabled; approval ladder still protects autopilot |

No option or meta is deleted on deactivation. The signal table is dropped only by `uninstall.php` when the operator opts into full removal.

---

## Delivery sequence and review gates

| Gate | Before starting | Review artifact |
|---|---|---|
| G0 | Approve licence boundary + data model | This plan + proposal §11 |
| G1 | Phase 0 fixtures merged | Test plan + reason-code list |
| G2 | Phase 1 merged | Gate coverage table (rule → test) |
| G3 | Phase 2 merged | Readiness screenshot + DNS fixture matrix |
| G4 | Phase 3 merged | Timezone matrix + unsubscribe RFC checklist |
| G5 | Phase 4 merged | Cohort fixture counts + export sample |
| G6 | Phase 5 merged | Held-mail UX + A/B guard test |

---

## Open implementation questions

1. **Table creation timing.** On plugin activation or on first CRM init? Recommendation: `init()` with a version check, mirroring ECA.
2. **Unsubscribe secret rotation.** `wp_salt('auth')` changes can invalidate old tokens. Recommendation: include `issued_at` and accept expired tokens for revocation (log only).
3. **Verification provider.** Ship interface only, or add a default heuristic verifier (MX + syntax)? Recommendation: interface + MX heuristic; no paid API.
4. **Bounce ingestion.** IMAP poll vs webhook. Recommendation: reuse the existing inbound mail path; add DSN detection there.
5. **CSV streaming.** `php://output` vs temp file. Recommendation: temp file + `readfile()` to avoid partial output on timeout.
6. **Evidence for non-AI templates.** Should hand-written templates also require evidence? Recommendation: warn, not block.
7. **Signal table on multisite.** Per-site tables (standard) vs network-wide. Recommendation: per-site, matching CPT storage.
8. **`held` retention.** 30 days then auto-reject with an audit row. Confirm with support.
