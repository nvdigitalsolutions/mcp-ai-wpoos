# 129 — Outreach & CRM Toolkit Hardening: Sellbird Patterns, Deliverability, Signals and Pre-Send Safety

- **Date:** 2026-11-07
- **Status:** Implemented (P0–P5, 2026-11-07) — see [Implementation notes](129-outreach-crm-sellbird-hardening-implementation-plan.md#implementation-notes-2026-11-07) for the four decisions that changed during the build
- **Target:** Pro addon (`addons/pro`), PHP 8.1+, gated by `enable_crm_toolkit` and `enable_outbound_booking_toolkit`
- **Source assessed:** [Sellbird](https://github.com/ethanplusai/sellbird) (formerly Harvey) — `main` @ 2026-11-07, 4.7k stars, 1.0k forks, 476 commits, **AGPL-3.0**
- **Related:** [044 — Outbound Appointment Booking](044-outbound-appointment-booking.md), [`addons/pro/includes/tools/crm/README.md`](../../../addons/pro/includes/tools/crm/README.md), [`addons/pro/includes/tools/outbound-booking/README.md`](../../../addons/pro/includes/tools/outbound-booking/README.md), [`docs/CRM_TOOLKIT_ENHANCEMENT_PLAN.md`](../../CRM_TOOLKIT_ENHANCEMENT_PLAN.md)
- **Companions:** [Research notes](129-outreach-crm-sellbird-hardening-research.md) · [Implementation plan](129-outreach-crm-sellbird-hardening-implementation-plan.md)

> **Licence boundary (read first).** Sellbird is AGPL-3.0. The Pro addon is proprietary. **No Sellbird source code, prompt text, skill file, or documentation may be copied into this repository.** This proposal adopts *patterns* — architecture, control flow, thresholds, and test discipline — and specifies independent implementations. The research companion paraphrases and cites; it does not reproduce the upstream files.

---

## Executive summary

Sellbird is the clearest public example of the pattern the surviving AI-outreach tools converged on after the 2025 autonomous-agent failures: **let the model write, but let deterministic code decide whether anything may leave.** It separates four things the NV oOS outreach stack currently mixes together — *signal collection*, *message generation*, *send eligibility*, and *approval* — and puts a non-LLM gate, a per-recipient send window, an evidence trail, and an approval ladder between generation and the prospect.

The NV oOS CRM + Outbound Booking toolkits already have most of the *record-keeping* half of this: consent ledger, DNC, audit, pipeline stages, sequences, multichannel send, an approval outbox, angle bank, A/B tests, and booking links. What they do not have is the *admissibility* half:

- no deterministic pre-send gate (merge tags, banned phrases, length, link budget, HTML, recipient mismatch, postal footer);
- no deliverability state for an address (verified / risky / guess / invalid), no bounce loop, no warmup or ramp, no domain-authentication check;
- a send window measured in the **site's** clock, not the recipient's;
- no one-click unsubscribe headers (RFC 8058) on cold email;
- no signal catalogue with confirmation, provenance and cohort queries — ICP scoring exists, but a prospect cannot be explained from stored evidence;
- no "held" state for an unapproved email whose generated body links somewhere foreign or echoes the system prompt;
- no benchmark-aligned sequence presets or statistical guard on A/B promotion.

This proposal adds a **Deliverability & Safety layer** to the CRM toolkit and wires it into the existing send paths and Outbound Booking engine. It is additive: no existing tool slug, CPT, meta key or hook is removed. The work is scoped as six phases (P0–P5, ~29 dev-days; range 25–36 with review/QA) with P1 (the gate) and P2 (deliverability state + domain check) delivering most of the risk reduction on their own.

**Recommendation:** approve P0–P2 now, defer P3–P5 until the gate and readiness score have run against a real sending domain for one warmup cycle.

---

## 1. Why Sellbird is worth studying

Sellbird is not a competitor to the WordPress toolkit — it is a local Python agent that runs on a Claude/ChatGPT subscription CLI. Its value here is architectural, and it comes from four decisions:

1. **Deterministic-first.** The priority decision (`handle replies → send campaigns → write campaigns → prospect → idle`) is arithmetic over four counts, not a model call. Profile extraction, junk filtering, rank position, the pre-send gate and the send-window check are plain code. The model is reserved for judgement (writing, reply drafting, classification).
2. **A pre-send gate that cannot be talked out of.** Prompt instructions are treated as a floor, not a guarantee. Every outgoing email passes non-LLM checks at send time; a failure blocks the send and records why. This is the single most transferable idea in the repository.
3. **Evidence before personalisation.** Discover → profile → enrich → verify produces stored observations (signal, evidence, source, confidence), and only verified-deliverable addresses are ever sent to. A guess is never treated as a win.
4. **Approval is a ladder, not a switch.** Approval is the default; autopilot is opt-in with a typed confirmation; an autopilot email that links to a non-owned domain or quotes the system's own instructions is *held* for a human. "Approve all" deliberately excludes held mail.

The project also shows its seams honestly: a 29-signal catalogue (the project-structure comment in `docs/how-it-works.md` still says 23 at the time of review), a 242-test suite, and docs that state what is *not* known (e.g. OSM coverage gaps). That candour is itself a pattern worth copying.

### What is not transferable

| Sellbird element | Why not |
|---|---|
| Claude Code / Codex subscription runtime | NV oOS is provider-API based; no CLI subscription dependency is acceptable in a WordPress plugin. |
| Python + SQLite + FastAPI dashboard | Wrong runtime; the toolkit must remain WP-native (CPTs, options, REST, admin pages). |
| 29-signal catalogue as shipped | Several signals depend on paid providers (Serper, DataForSEO, Scavio) or scraped pages. We adopt the **catalogue shape**, not the provider bundle. |
| LinkedIn browser automation | Violates LinkedIn ToS; the existing approval/webhook hand-off stays. |
| AGPL source, prompts and skill text | Licence-incompatible. Patterns only; independent implementation. |

---

## 2. Current state in this repository

The relevant surfaces already exist and should be composed, not duplicated.

| Capability | Where it lives today | Maturity |
|---|---|---|
| Consent ledger, revocation, DNC | `crm/class-wp-mcp-ai-crm-consent.php`, `crm/class-wp-mcp-ai-crm-engine.php`, `crm/compliance/*` | Strong; channel-specific, audited, TCPA/FCC-aware |
| Audit ledger | `crm/class-wp-mcp-ai-crm-audit.php` | Strong; append-only rolling buffer |
| Identity / dedup / normalisation | `crm/class-wp-mcp-ai-crm-identity.php` | Good; email normalisation, company linking |
| Sequences | `crm/sequences/*` (`_active_sequence_id`, `_sequence_step`, `steps` meta) | Functional; steps carry `channel`, `template_id`, `wait_hours`, `branch_on_reply` only |
| Outbound email | `crm/outbound/class-wp-mcp-ai-tool-send-lead-email.php` | Consent + DNC + two veto filters, then `wp_mail()`; **no content gate** |
| Outbound SMS / WhatsApp / DM | `crm/outbound/*` | Consent-gated; channel-specific |
| Inbound triage | `crm/inbound/*`, `class-wp-mcp-ai-crm-classifier.php` | Intent/sentiment + BANT/MEDDIC extraction; reply recording |
| Opt-out | `crm/compliance/class-wp-mcp-ai-tool-process-opt-out.php` | DNC + consent revocation + PII pseudonymisation; **trigger is caller-supplied, not detected from reply text** |
| Email hygiene | `crm/compliance/class-wp-mcp-ai-tool-classify-email-hygiene.php` | Heuristic spam/promo/notification/priority classification; 90–180-day pruning |
| Outbound engine | `outbound-booking/class-wp-mcp-ai-oa-engine.php` | Hourly tick, per-run lead cap (200), **global** daily cap, site-timezone send window, consent attestation |
| Approval outbox | `outbound-booking/class-wp-mcp-ai-oa-outbox.php` | Pending/approved/sent/rejected; manual and auto dispatch |
| Angle bank / A-B tests | `outbound-booking/class-wp-mcp-ai-oa-angle-cpt.php` | Variant picking, weekly promotion, `min_test_sends` |
| Tracking | `crm/class-wp-mcp-ai-crm-link-tracker.php` | First-party tracked proposal links (`?nvoos_track=`) |

**The gap is not storage. It is admissibility** — nothing between "the model wrote this" and "the prospect received it" currently inspects the message, the address, the clock, or the evidence.

---

## 3. Gap analysis

Severity: **P0** = blocks safe sending at any volume; **P1** = degrades deliverability or compliance; **P2** = capability gap; **P3** = polish.

| # | Gap | Severity | Evidence in this repo | Sellbird / industry answer |
|---|---|---|---|---|
| G1 | **No deterministic pre-send gate.** No check for unrendered merge tags, banned phrases, subject/body length, link budget, HTML bodies, recipient↔record mismatch, or missing postal footer. | P0 | `send-lead-email.php` goes from consent/DNC straight to `wp_mail()` | `gate.py` runs every check at send time; a failure blocks and records the reason |
| G2 | **No address deliverability state.** `email` is a string; nothing records `verified / risky / guess / invalid`, when it was checked, or where the pattern came from. | P0 | No `email_status` meta in `crm/**` | Verify stage tags every address; only deliverable addresses are sent to |
| G3 | **No domain authentication, warmup or ramp.** `wp_mail()` uses whatever the host provides; no SPF/DKIM/DMARC check, no warmup schedule, no daily increase, no per-mailbox caps. | P0 | Not present in `crm/**` or `outbound-booking/**` | `sellbird warmup` checks SPF/DKIM/DMARC, domain age, blocklists, warmup progress; `max_daily_sends` is a ceiling, ramp starts 10–20/day |
| G4 | **Send window uses the site clock.** One `send_window_start/end` for all recipients; no timezone resolution, no weekdays-only, no reply exemption. | P1 | `WP_MCP_AI_OA_Settings::defaults()` + `WP_MCP_AI_OA_Engine::within_send_window()` use `current_time()` | Per-recipient office hours in the recipient's zone; replies never held |
| G5 | **No bounce or complaint feedback loop.** A hard bounce is invisible; nothing pauses a sequence or trips a kill switch. | P1 | No `bounce` handling in CRM/OA | Bounce > ~3% stops sending; deliverability monitor gates on it |
| G6 | **No one-click unsubscribe headers.** Cold email carries a footer only; no `List-Unsubscribe` / `List-Unsubscribe-Post`, no signed unsubscribe route. | P1 | `send-lead-email.php` appends a physical address only | RFC 8058 required by Gmail/Yahoo for bulk senders |
| G7 | **No signal catalogue, evidence store or cohort query.** ICP scoring exists, but a prospect cannot be explained from stored observations. | P2 | `WP_MCP_AI_ICP_Scorer` scores; no per-signal evidence/cohort model | 29 signals with plain-language description, cost, coverage; cohorts are live queries with must-have / disqualify / numeric ranges |
| G8 | **Sequence defaults are not benchmark-aligned.** Steps carry no purpose, word ceiling, link budget, or evidence requirement; default wait is 24h indefinitely. | P2 | `create-outreach-sequence.php` schema | 4–7 touches over 14–28 days; email 1 < 75 words, no link; follow-ups < 60 |
| G9 | **No autopilot "held" review.** Auto-send does not detect foreign links or instruction-leak echoes in generated copy. | P1 | Channel `auto` dispatches whatever was rendered | Held state with reason; "approve all" skips held mail |
| G10 | **Opt-out detection is caller-driven and English-centric.** `process_opt_out` is excellent at what it does, but nothing recognises "no thanks", "STOP", "BAJA", "הסר" in a reply thread. | P1 | `process-opt-out.php` expects an identifier argument | Rule layer detects opt-out wording in EN/ES/HE, treats it as immediate and permanent |
| G11 | **No sequencer-ready export.** Import exists (`import_crm_csv`); there is no export with verification status, signals, evidence and timezone. | P3 | No export tool in `crm/leads/` | `sellbird export` writes a sequencer-ready CSV |
| G12 | **A/B promotion has a minimum-send floor but no statistical guard.** `min_test_sends` defaults to 10. | P3 | `WP_MCP_AI_OA_Angle_CPT::pick_variant()` / weekly tests | Promote only after a meaningful sample; otherwise keep the control |
| G13 | **No sending-readiness score.** An operator can enable auto-send before DNS, warmup or list quality are ready. | P1 | No readiness surface | `sellbird warmup` returns READY / NOT READY with reasons |
| G14 | **AI disclosure and evidence requirements are not enforced.** The assistant may write without a citable observation, and there is no standard reply when a prospect asks whether they are talking to an AI. | P2 | No evidence meta or disclosure template | Answer truthfully always; personalisation traceable to stored evidence |
| G15 | **Tracking must stay first-party and opt-in.** `link-tracker` is fine for proposal links; the risk is carrying the same instinct into cold email. | P3 | `class-wp-mcp-ai-crm-link-tracker.php` | No open/click pixels on cold email; tracking costs 8–12% inbox placement |

---

## 4. Adopt / adapt / reject

### Adopt (independently implemented)

1. **Deterministic pre-send gate** as the single choke point for every outbound channel.
2. **Address lifecycle** `unknown → guess → risky → verified → invalid`, with source, timestamp and pattern evidence.
3. **Sending readiness** as a first-class state (DNS + warmup + bounce + list quality), blocking auto-send until green.
4. **Per-recipient send window** with timezone resolution, weekdays-only, and reply exemption.
5. **Signal catalogue with confirmation + evidence + cohort queries** (smaller than Sellbird's, provider-optional).
6. **Held state** for unapproved autopilot mail that fails the link/leak review.
7. **Benchmark-aligned sequence presets** and evidence requirement for AI-written copy.
8. **Rule-based multilingual opt-out detection** feeding the existing DNC/consent engine.
9. **One-click unsubscribe** (RFC 8058) with a signed token route.
10. **Readiness/why-prospect surfaces** in the admin and as MCP tools.

### Adapt

| Sellbird pattern | Adaptation for NV oOS |
|---|---|
| Heartbeat loop + deterministic priority | Keep WP-Cron/Action Scheduler cadence; reuse the existing OA hourly tick and add a per-run budget object rather than a new loop. |
| SQLite `observations` / `cohorts` | A dedicated signal table (`{prefix}mcp_ai_crm_signals`) with `lead_id`, `signal_key`, `value_num`, `value_text`, `evidence_json`, `source`, `confidence`, `observed_at`; filterable fallback to post meta for hosts that cannot create tables. |
| `max_daily_claude_percent` self-budget | Map to per-mailbox send caps and a daily model-call ceiling, not subscription quota. |
| 29 signals / paid providers | Ship a 12-signal **free/own-site** catalogue; provider-backed signals (SERP rank, maps rank, reviews) register through filters when a provider is configured. |
| Precleared per-recipient legal check | Optional filter seam `wp_mcp_ai_crm_recipient_eligibility`; no third-party dependency shipped. |

### Reject

| Rejected | Reason |
|---|---|
| Copying AGPL code, prompts or skills | Licence-incompatible with the proprietary Pro addon. |
| Running on Claude/ChatGPT subscription CLI | Not shippable in WordPress; provider APIs already exist. |
| Browser-automation LinkedIn sending | ToS risk; keep approval/webhook hand-off. |
| Open/click tracking pixels on cold email | Measured inbox-placement cost; first-party link tracking stays for proposal pages only. |
| A "approve all" that includes held mail | Defeats the review ladder; held mail must be read individually. |
| 100-result SERP depth / paid data bundle | Not a plugin responsibility; expose seams instead. |
| Auto-enabling autopilot on install | Approval must remain the default. |
| New CPTs for signals | A table is the right shape for cohort queries; CPTs would force expensive meta joins. |

---

## 5. Proposed architecture

### 5.1 New shared engine classes (CRM toolkit)

All new classes live in `addons/pro/includes/tools/crm/` and are loaded by the CRM toolkit loader, so the Outbound Booking toolkit can compose them without a second copy.

| Class | File | Responsibility |
|---|---|---|
| `WP_MCP_AI_CRM_Deliverability` | `class-wp-mcp-ai-crm-deliverability.php` | Address status lifecycle, pattern inference, bounce/complaint counters, domain-auth checks (SPF/DKIM/DMARC), warmup schedule, per-mailbox caps, readiness score. |
| `WP_MCP_AI_CRM_Pre_Send_Gate` | `class-wp-mcp-ai-crm-pre-send-gate.php` | Deterministic checks over `(recipient, subject, body, lead, channel, kind)`. Returns `true` or `WP_Error` with machine-readable reasons. No model calls. |
| `WP_MCP_AI_CRM_Send_Window` | `class-wp-mcp-ai-crm-send-window.php` | Resolve recipient timezone (explicit → country/state → site default), evaluate window, weekdays-only, reply exemption, next-open-time. |
| `WP_MCP_AI_CRM_Signals` | `class-wp-mcp-ai-crm-signals.php` | Signal catalogue (key, label, description, cost, collector), confirmation gate, observation write, cohort query builder, coverage counts. |
| `WP_MCP_AI_CRM_Agent_Guard` | `class-wp-mcp-ai-crm-agent-guard.php` | Fence untrusted text for prompts, post-generation review (foreign links, instruction-leak runs), hold reasons, AI-disclosure template. |
| `WP_MCP_AI_CRM_Unsubscribe` | `class-wp-mcp-ai-crm-unsubscribe.php` | Signed unsubscribe tokens, `List-Unsubscribe` headers, GET/POST route, consent revocation + DNC + audit. |
| `WP_MCP_AI_CRM_Opt_Out_Detector` | `class-wp-mcp-ai-crm-opt-out-detector.php` | Multilingual rule layer over reply text; returns matched phrase + rule + confidence; feeds `WP_MCP_AI_CRM_Consent` and `process_opt_out`. |

**Why the CRM toolkit and not Outbound Booking?** The send gate must also protect one-off sends (`send_lead_email`, SMS, WhatsApp) that never touch the OA engine. The CRM toolkit is the lower layer; OA already composes it.

### 5.2 Data model

**Lead/contact meta (additive, prefixed):**

| Meta key | Type | Meaning |
|---|---|---|
| `_crm_email_status` | enum | `unknown`, `guess`, `risky`, `verified`, `invalid` |
| `_crm_email_checked_at` | ISO-8601 | Last verification attempt |
| `_crm_email_source` | string | `published`, `pattern`, `provider`, `manual` |
| `_crm_email_pattern` | string | e.g. `{first}.{last}`, `{f}{last}` |
| `_crm_email_confidence` | int 0–100 | Verifier confidence |
| `_crm_bounce_count` | int | Hard + soft counters (separate meta) |
| `_crm_last_bounce_at` | ISO-8601 | Drives sequence pause |
| `_crm_timezone` | string | IANA zone, when known |
| `_crm_country` | ISO-3166-1 alpha-2 | Used for timezone/legal resolution |
| `_crm_personalization_evidence` | JSON | Signal keys + source URL + quote used in the draft |
| `_crm_ai_generated` | `0/1` | Set by writer paths; used by disclosure policy |

**Signal table (created on activation, versioned):**

```sql
CREATE TABLE {prefix}mcp_ai_crm_signals (
  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  lead_id       BIGINT UNSIGNED NOT NULL,
  signal_key    VARCHAR(64)     NOT NULL,
  value_num     DECIMAL(12,4)   NULL,
  value_text    TEXT            NULL,
  evidence_json LONGTEXT        NULL,
  source        VARCHAR(191)    NOT NULL DEFAULT '',
  confidence    TINYINT UNSIGNED NOT NULL DEFAULT 100,
  observed_at   DATETIME        NOT NULL,
  expires_at    DATETIME        NULL,
  PRIMARY KEY (id),
  KEY lead_signal (lead_id, signal_key),
  KEY signal_value (signal_key, value_num),
  KEY observed (observed_at)
) {charset_collate};
```

The table is created by the CRM installer with `dbDelta()` and a schema version option. A filter (`wp_mcp_ai_crm_signals_use_table`) can force post-meta storage on hosts that disallow custom tables; the query layer abstracts both.

**Settings options (new, no-autoload):**

- `wp_mcp_ai_crm_deliverability_settings` — `sending_domain`, `dkim_selector`, `warmup_start_date`, `warmup_min_days` (14), `warmup_start_per_day` (10), `warmup_daily_increase` (5), `max_per_mailbox_per_day` (50), `bounce_pause_threshold` (0.03), `complaint_threshold` (0.001), `mailboxes[]`, `require_verified` (true), `allow_risky` (false).
- `wp_mcp_ai_crm_gate_settings` — `max_subject_chars` (90), `max_body_words` (220), `soft_body_words` (75 for first touch), `max_links` (1), `require_plain_text`, `banned_phrases[]`, `require_evidence`, `require_postal_address`, `require_unsubscribe_headers`.
- `wp_mcp_ai_crm_signal_settings` — confirmed signal keys, provider handles, refresh TTLs.
- `wp_mcp_ai_crm_send_window_settings` — default window, weekdays-only, fallback timezone, per-country overrides.

All options are filterable through the existing `wp_mcp_ai_crm_toolkit_settings` pattern where practical; the new options keep their own defaults so the existing option shape is not destabilised.

### 5.3 Integration points

**a) Direct email send** — `crm/outbound/class-wp-mcp-ai-tool-send-lead-email.php`

Today the two veto filters receive `(null, $lead_id, 'email', $context)`. Extend the call *additively*:

```php
$gate = WP_MCP_AI_CRM_Pre_Send_Gate::check(
    array(
        'lead_id'   => $lead_id,
        'channel'   => 'email',
        'kind'      => $sequence_id ? 'sequence' : 'one_off',
        'to'        => $email,
        'subject'   => $subject,
        'body'      => $body,
        'context'   => $context,
    )
);
if ( is_wp_error( $gate ) ) {
    return $gate;
}
```

Then keep the existing `wp_mcp_ai_crm_before_outbound_send` filter for third parties. The gate runs **after** sanitisation and **before** `wp_mail()`, so it sees exactly what would leave.

**b) SMS / WhatsApp / DM** — same call with `channel` set; the gate applies channel-appropriate rules (no subject, SMS length, opt-out keyword presence where required).

**c) Outbound Booking dispatch** — `class-wp-mcp-ai-oa-channels.php`

