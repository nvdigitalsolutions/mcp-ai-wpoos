# Implementation Plan 089 — E-Commerce & Inventory Toolkits → Complete Console Surface Port

**Status:** Ready for scheduling (slots into the Proposal 084 migration tracker)
**Date:** 2026-10-25
**Proposal:** `docs/project/proposals/089-ecommerce-toolkits-console-surface-port-proposal.md`
**Tracker:** `docs/project/plans/nvoos-complete-console-admin-migration-implementation.md`

This plan extends the 084 phase machinery (its invariants apply unchanged) with
commerce-specific phases. Phase letters continue 084's numbering: **2b**
(settings), **3c** (sync ops), **4e/4f** (records/dashboards), **5b**
(retirement), **6b** (deep-links). Every phase respects:

- REST-first (no new admin-ajax); `permission_callback` everywhere.
- Two-bundle rule: every spa-v2 change rebuilds `addons/pro/assets/spa-v2/assets/dist/`
  **and** `examples/nvoos-complete-velocity/dist/` (`npm run sync:dist`).
- Kill-switch filters (`wp_mcp_ai_admin_page_enabled_{slug}`) before any
  wp-admin page is retired — never before parity tests pass.
- Capability-driven nav via `nav-features.tsx` + `/mcp-ai/v1/features`.
- Canonical envelope + two-gate sanitisation in any tool-adjacent PHP.

## Phase 0 — Preflight (no code; do first)

- [ ] Confirm toolkit option-group keys + enable helpers for the four
      toolkits (read each `addons/pro/includes/tools/{ecommerce,erp-ezuite,
      shopify-sync,flowhub}/init.php`); record them in the proposal §5.3
      table.
- [ ] Confirm product/post/page research CCT/CPT names (P7 base classes) for
      the records entity map.
- [ ] Resolve Proposal 089 open questions 1–3 and 5 (FlowHub MCP vs REST,
      Shopify connection type, `/content` capability, EZuite direction).
- [ ] Create tracker rows in the 084 implementation plan for phases 2b, 3c,
      4e, 4f, 5b, 6b.

## Phase 2b — Commerce settings schemas (T3) — highest leverage, first

Goal: the four toolkit settings pages become schemas served by the existing
`mcp-ai/v1/settings` engine; one React form renders them; feature flags gate
the nav.

**PR 2b.1 — Feature flags**
- Add `ecommerce`, `ezuite`, `shopify_sync`, `flowhub`, `content_records` to
  `WP_MCP_AI_Feature_Flags::all()` wired to each toolkit's enable helper.
- Tests: `tests/test-feature-flags.php` — enabled/disabled matrix per option
  value; feature absent from `/mcp-ai/v1/features` when toolkit off.

**PR 2b.2 — E-Commerce settings schema (first schema is the template)**
- Publish the ecommerce option group through the settings schema registry;
  include `safety_buffer` (default 2, range 0–10), per-channel rate caps,
  reconciliation cadence defaults (active 6–12 h, full 24 h — §4.1).
