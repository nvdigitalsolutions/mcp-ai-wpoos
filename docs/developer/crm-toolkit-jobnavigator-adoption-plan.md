# CRM Toolkit — JobNavigator Adoption Plan

**Source review:** [vesaias/JobNavigator](https://github.com/vesaias/JobNavigator) — self-hosted job-hunt automation with an application-tracking core.
**Adoption target:** NV oOS Pro Toolkit CRM (`addons/pro/includes/tools/crm/`).
**Status:** Implemented (WP1–WP9 ✅, WP10 ✅ except WP 7.1 + full-suite validation pending).

## Why JobNavigator

JobNavigator's application tracker solves the same problem the CRM deal pipeline
solves: a record moves through stages, actors change it, automation reacts to
inbound email, and operators need to see *where things stalled*. It does several
things the CRM toolkit does not yet do:

1. Stores stage changes as **structured, machine-readable transitions** on the record.
2. Supports **undo** of the last stage move without polluting history.
3. Refuses **duplicate records** with a pointer to the existing one.
4. Classifies **inbound email replies** and updates the record (storing last-received/snippet).
5. Assembles a paste-ready **prep handover bundle** for any AI chat / human.
6. Bulk operations report **per-row outcomes** (`updated` / `skipped` / `not_found`).
7. Tracks **document opens** via unique links (tracer).
8. Deletes cascade: removing an application **releases** the linked job back to its prior state.
9. Canonical company aliasing + auto-created company profiles.
10. Auto-reject rules, light/full scoring depths, provider fallback, daily digest.

## Guiding principles

- Follow the repo tool contract: canonical envelope (`success` array) or `WP_Error`
  (**never** `array( 'success' => false, ... )`); two-gate sanitisation
  (sanitise `$arguments[...]` at entry, escape at exit).
- New shared helpers are static engine-style classes in
  `addons/pro/includes/tools/crm/` following the Phase A pattern
  (`WP_MCP_AI_CRM_*`).
- New tools live in the matching CRM subfolders and are registered in the class→path
  map of `wp_mcp_ai_pro_register_tools()` in `addons/pro/mcp-ai-wpoos-pro.php`.
- Every state change is mirrored to `WP_MCP_AI_CRM_Audit` and fires
  `wp_mcp_ai_crm_before/after_deal_stage_change` hooks.
- The Content Graph Pro port (`plugins/nvoos-content-graph-pro/`) is **not**
  edited here — it follows the ecosystem-port loop afterwards.
- Storage: CRM canonical post types are CPT-based; cross-store queries follow the
  existing analytics-tool pattern (`get_posts()` on `mcp_ai_lead` / `mcp_ai_deal`).
- Ageing-signal discipline (JobNavigator): a stage move stamps `stage_changed_at`;
  no-op updates never bump `updated_at`.

---

## WP1 — Deal stage transition history + undo + source attribution

**Files**

- `addons/pro/includes/tools/crm/class-wp-mcp-ai-crm-stage-history.php` (new)
- `addons/pro/includes/tools/crm/deals/class-wp-mcp-ai-tool-move-deal-stage.php` (edit)
- `addons/pro/includes/tools/crm/deals/class-wp-mcp-ai-tool-create-deal.php` (edit)

**Contracts**

- Meta on `mcp_ai_deal` (visible via `get_item`, no leading underscore):
  - `stage_history` — ordered array of `{ from, to, at, source }`; `from = null` for the seed entry; capped at 200 entries.
  - `stage_changed_at` — ISO 8601 UTC of the last *real* stage change.
- `WP_MCP_AI_CRM_Stage_History`:
  - `record( $deal_id, $from, $to, $source )` → bool
  - `get_history( $deal_id )` → array
  - `undo_last( $deal_id )` → array|null (pops last entry; restores `stage_changed_at` from the new tail)
  - `time_in_stage( $deal_id )` → int seconds|null
  - `next_open_stage( $current )` → string|null (first open stage ordered after `$current`)
  - `SOURCES` constant: `tool`, `agent`, `workflow`, `manual`, `email_reply`, `bulk`.
  - Action `wp_mcp_ai_crm_deal_stage_history_recorded` after every append.
- `move_deal_stage` gains two optional args:
  - `source` (enum of `SOURCES`, default `tool`)
  - `undo` (bool, default false) — restores the previous stage from history,
    pops the transition, does **not** record a new one, audits `deal_stage_reverted`.
  - Normal moves record the transition and stamp `stage_changed_at`.
  - No-op guard stays (returns `WP_Error already_at_stage`, no meta touched).
- `create_deal` seeds `stage_history` with `{ from: null, to: <stage>, at: now, source: 'tool' }` and stamps `stage_changed_at`.

**Tests:** move → history grows; `source` recorded; undo restores stage + probability,
history shrinks and no new entry; undo with empty history → `WP_Error no_stage_history`;
no-op move leaves history/`updated_at` untouched; create seeds history.

---

## WP2 — Lead dedup, canonical companies, email signal fields, source snapshot

**Files**

- `addons/pro/includes/tools/crm/class-wp-mcp-ai-crm-identity.php` (new)
- `addons/pro/includes/tools/crm/leads/class-wp-mcp-ai-tool-create-lead.php` (edit)
- `addons/pro/includes/tools/crm/leads/class-wp-mcp-ai-tool-update-lead.php` (edit)
- `addons/pro/includes/tools/crm/class-wp-mcp-ai-crm-engine.php` (edit — settings defaults)

**Contracts**

- `WP_MCP_AI_CRM_Identity` (static helpers):
  - `normalize_email( $email )` — lowercase trim.
  - `canonical_company_name( $name )` — trim, collapse whitespace, lowercase.
  - `find_lead_by_email( $email )` → int|0 (`get_posts` on `mcp_ai_lead`, meta `email`).
  - `find_company_by_canonical_name( $name )` → int|0 (meta `_company_name_canonical`).
  - `link_or_create_company( $lead_id, $company_name, $email )` → int|0 —
    canonical lookup → link via `company_id` meta; else auto-create `mcp_ai_company`
    (status `prospect`, canonical meta) gated by settings; else derive from email
    domain gated by `identity.auto_create_company_from_domain` (default off).
- Engine settings (new `identity` block + defaults, all three layers where UI exists —
  UI textareas are added only if a settings page renders them; defaults + runtime
  fallbacks are the code-level truth here):
  - `identity.dedupe_leads` (default true)
  - `identity.auto_create_company` (default true)
  - `identity.auto_create_company_from_domain` (default false)
  - `identity.canonical_company_names` (default true)
- `create_lead`:
  - New args: `allow_duplicate` (bool, default false), `source_message_id`,
    `source_email_snapshot` (capped text).
  - When dedupe enabled and email exists → `WP_Error wp_mcp_ai_duplicate_lead`
    (status 409) with `existing_lead_id` in error data.
  - Auto-links/creates company from `company_name` (or domain) → stores `company_id`.
- `update_lead` gains signal fields: `last_email_received` (ISO 8601),
  `last_email_snippet` (capped 500 chars), `last_email_sentiment`
  (enum: positive|neutral|negative|mixed|unknown).

**Tests:** duplicate refused with pointer; `allow_duplicate` passes; company
canonicalization (case/space variants match); auto-create company on/off gates;
domain fallback off by default; signal fields stored and capped.

---

## WP3 — Auto-disqualification rules (JobNavigator auto-reject analogue)

**Files**

- `addons/pro/includes/tools/crm/class-wp-mcp-ai-crm-engine.php` (edit)
- `addons/pro/includes/tools/crm/leads/class-wp-mcp-ai-tool-create-lead.php` (edit)
- `addons/pro/includes/tools/crm/leads/class-wp-mcp-ai-tool-update-lead.php` (edit)

**Contracts**

- Settings `auto_disqualify` block:
  - `enabled` (default false), `max_score` (default 20), `min_age_days` (default 30),
    `only_statuses` (default `['new','contacted']`).
- `WP_MCP_AI_CRM_Engine::maybe_auto_disqualify( $lead_id )` → bool:
  - Loads the lead; skips if disabled, lead missing, score above `max_score`,
    post date younger than `min_age_days`, or status not in `only_statuses`.
  - Sets `lead_status = 'disqualified'`, audits `lead_auto_disqualified`,
    fires `wp_mcp_ai_crm_lead_auto_disqualified`, returns true.
- Invoked post-save from `create_lead` and `update_lead` (non-blocking; a
  disqualification is reported in the tool response as `auto_disqualified: true`).

**Tests:** disabled = no-op; score gate; age gate; status gate; audit + hook fired.

---

## WP4 — Bulk deal stage move with per-row reporting + undo

**Files**

- `addons/pro/includes/tools/crm/deals/class-wp-mcp-ai-tool-bulk-move-deal-stages.php` (new)

**Contracts**

- Slug `bulk_move_deal_stages`; capability `edit_posts`; flags
  `pro`, `database-write`, `requires-capability`.
- Args: `deal_ids` (array<int>, max 100), `pipeline_stage` (required, validated),
  `undo` (bool), `source` (enum, default `bulk`).
- Response: `{ updated, skipped, not_found, rows: [{ deal_id, previous_stage, new_stage }] }`.
- Rows already at the stage are skipped (no transition, no `updated_at` bump);
  malformed IDs land in `not_found`; per-row failures never abort the batch.
- Each row reuses the same path as `move_deal_stage` (history, hooks, audit, won-cascade).

**Tests:** mixed batch → counts + rows correct; skip semantics; undo across batch;
invalid stage → 400; >100 IDs → 400.

---

## WP5 — Reply signals tool (inbound email auto-classification entry point)

**Files**

- `addons/pro/includes/tools/crm/inbound/class-wp-mcp-ai-tool-record-crm-reply.php` (new)

**Contracts**

- Slug `record_crm_reply`; capability `edit_posts`.
- Args: `email` (required unless `lead_id`/`deal_id`), `snippet` (capped 500),
  `sentiment` (enum), `received_at` (ISO 8601), `lead_id`, `deal_id`,
  `advance_deal` (bool, default false), `notes`.
- Behaviour: resolves the lead by `lead_id` or by normalized email (identity helper);
  stores `last_email_received` / `last_email_snippet` / `last_email_sentiment`
  on the lead **and** on every open deal of that lead.
  When `deal_id` + `advance_deal`: moves the deal to `next_open_stage()` with
  `source = 'email_reply'` (closed deals are never advanced). Audits `reply_recorded`.
- This is the deterministic core the future Gmail-poll workflow rule will call.

**Tests:** lead-only; lead+deal signal spread; advance moves one open stage with
`email_reply` source; closed deal not advanced; unknown email → not-found error.

---

## WP6 — Handover bundle tool (JobNavigator `prep` bundle)

**Files**

- `addons/pro/includes/tools/crm/class-wp-mcp-ai-tool-get-crm-handover.php` (new)

**Contracts**

- Slug `get_crm_handover`; capability `edit_posts`; read-only (`database-read` flag).
- Args: `entity` (`lead`|`deal`, default `deal`), `entity_id` (required),
  `include` (array subset of `lead,company,deals,activities,history`, default all).
- Returns `{ text, sections }` where `text` is a plain-text bundle (no LLM call):
  entity facts, stage history with time-in-stage, BANT scores, last email signal,
  company profile, recent activities (capped 20), open proposals, and the
  settings-driven closing ask (`handover_ask`, default
  "Summarize this record and propose the next action.").
- Escapes everything at exit; numbers formatted via `WP_MCP_AI_CRM_Engine::format_currency`.

**Tests:** lead bundle contains email + BANT; deal bundle contains history +
ask; `include` subset respected; missing entity → 404 error; no LLM dependency.

---

## WP7 — Pipeline digest tool (JobNavigator Telegram digest)

**Files**

- `addons/pro/includes/tools/crm/analytics/class-wp-mcp-ai-tool-get-pipeline-digest.php` (new)

**Contracts**

- Slug `get_pipeline_digest`; capability `edit_posts`; read-only.
- Args: `stale_days` (int, default from settings `stale_deal_days` = 14),
  `include_hot_leads` (bool, default true), `max_rows` (default 20).
- Returns `{ text, data }`: per-stage counts/value (open stages), total pipeline
  value, stalled deals (stage unchanged ≥ `stale_days`, `stage_changed_at`-aware
  with `updated_at` fallback), hot leads (`lead_score` ≥ `hot_score_threshold`),
  overdue tasks (activities `due_date` < today, status not done).
- `text` is a compact digest an agent can forward to Telegram/WhatsApp MCP tools.

**Tests:** counts/value correct; stalled detection uses `stage_changed_at`;
overdue tasks found; `text` non-empty; empty pipeline → success with zeros.

---

## WP8 — Tracked proposal links (JobNavigator tracer)

**Files**

- `addons/pro/includes/tools/crm/deals/class-wp-mcp-ai-tool-create-tracked-link.php` (new)
- `addons/pro/includes/tools/crm/class-wp-mcp-ai-crm-link-tracker.php` (new)
- `addons/pro/includes/tools/crm/init.php` (edit — wire `template_redirect` + `query_vars`)

**Contracts**

- Slug `create_tracked_link`; capability `edit_posts`.
- Args: `deal_id` (required), `url` (required, `esc_url_raw`), `label`.
- Generates an unguessable token (`wp_generate_password( 24, false )`);
  stores on the deal `tracked_links` (array of `{ token, url, label, created_at, opens }`)
  and in a non-autoloaded site option registry (`wp_mcp_ai_crm_link_registry`,
  token → deal_id) for O(1) open lookup.
- Returns the public URL `home_url( '/?nvoos_track=' . $token )`.
- `WP_MCP_AI_CRM_Link_Tracker`:
  - `query_vars` filter adds `nvoos_track`; `template_redirect` handler looks the
    token up, increments `opens` + `last_opened_at` (both registry and deal meta),
    audits `tracked_link_opened`, then `wp_safe_redirect( $url )` with 302.
  - Unknown/missing token → silent 404 fall-through (no information leak).
  - Registered only when the CRM toolkit is enabled.
- Open-tracking is a **sales signal**, not a security feature: tokens are the
  only protection and URLs are stored by authorized users only.

**Tests:** tool creates token + registry entry; tracker increments opens and
redirects (capture output / `wp_redirect` filter); unknown token does nothing;
query var registered.

---

## WP9 — Delete-deal cascade (release the lead)

**Files**

- `addons/pro/includes/tools/crm/deals/class-wp-mcp-ai-tool-delete-deal.php` (edit)

**Contracts**

- Before deletion, capture `lead_id` + stage. After deletion:
  - If the deleted deal was `closed_won` and the lead exists and its
    `lifecycle_stage` is `customer`, count remaining won deals for the lead;
    when zero, revert `lifecycle_stage` to `opportunity`, audit `lead_released`.
  - Fire `wp_mcp_ai_crm_deal_deleted` (with lead release info) for workflow rules.
- Non-won deals: lead untouched (it may have other deals).

**Tests:** won-deal delete with no other won deals releases the lead; a second
won deal prevents release; non-won delete leaves the lead alone; hook fires.

---

## WP10 — Docs, registration, tool surface, full validation

**Files**

- `addons/pro/mcp-ai-wpoos-pro.php` (edit — register 6 new tools in the CRM map)
- `addons/pro/includes/tools/crm/README.md` (edit — module map, public surface, settings, hooks)
- `addons/pro/tests/tools/crm/test-crm-toolkit.php` (edit — `$tool_groups`)
- `tests/pro/tools/crm/test-crm-jobnavigator-adoption.php` (new — WP1–WP9 coverage)
- `.agents/skills/design-crm/SKILL.md` (edit — document new tools/fields so the
  operating skill matches reality)

**Validation gates (in order)**

1. `php -l` on every touched file.
2. `phpcs --standard=phpcs.xml.dist` on changed files (CI counts errors).
3. Targeted PHPUnit: `tests/pro/tools/crm/` + `addons/pro/tests/tools/crm/test-crm-toolkit.php`
   on WP 6.9, then WP 7.1 (never concurrently).
4. Full `tests/pro` prefix run to catch singleton/hook interference.

---

## Explicitly deferred (out of scope for this branch)

- **Light/full BANT scoring depths + provider fallback** — touches the provider
  router and scoring pipeline; requires its own design pass (tracked as follow-up).
- **Gmail poll → workflow-rule auto-advance** — `record_crm_reply` (WP5) is the
  deterministic core; the polling cron + rule wiring is a Phase F item.
- **Scheduled digest delivery** — `get_pipeline_digest` (WP7) is the data source;
  delivery is composed with Pro Schedule Manager + Telegram/WhatsApp MCP tools
  (skill-level orchestration, no code).
- **Content Graph Pro port** — runs afterwards via the ecosystem-port loop
  (byte-identical port + dual-matrix validation).