Before `dispatch()`, run the gate. On failure:

- set the outbox row to `held` (new status) with `_oa_hold_reason` and the gate reasons;
- do **not** advance `_sequence_step` or `_oa_next_due`;
- surface the row in the Outbox "Held" rail;
- log an audit entry.

`WP_MCP_AI_OA_Outbox` gains `held` as a first-class status and an `approve_held()` path. "Approve all" excludes `held`, matching Sellbird's ladder.

**d) Send window** — `WP_MCP_AI_OA_Engine::within_send_window()` becomes a thin wrapper over `WP_MCP_AI_CRM_Send_Window::is_open( $lead_id, $channel )`. The engine processes replies first (never window-blocked), then sends; a due email outside the recipient's window is rescheduled to `next_open()` rather than skipped until the next hourly tick.

**e) Sequence step schema** — extend the step object, backward-compatibly:

```php
array(
  'channel'          => 'email',
  'template_id'      => 'angle-123',
  'wait_hours'       => 72,
  'branch_on_reply'  => true,
  'purpose'          => 'observation|angle|proof|breakup', // new, optional
  'max_words'        => 75,                                 // new, optional
  'link_budget'      => 0,                                  // new, optional
  'require_evidence' => true,                               // new, optional
)
```

Missing keys fall back to gate settings, so existing sequences keep working.