- Fields: text/select/number/boolean/masked-secret via the B1 renderer;
  secrets masked on read (#7157 precedent).
- Kill-switch filter for `class-wp-mcp-ai-ecommerce-settings-page.php`.
- Tests: PHPUnit schema shape + masked-secret round-trip; vitest form render
  from schema; a11y (axe + jsx-a11y).

**PR 2b.3 — EZuite, Shopify Sync, FlowHub schemas (config-only repetition)**
- Same pattern; FlowHub schema defaults read-mostly and adds compliance
  deep-link fields (T6). Shopify credentials masked; "Manage credentials"
  deep-links wp-admin until Phase 5b.
- Kill-switch filters for the three settings pages.
- Tests: schema presence per toolkit; enable-toggle gating.

**Acceptance (Phase 2b):** all four toolkit settings editable in the Console
under `/ecommerce|ezuite|shopify-sync|flowhub` → Settings tab; values round-trip;
no secret appears unmasked in any response; wp-admin pages still available
(filters on by default).

## Phase 3c — Sync-run monitor (T2 → REST)

Goal: read-only sync receipts per channel, backed by the existing sync service
classes (no logic forks).

**PR 3c.1 — `mcp-ai-pro/v1/commerce/sync-runs`**
- READABLE endpoints: list runs per toolkit/channel (paged), get run detail
  (idempotency key, started/finished, result, drift summary, audit-log link).
- Wrap the sync service methods; no new writes from the console surface.
- Permission: `manage_options` (read) — no state-changing routes in this PR.

**PR 3c.2 — Sync tab on each commerce page**
- Site picker (local first) + last-sync per channel + "Reconcile now"
  (confirm-gated; fires the existing sync tool path idempotently).
- Tests: vitest rendering + last-sync freshness states; PHPUnit endpoint
  contract (envelope, paging, capability denial).

**Acceptance (Phase 3c):** an operator can see per-channel sync freshness and
receipts in the Console without wp-admin; a denied user gets 403-shaped errors;
reconcile is confirm-gated and idempotent.

## Phase 4e — Commerce + content records (T4, config-only)

Goal: product/post/page research data visible as RecordsPage groups; edit
actions deep-link wp-admin (T6).

**PR 4e.1 — Records entity map + REST**
- Add `product` (group `commerce`) and `post`, `page` (group `content`) to the
  `mcp-ai-pro/v1/records` entity map, pointing at the R&A CCT/CPT names.
- Read-mostly: list/filter/detail; writes only where the R&A service already
  exposes them, still capability-checked.

**PR 4e.2 — Console routes + nav**
- `nav-features.tsx`: Commerce group (`/ecommerce`, `/ezuite`,
  `/shopify-sync`, `/flowhub`) and Content group (`/content`).
- `ConsoleShell.tsx`: `RecordsPage group="commerce"` on the toolkit routes'
  Records tab (or a shared CommerceRecords route) and `RecordsPage
  group="content"` at `/content`.
- "Edit in wp-admin" deep-link affordance per record (Phase 6b wires the
  branded breadcrumbs).
- Tests: route guards per capability; vitest RecordsPage group rendering.

**Acceptance (Phase 4e):** research records for products/posts/pages are
browsable in the Console; editing stays in wp-admin via deep link; feature
flags hide groups when toolkits are off.

## Phase 4f — Commerce dashboard widgets (T4, configurable dashboard)

Goal: EZuite/Shopify/FlowHub widget parity (the three
`*-dashboard-widget.php` pages) as dashboard configs, not code.

**PR 4f.1 — Widget configs for the dashboards controller**
- Port each widget's data source through `mcp-ai-pro/v1/dashboards` as
  widget definitions (F&B precedent), served from plugin-side transients
  with TTL to respect Shopify/FlowHub rate limits (§6).
- FlowHub widgets read-only; no compliance-reporting widgets (T6).

**PR 4f.2 — Overview tabs on the four commerce pages**
- Overview = dashboard grid (site-picker scoped) + sync freshness summary
  (Phase 3c) + quick actions.
- Tests: widget payload contract; vitest grid; empty/error states.

**Acceptance (Phase 4f):** the three wp-admin dashboard widgets are retired
(filter-gated) after console parity; console Overview shows the same metrics
per selected site.

## Phase B — Connection-aware pages (Remote Sites)

Goal: every commerce page reads against a selected Remote Site when the
operator picks one (proposal §3.3). Reads only in this phase.

**PR B.1 — `connection_id`/`site_id` on records + dashboards + sync-runs**
- Optional `remote_site` param on the three endpoints; resolve through
  `WP_MCP_AI_Remote_Connection`; reject unknown/disconnected sites with a
  structured error; never echo connection credentials.
- Extend `/mcp-ai-pro/v1/remote-sites` if a `type=shopify` variant is chosen
  (open question 2) with `/test` parity.

**PR B.2 — Site picker component**
- spa-v2 `SitePicker` (select + connection health dot) on each commerce page;
  persists selection per page in localStorage (`nvoos-console.site.*`).
- Tests: picker states (loading/offline/ok); vitest; cross-site error
  surfacing.

**Acceptance (Phase B):** operator can flip between the local site and any
healthy Remote Site on commerce pages; a dead connection shows a health
error, not a silent empty page.

## Phase 5b — Retirement (kill-switch flip) + write-surface decision

- [ ] Flip the four settings-page filters and the three dashboard-widget
      filters to off **per customer cohort** after parity verification
      (084 Phase 5 discipline).
- [ ] Decide money-moving console surfaces (refund/order mutation) per
      proposal §4.2 — expected: stay chat-only via the destructive-ops gate
      (Major Build "money-moving last"); record the decision in the tracker.
- [ ] Delete admin-ajax handlers only after their last WP consumer is gone;
      record subtractions.

## Phase 6b — T5/T6 deep-links

- [ ] Branded breadcrumb deep-links from commerce/content pages into
      proxied wp-admin (WooCommerce screens, FlowHub compliance reports,
      Gutenberg for posts/pages) with a "back to Console" affordance
      (084 Phase 6 pattern).

## Cross-phase acceptance gates

| Gate | Command / check |
|---|---|
| PHP lint + standards | `composer run lint` (both standards; toolkit-audit rules) |
| PHPUnit (affected files) | `vendor/bin/phpunit tests/test-feature-flags.php tests/test-<controller>.php` |
| SPA typecheck | `cd examples/nvoos-complete-velocity && npm run typecheck` |
| SPA tests | `npm test` (vitest; new page/component suites) |
| A11y | `npm run lint:a11y` (axe + jsx-a11y) |
| Two-bundle rebuild | `npm run build` in spa-v2 package + `npm run sync:dist` in the console (both dists committed) |
| REST contract | wp-rest-api skill checklist: permission_callback on every route, envelope shape, 403 shape, no raw rows |
| Parity before retirement | kill-switch filter exists and is default-on until the gate passes |

## Effort sketch (for scheduling against the 084 tracker)

| Phase | PRs | Effort | Notes |
|---|---|---|---|
| 0 Preflight | — | S | decisions only |
| 2b Settings | 3 | M (first), S (rest) | highest leverage — one renderer, four schemas |
| 3c Sync monitor | 2 | M | wraps existing services |
| 4e Records | 2 | M | config-only additions to a shipped engine |
| 4f Dashboards | 2 | M | config-only; F&B precedent |
| B Connections | 2 | M | first cross-site page pattern in the Console |
| 5b Retirement | — | S | per-cohort filter flips |
| 6b Deep-links | 1 | S | 084 Phase 6 machinery reuse |

Suggested order: **0 → 2b → 3c → 4e → 4f → B → 5b → 6b** (read-only value
first, money-moving last, per §4.2).