**f) Inbound replies** — `WP_MCP_AI_CRM_Opt_Out_Detector` runs before the classifier on every inbound message. On a match it calls the existing consent/DNC path immediately and records the rule + matched phrase in the audit ledger. A legal threat alongside an opt-out still opts out, and escalates to a human.

**g) Unsubscribe** — `wp_mail` headers filter adds:

```
List-Unsubscribe: <https://site.example/wp-json/mcp-ai/v1/crm/unsubscribe?token=...>
List-Unsubscribe-Post: List-Unsubscribe=One-Click
```

The token is an HMAC over `lead_id|email|channel|issued_at` with a rotating secret (`wp_salt('auth')` + option nonce). GET renders a confirmation page; POST (RFC 8058) revokes immediately. Both are rate-limited and audited. Transactional mail is excluded by kind.

### 5.4 New MCP tools

| Toolkit | Tool slug | Class | Purpose |
|---|---|---|---|
| CRM | `verify_lead_email` | `crm/quality/class-wp-mcp-ai-tool-verify-lead-email.php` | Infer pattern from published addresses, verify one candidate, write status/source/confidence; never brute-force. |
| CRM | `check_sending_domain` | `crm/compliance/class-wp-mcp-ai-tool-check-sending-domain.php` | SPF/DKIM/DMARC presence + alignment, DMARC policy, optional DNSBL via filter; returns READY/NOT READY with reasons. |
| CRM | `get_sending_readiness` | `crm/compliance/class-wp-mcp-ai-tool-get-sending-readiness.php` | Composite score: DNS, warmup progress, bounce rate, list verification coverage, gate settings. |
| CRM | `manage_sending_warmup` | `crm/compliance/class-wp-mcp-ai-tool-manage-sending-warmup.php` | Start/pause/extend warmup; preview today's cap; per-mailbox state. |
| CRM | `preview_pre_send_gate` | `crm/compliance/class-wp-mcp-ai-tool-preview-pre-send-gate.php` | Dry-run the gate against a lead + draft; returns pass/fail and every reason. |
| CRM | `explain_prospect_fit` | `crm/leads/class-wp-mcp-ai-tool-explain-prospect-fit.php` | Return confirmed signals + evidence + confidence + cohort membership for one lead. |
| CRM | `query_signal_cohort` | `crm/leads/class-wp-mcp-ai-tool-query-signal-cohort.php` | Must-have / disqualify / numeric-range query; live count + sample. |
| CRM | `export_outreach_csv` | `crm/leads/class-wp-mcp-ai-tool-export-outreach-csv.php` | Sequencer-ready CSV: identity, status, signals, evidence, timezone, consent state (DNC excluded). |
| CRM | `review_held_send` | `crm/outbound/class-wp-mcp-ai-tool-review-held-send.php` | List/approve/reject held outbox mail with reasons. |
| Outbound | `outbound_pipeline` (extend) | existing | Add `readiness`, `held`, and `signals` sections to the pipeline snapshot. |

Each tool follows the house rules: canonical envelope (`success` array or `WP_Error`), two-gate sanitisation, `is_available()` / `get_unavailable_reason()`, capability flags.

### 5.5 REST routes

| Route | Method | Auth | Purpose |
|---|---|---|---|
| `/mcp-ai/v1/crm/unsubscribe` | GET/POST | signed token | RFC 8058 one-click + human confirmation |
| `/mcp-ai/v1/crm/signals/cohort` | POST | `edit_posts` + nonce | Live cohort count (dashboard) |
| `/mcp-ai/v1/crm/readiness` | GET | `manage_options` | Readiness snapshot for the admin panel |

The existing OA routes (`bookings`, `replies`, `pipeline`) are unchanged; held rows are included in the pipeline payload with `status: held`.

### 5.6 Admin surfaces

1. **NV CRM → Outreach Readiness** — one screen: sending domain, DNS checks, warmup day/cap, bounce rate, verified coverage, gate settings, last gate blocks.
2. **Outbox → Held rail** — one held email at a time, reason shown, approve/reject; approve-all excludes held.
3. **Lead → Why this prospect** — evidence cards: signal, value, source URL, observed date, confidence, and the exact line used in the draft.
4. **Signals → Catalogue** — confirm/skip each signal, cost label, coverage count, refresh TTL.
5. **Sequences → Presets** — "4-touch / 14-day", "6-touch / 28-day", "breakup-only"; each preset pre-fills purpose, wait, word ceiling and link budget.

### 5.7 Sequence presets (benchmark defaults)

| Preset | Touches | Span | Email 1 | Follow-ups | Links |
|---|---|---|---|---|---|
| Short | 4 | 14 days | ≤75 words, 1 question | ≤60 words, new angle | 0 in #1, ≤1 later |
| Standard | 6 | 28 days | ≤75 words | ≤60 words, bump then new angle | 0 in #1, ≤1 later |
| Breakup-only | 2 | 7 days | ≤75 words | ≤40 words, close the loop | 0 |

Every preset sets `branch_on_reply = true`, pauses on meeting booked, and stops on opt-out/bounce.

### 5.8 Compliance pack

- **CAN-SPAM:** truthful subject/from, physical address, working opt-out, honour within 10 business days (we honour immediately).
- **GDPR/PECR (UK/EU):** legitimate-interest note per campaign; country-aware eligibility seam; objection link in every cold email; Germany defaults to "requires prior consent" in the eligibility filter.
- **CASL:** published-address basis where applicable; identification block.
- **AI disclosure:** standard truthful reply template; never deny automation.
- **LinkedIn:** approval/webhook only; no browser automation.

---

## 6. Deliverability rules (normative)

| Rule | Default | Enforcement |
|---|---|---|
| SPF, DKIM, DMARC present and aligned | required | `check_sending_domain` blocks auto-send |
| DMARC policy | `quarantine` or `reject` recommended | warning, not block, for `none` |
| Dedicated sending domain | recommended | readiness warning if `from_email` domain equals the main site domain |
| Warmup before cold volume | ≥14 days, 2–4 weeks recommended | `manage_sending_warmup` gates auto-send |
| Ramp | start 10–20/day/mailbox, +5/day | daily cap calculator |
| Hard ceiling | 50/day/mailbox | config clamp |
| Bounce rate | pause at >3%, warn at >2% | `bounce_pause_threshold` |
| Spam complaint rate | pause at >0.1%; never approach 0.3% | `complaint_threshold` |
| Cold-email links | 0 in first touch, ≤1 later | gate |
| Cold-email body | plain text, no HTML | gate |
| Cold-email tracking | no open/click pixels | gate (HTML/pixel detection) |
| One-click unsubscribe | required for bulk kinds | headers + route |
| Per-recipient window | 08:00–17:00 local, Mon–Fri | send-window engine |
| Replies | never window-blocked | engine order |

These are defaults, not law; every threshold is filterable and shown in the readiness screen.

---

## 7. Rollout

| Phase | Scope | Effort | Exit criteria |
|---|---|---|---|
| **P0** | Baseline fixtures, gate settings, reason codes, feature flags | 2 d | Gate can be dry-run in tests; no behaviour change |
| **P1** | Pre-send gate + held status + direct/OA wiring | 4 d | All outbound paths pass through the gate; PHPUnit covers every rule |
| **P2** | Deliverability state, verification tool, domain check, readiness | 5 d | Readiness blocks auto-send on a bad domain; verification writes status |
| **P3** | Send window, warmup/ramp, bounce loop, unsubscribe headers | 5 d | Per-recipient window proven with TZ fixtures; RFC 8058 route passes |
| **P4** | Signal catalogue, observations, cohorts, evidence, export | 8 d | Cohort counts match fixtures; evidence card renders |
| **P5** | Agent guard, opt-out detector, presets, admin polish | 5 d | Held mail excludes from approve-all; multilingual opt-out fixture passes |

Total: **~29 dev-days** (range 25–36 with review/QA). Detailed file map and test matrix in the [implementation plan](129-outreach-crm-sellbird-hardening-implementation-plan.md).

---

## 8. Success metrics

| Metric | Baseline | Target (90 days after P3) |
|---|---|---|
| Gate blocks with a recorded reason | 0 | 100% of blocked sends have a reason + audit row |
| Unrendered merge tags reaching a send | unknown | 0 |
| Hard bounce rate | unknown | < 2% (pause > 3%) |
| Spam complaint rate | unknown | < 0.1% |
| Verified-address coverage before first send | unknown | ≥ 90% |
| Sends outside recipient office hours | unknown | 0 |
| Held (autopilot) emails reviewed before send | n/a | 100% |
| Replies with a detected opt-out processed same day | unknown | 100% |
| Time to explain "why this prospect" | manual | < 1 tool call |
| A/B promotions below minimum sample | possible | 0 |

---

## 9. Risks and mitigations

| Risk | Likelihood | Impact | Mitigation |
|---|---|---|---|
| Gate produces false positives that block legitimate mail | Medium | High | Dry-run mode (`preview_pre_send_gate`), filterable rules, reason codes, per-rule kill switch |
| DNS checks are unreliable on shared hosting | Medium | Medium | Check via `dns_get_record()` with timeout; cache 6h; never block on a resolver error — warn + readiness downgrade |
| Custom signal table is rejected by a host | Low | Medium | `wp_mcp_ai_crm_signals_use_table` fallback to post meta; installer catches `dbDelta` failure |
| Per-recipient timezone is wrong | Medium | Medium | Resolution chain + "unknown → site fallback" is explicit; log the resolved zone on every send |
| Verification provider returns stale catch-all results | Medium | Medium | Status has TTL; risky is not deliverable by default; re-verify after 30 days |
| Warmup defaults too conservative for an established domain | Low | Low | Operator can set `warmup_start_date` in the past / disable ramp with a typed confirmation |
| Legal rules differ by jurisdiction | Medium | High | Eligibility filter seam; country-aware defaults; docs state "not legal advice" |
| Scope creep into a full prospecting product | Medium | Medium | Signals are provider-optional and own-site-first; P4 is the only phase with new collection |

---

## 10. Rejected alternatives

1. **A single "smart" gate that asks the model whether the email is good.** Rejected: non-deterministic, unexplainable, and exactly the failure mode the gate exists to prevent.
2. **Blocking all auto-send until every signal is implemented.** Rejected: P1/P2 deliver most of the risk reduction without P4.
3. **Putting the gate in the OA engine only.** Rejected: one-off CRM sends would bypass it.
4. **A new CPT for every signal.** Rejected: cohort queries would become meta joins; a table is the correct shape.
5. **Shipping a paid data provider bundle.** Rejected: cost, licensing, and the plugin's provider-agnostic stance.
6. **Copying Sellbird's prompt/skill text.** Rejected: AGPL and quality — our voice and vertical templates differ.

---

## 11. Decision required

Approve:

1. The **licence boundary** — patterns only, independent implementation, no AGPL code or prompt text.
2. **P0–P2** now (~11 dev-days), with **P3–P5** gated on a real warmup cycle.
3. The **new options and meta keys** (additive; no migrations of existing data).
4. The **custom signal table** (with post-meta fallback).
5. The **`held` outbox status** and the rule that "approve all" excludes held mail.
6. The **RFC 8058 unsubscribe route** and its token scheme.

---

## Appendix A — Sellbird patterns → NV oOS mapping

| Sellbird | NV oOS implementation |
|---|---|
| `gate.py::pre_send_check` | `WP_MCP_AI_CRM_Pre_Send_Gate::check()` |
| `gate.py::autopilot_review` | `WP_MCP_AI_CRM_Agent_Guard::review()` + outbox `held` |
| `send_window.py` | `WP_MCP_AI_CRM_Send_Window` |
| `deliverability.py` | `WP_MCP_AI_CRM_Deliverability` |
| `signals.py` + `state.py` observations | `WP_MCP_AI_CRM_Signals` + `{prefix}mcp_ai_crm_signals` |
| `collectors/profile.py` | Signal collectors registered by key; own-site first |
| `export.py` | `export_outreach_csv` |
| `sending_mode.py` | Existing OA channel modes + typed autopilot confirmation |
| `skills/email_frameworks.md` | Sequence presets + gate thresholds (our own copy, not copied text) |

## Appendix B — Sources

Sellbird repository (README, `docs/how-it-works.md`, `docs/signals-and-pipeline.md`, `docs/approval-and-sending.md`, `docs/deliverability.md`, `skills/README.md`, `skills/email_frameworks.md`, `sellbird/gate.py`) reviewed 2026-11-07. Industry sources and the evidence table are in the [research companion](129-outreach-crm-sellbird-hardening-research.md).
