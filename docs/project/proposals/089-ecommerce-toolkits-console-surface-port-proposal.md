# Proposal 089 — E-Commerce & Inventory Toolkits → Complete Console Surface Port

**Status:** Proposal (awaiting review)
**Date:** 2026-10-25
**Relates to:** Proposal 084 §5/§6 (P6, P7, P8, P15, B24) · Proposals 036–038 (Woo tools) · FLOWHUB-INVENTORY-SYNC-* · SHOPIFY-SYNC-* · Proposal 041 (Remote Sites) · Proposal 078 (F&B ingestion — the precedent)
**Author:** NV oOS coding agent (Zed)

---

## 0. Executive verdict — what is and is not ported today

**The tool surface is ported. The console UI surface is not.**

The Complete Console (`examples/nvoos-complete-velocity`, deployed at
console.nvoos.cloud) proxies the Complete plugin's full REST tool registry,
so every one of the four commerce toolkits is already callable from the
Console **via chat and the `/tools` page**:

| Toolkit | Location | Tools today |
|---|---|---|
| E-Commerce (WooCommerce + Shopify catalog + QuickBooks + Printful) | `addons/pro/includes/tools/ecommerce/` | 24 tools per README (products, orders, customers, inventory, marketing; Shopify catalog/customers/inventory/orders) |
| EZuite ERP | `addons/pro/includes/tools/erp-ezuite/` | `ezuite_erp`, `ezuite_erp_get_products`, `ezuite_inventory`, `ezuite_settings`, `ezuite_sync` |
| Shopify Sync | `addons/pro/includes/tools/shopify-sync/` | `shopify_sync_analytics/inventory/orders/products/settings` + connection resolver trait |
| FlowHub | `addons/pro/includes/tools/flowhub/` | `flowhub_analytics/inventory/locations/products/settings/sync` + alert manager; base also ships `flowhub_get_products`, `flowhub_manage_product` |
| Remote Connections (cross-site) | `addons/pro/includes/tools/remote-connections/` | `remote_wp_connection`, `remote_shopify_connection` |
| Base WooCommerce tools | `includes/tools/` | `create/get_woo_products`, `get_woo_recent_orders`, `scrape_product`, etc. |

**What has NOT been ported to the Console** (still wp-admin-only):

1. **Four top-level toolkit menus** — `class-wp-mcp-ai-ecommerce-settings-page.php`,
   `-ezuite-toolkit-settings-page.php`, `-flowhub-toolkit-settings-page.php`,
   `-shopify-sync-toolkit-settings-page.php`, plus three dashboard widgets
   (`ezuite-`, `flowhub-`, `shopify-sync-dashboard-widget.php`).
2. **Product/post/page Research & Add and Consolidate pages** —
   `product-research-page.php`, `product-settings-page.php`,
   `product-consolidate-page.php`, `post-*`, `page-*` (the P7/P8 family).
3. **No console routes exist** for any of the above. The route catalogue
   (`examples/nvoos-complete-velocity/src/console/nav-features.tsx`) has
   Dashboard, Chat, Assistants, Blueprints, Tools, Paper Store, Workflows,
   Schedules, Triggers, Slash Commands, Approvals, Operations, Pro Ops,
   Orchestration, CRM, Projects, Professions, Teams, Calendar, F&B, Channels,
   Connections, Media Ops, Dashboards, Analytics, Diagnostics, Security,
   Tokens, Portability, Knowledge Graph, Docs, Media Studio, Documents,
   Settings — **no `/ecommerce`, `/ezuite`, `/shopify-sync`, `/flowhub`,
   `/posts`, `/pages`**.
4. **No feature flags** exist for the four toolkits
   (`includes/class-wp-mcp-ai-feature-flags.php` only ships
   `docs_hub`, `knowledge_graph`, `media_studio`, `document_editor`, `pro`,
   `okf`, `fnb`, `crm`, `project_management`, `calendar_booking`), and the
   Pro records REST controller (`mcp-ai-pro/v1/records`) serves groups
   `crm`, `pm`, `professions`, `calendar`, `fnb` only.

Proposal 084's gap matrix already queues exactly these surfaces — **P6**
(toolkit settings, "~20: ecommerce, ezuite, flowhub, shopify, …", tier T3),
**P7/P8** (Research & Add / Consolidate families, tier T4), **P15**
(repeating dashboards, tier T4), **B24** (WooCommerce plugin integration
screens, tier T5 — stays in wp-admin by design).

## 1. Problem statement

An NV oOS Complete customer running a multi-channel commerce operation
(Woo store + Shopify storefront + FlowHub dispensary POS + EZuite ERP) gets
the full tool surface in chat, but every management surface — connection
credentials, sync configuration, sync-run monitoring, product research,
catalog views — requires leaving the Console for wp-admin. The Console's
value proposition (Proposal 083: one surface for the Complete bundle) is
half-delivered for commerce customers. Additionally, the Console is
increasingly a **hub** for multiple sites (Remote Sites shipped #7220):
commerce customers expect to monitor and operate *several* store sites from
the one console, not one wp-admin per site.

## 2. Scope decisions (what the Console will and won't own)

Follow the 084 boundary discipline. The Console **will** own:

- **Settings & configuration** for the four toolkits (T3, schema-driven).
- **Connection management** for remote WP + Shopify stores — consume the
  existing `/connections` page; add a site picker to commerce pages.
- **Read-mostly monitoring**: sync runs/status, inventory drift, low-stock,
  analytics/dashboard widgets (T4, configurable dashboard feature).
- **Records views** for product/post/page research data (T4, RecordsPage
  groups) with "Edit in wp-admin" deep-link affordances.

The Console **will not** own:

- WooCommerce core screens (product/order editing, WC settings) — T6, stays
  wp-admin/Gutenberg; deep-linked from the Console (Phase 6).
- FlowHub/Metrc compliance reporting — FlowHub's own surface is the system
  of record; the Console is read-only against it (see §4.3).
- Anything money-moving without the existing destructive-ops gate +
  explicit human confirm — see §4.2.

## 3. Current-state inventory (verified 2026-10-25)

### 3.1 Console-side building blocks that already exist

| Block | Where | Reuse for this proposal |
|---|---|---|
| Schema-driven settings engine | `mcp-ai/v1/settings/schema` + `/values` (shipped 2.4.0, 084 B1) | Host the four toolkit option groups as schemas — **config, not code** (084 Phase 2) |
| Configurable Records feature | `mcp-ai-pro/v1/records` (entity map → group) + spa-v2 `features/records/RecordsPage` | Add `product` / `post` / `page` entities; groups `commerce` + `content` |
| Configurable dashboards | `mcp-ai-pro/v1/dashboards` (pro-dashboards controller) | F&B already proved this path; commerce dashboards are config |
| Remote Sites REST | `mcp-ai-pro/v1/remote-sites` (+ `/types`, `/{id}`, `/{id}/test`) | Site picker + per-connection commerce reads |
| Console Connections page | spa-v2 `features/remote-sites/RemoteSitesPage.tsx` (`/connections`, #7220) | Extend with commerce-specific connection types if needed |
| Remote connection manager | `addons/pro/includes/class-wp-mcp-ai-remote-connection.php` | Route cross-site commerce REST reads through it |
| Feature-gated nav | `nav-features.tsx` + `GET /mcp-ai/v1/features` | Add 4 commerce keys + a `content_records` key |
| F&B precedent | `/fnb` = `RecordsPage group="fnb"` + feature flag + 078 ingestion REST | The exact pattern to copy for each toolkit |

### 3.2 Toolkit option/settings state (source of the schemas)

The four toolkit settings pages extend `WP_MCP_AI_Toolkit_Settings_Base`
(Overview / Configuration / Tools Management / Research & Add / Help tabs).
The schema-driven port must:

- publish each toolkit's option group through the settings schema registry
  (`mcp-ai/v1/settings/schema`), reusing the B1 engine, **not** a new
  renderer;
- keep secrets masked on read (084 invariant 3);
- surface the toolkit enable toggles as feature flags
  (`ecommerce`, `ezuite`, `shopify_sync`, `flowhub`) wired to the existing
  enable helpers (e.g. `wp_mcp_ai_is_ecommerce_toolkit_enabled()`).

### 3.3 Cross-site operation (Remote Sites)

Commerce customers manage several storefronts. Today:

- `remote_wp_connection` and `remote_shopify_connection` tools already let
  chat operate cross-site;
- the paper-store MCP tools already accept `connection_id` — the
  connection-aware tool pattern is established;
- the console has no connection-aware *pages*.

This proposal adds one design rule: **every commerce console page carries a
site selector** (default: this site; options: Remote Sites connections).
Phase A ships pages local-site-first; Phase B makes them connection-aware
by passing `connection_id` through the records/dashboards/sync REST
endpoints and resolving via `WP_MCP_AI_Remote_Connection`.

## 4. Research & industry standards

### 4.1 Inventory-sync engineering standards (applies to the sync surfaces)

- **Webhooks + a reconciliation poll.** Wire all channel webhooks, then add
  a reconciliation poll "sized to your velocity and to the webhook-silent
  states" — the poll is the safety net (Digital Applied, 2026). Console
  implication: expose *last sync time per channel* and a **Reconcile now**
  action; never rely on webhook-only freshness.
- **Idempotency is mandatory.** Webhook/queue delivery is at-least-once in
  practice; without idempotency protection you get duplicate bookings and
  race conditions (XICTRON, 2026; ECOSIRE, 2026). Console implication:
  sync-run endpoints are idempotent (idempotency key per run); the UI shows
  run receipts, not fire-and-forget.
- **Safety buffers.** Hold back a configurable 1–3 units per SKU (nventory,
  2026; CodeSolTech, 2026) or 5–10% of stock (Techspawn, 2026) to absorb
  inter-channel disagreement. Console implication: `safety_buffer` is a
  first-class field in the sync settings schema with a sensible default.
- **SKU canonicalization.** One master SKU per product or an explicit
  mapping table; normalize units of measure; define stock states
  (reserved/available/inbound/damaged) up front (Racklify, 2026). Console
  implication: the sync monitor renders state by the canonical vocabulary,
  and the settings schema owns the mapping table.
- **Reconciliation cadence.** Full reconciliation every 6–12 h for active
  SKUs, 24 h for the full catalog (ECOSIRE, 2026). Console implication:
  defaults in the schedule fields of the sync settings schema.
- **Audit everything.** "Every stock change, order import, and webhook event
  is logged" (nventory, 2026). The plugin's audit logger covers this; the
  console surface links run receipts to audit entries.

### 4.2 Agentic-commerce console standards (applies to page design)

- **Human approval before apply.** Shopify Sidekick "presents changes for
  your review before applying them" (MESA, 2026; Moshel, 2026). Console
  implication: commerce pages default to read-mostly; every mutating action
  is an explicit, capability-checked confirm — no background writes.
- **Guardrails in code, not prompts.** Anthropic's Commerce Agents
  blueprint: the merchant agent's "every proposed change is staged behind
  human approval" and guardrails are enforced in code (Coursiv, 2026).
  Console implication: the existing destructive-ops gate +
  `permission_callback` discipline (084 invariant 1) is the enforcement
  layer; the UI only mirrors it.
- **Scoped agent capability.** Cap discount amounts, restrict data access,
  approved messaging templates (Shopify, 2026). Console implication: sync
  settings include per-channel caps (e.g. max price-change % per run).
- **Money-moving last.** "You do not build all six at once. Sequence by
  return against risk, and put the money-moving ones last so your
  guardrails are proven" (Major Build, 2026). Console implication: phase
  order below ships read-only monitoring and settings first; refund/order
  mutation surfaces stay chat-only (tool + gate) until Phase 5b.

### 4.3 FlowHub compliance reality (applies to the FlowHub page)

FlowHub is a cannabis POS: first Metrc API integration (2015), compliance
across 36 US states via Metrc/BioTrack, compliance reporting built into the
core platform (flowhub.com, 2026). FlowHub also exposes open APIs and a
**FlowHub MCP** for AI assistants. Console implications:

- The console surface is **read-mostly by default** against FlowHub —
  inventory/location/product reads and sync-run status only.
- Any write (adjustments, receives) must be capability-gated, confirm-gated,
  and audit-logged, because state traceability (Metrc/BioTrack) is legally
  binding; the Console must never be able to silently fabricate inventory.
- Compliance *reporting* stays in FlowHub (T6); the console deep-links it.
- Flag the MCP possibility as an open question (§7) — FlowHub publishes an
  MCP server; wiring it as an MCP App on the assistant (Proposal 041
  connection type) may be cheaper than REST duplication.

### 4.4 Migration methodology

Proposal 084 §2 already researched this: strangler-fig incremental
migration, WordPress core's own React admin precedent, REST over admin-ajax,
server-enforced capability-driven nav, and "keep the platform-admin in the
platform". This proposal is a child of 084 and reuses its tier model,
invariants, and phase machinery verbatim.

## 5. Proposed console surface

### 5.1 Nav additions (`nav-features.tsx`)

New group **Commerce** (collapsible, after Command Centers):

| Path | Label | Gate |
|---|---|---|
| `/ecommerce` | E-Commerce | `manage_options` + feature `ecommerce` |
| `/ezuite` | EZuite ERP | `manage_options` + feature `ezuite` |
| `/shopify-sync` | Shopify Sync | `manage_options` + feature `shopify_sync` |
| `/flowhub` | FlowHub | `manage_options` + feature `flowhub` |

New group **Content** (after Commerce):

| Path | Label | Gate |
|---|---|---|
| `/content` | Content (posts/pages) | `edit_posts` + feature `content_records` |

Each commerce page is a composite: **site picker** (local + Remote Sites) →
**tabs**: Overview (dashboard widgets + sync status) · Settings
(schema-driven form) · Records (toolkit-specific records) · Sync runs
(read-only receipt log) · wp-admin deep-links.

### 5.2 Tier mapping (084 difficulty model)

| # | Surface | Tier | Notes |
|---|---|---|---|
| C1 | E-Commerce toolkit settings (option group) | T3 | Schema in the B1 engine; masked keys; buffer/cap fields per §4.1–4.2 |
| C2 | EZuite toolkit settings | T3 | ditto |
| C3 | Shopify Sync toolkit settings (+ Shopify credentials) | T3 + T5-lean | Credentials masked; "Manage" deep-links wp-admin until Phase 5b (secrets UX precedent: key rotation B2) |
| C4 | FlowHub toolkit settings | T3 | Read-mostly defaults; compliance deep-links (T6) |
| C5 | Product research records + consolidate | T4 | `mcp-ai-pro/v1/records` entities `product`; group `commerce` (P7/P8 config) |
| C6 | Post/page research records | T4 | Entities `post`, `page`; group `content`; edit deep-links to Gutenberg (T6) |
| C7 | EZuite/Shopify/FlowHub dashboard widgets | T4 | Configurable dashboard feature (P15); F&B precedent |
| C8 | Sync-run monitor (per channel) | T2→REST | Wrap existing sync service classes in a read-only REST controller; idempotency keys + audit links (§4.1) |
| C9 | Cross-site targeting (connection-aware pages) | + | Phase B: `connection_id` on records/dashboards/sync endpoints via `WP_MCP_AI_Remote_Connection` |
| C10 | WooCommerce core screens (products/orders/settings) | T6 | Never migrated; Phase 6 deep-links (B24) |
| C11 | FlowHub Metrc/BioTrack reporting | T6 | FlowHub's own surface; deep-link only |

### 5.3 Feature flags

Add to `WP_MCP_AI_Feature_Flags::all()`:

```php
'ecommerce'      => self::toolkit_flag( 'enable_ecommerce_toolkit' ),
'ezuite'         => self::toolkit_flag( 'enable_ezuite_toolkit' ),
'shopify_sync'   => self::toolkit_flag( 'enable_shopify_sync_toolkit' ),
'flowhub'        => self::toolkit_flag( 'enable_flowhub_toolkit' ),
'content_records'=> self::records_flag( 'posts_pages' ), // edit_posts-gated surface
```

(Option keys to be confirmed against each toolkit's `init.php` enable
helper — the ecommerce helper `wp_mcp_ai_is_ecommerce_toolkit_enabled()`
exists today.)

## 6. Risks & mitigations

| Risk | Mitigation |
|---|---|
| Secrets leak through schema-driven settings | 084 invariant 3: masked on read; write-back only when a new secret is submitted (the #7157 precedent) |
| Destructive commerce actions from the console | Read-mostly default; confirm gates; existing destructive-ops gate + capability checks; money-moving stays chat-only until Phase 5b (§4.2) |
| Metrc/BioTrack compliance mis-step via FlowHub writes | Read-mostly default; writes gated + audited; reporting stays in FlowHub (T6) |
| Stale inventory shown after webhook gaps | Last-sync timestamps + Reconcile now + schedule defaults (6–12 h active / 24 h full) (§4.1) |
| Cross-site reads break BFF cookie auth | Remote Sites already solved: `/{id}/test` + connection manager; console pages only *read* through existing connection credentials; no credential echo |
| Shopify/FlowHub rate limits from dashboard widgets | Widget data served from plugin-side caches/transients with TTL; per-channel rate caps in settings schema |
| Scope creep into a "full POS/ERP UI" | Explicit T6 boundary in §2; 084 kill-switch filters keep wp-admin parity until verified |

## 7. Open questions

1. **FlowHub MCP vs REST** — FlowHub publishes an MCP server. Should the
   FlowHub page consume plugin REST (parity with other toolkits) or wire
   FlowHub MCP as an assistant MCP App and make the console page a thin
   status surface? Decision needed before C7 (FlowHub dashboard).
2. **Connection types for Shopify** — `remote_shopify_connection` stores
   Shopify creds; should the Console Connections page add a "Shopify"
   connection type (schema + test), or reuse the WP remote-site record with
   a `type=shopify` field? Proposal 041's type registry is the precedent.
3. **Content group name/cap** — `/content` under `edit_posts` vs
   `manage_options`; confirm the product-research CCT names for the records
   entity map (P7 stores CPT/CCT dual).
4. **Multi-site write-through** — Phase B reads are safe; should any
   console surface ever *write* cross-site (e.g. push a price sync to a
   remote store), and if so under which gate?
5. **EZuite ERP scope** — confirm whether EZuite sync is one-directional
   (ERP → Woo) or bidirectional before defining the sync-run monitor schema.

## 8. References

External (industry):

- Digital Applied — Multichannel Inventory Sync 2026: A Decision Matrix. https://www.digitalapplied.com/blog/ecommerce-inventory-sync-multichannel-2026-decision-matrix
- XICTRON — Real-Time Multi-Channel Inventory Sync: Event-Driven 2026. https://www.xictron.com/en/blog/real-time-inventory-sync-multi-channel-2026/
- ECOSIRE — Real-Time Inventory Sync Architecture: Webhooks, Queues & Conflict Resolution. https://ecosire.com/blog/real-time-inventory-sync-webhooks-queues
- nventory — Multichannel Inventory Management: Complete 2026 Guide. https://nventory.io/blog/multichannel-inventory-management
- Techspawn — Multichannel Inventory Management: The Operator's Playbook '26. https://techspawn.com/multichannel-inventory-management-in-2026-the-complete-operators-playbook/
- CodeSolTech — Real-Time Inventory Sync Techniques. https://www.codesoltech.com/blog/real-time-inventory-sync-techniques/
- Racklify — How To Implement Inventory Sync For Multi-Channel Fulfillment. https://racklify.com/encyclopedia/how-to-implement-inventory-sync-for-multi-channel-fulfillment/
- MESA — What Is a Shopify AI Agent? https://www.getmesa.com/blog/what-is-a-shopify-ai-agent-and-do-you-need-one
- Moshel — How Shopify Built Sidekick. https://medium.com/@jakemoshel/how-shopify-built-sidekick-the-ai-agent-redefining-e-commerce-1b946a36df01
- Shopify — What Are AI Agents? (2026). https://www.shopify.com/blog/ai-agents
- Coursiv — Claude Commerce Agents: Anthropic's Open-Source Blueprint. https://coursiv.io/blog/claude-commerce-agents
- Major Build — AI Agents for Ecommerce: 6 Operational Workflows. https://www.major.build/blog/ai-agents-for-ecommerce
- FlowHub — Cannabis POS / Dispensary Software / Metrc. https://www.flowhub.com/ · https://www.flowhub.com/learn/best-dispensary-pos-software

Internal:

- `docs/project/proposals/084-admin-surface-console-migration-gap-analysis.md` (tiers, invariants, phases; P6/P7/P8/P15/B24)
- `docs/project/plans/nvoos-complete-console-admin-migration-implementation.md` (migration tracker)
- `docs/project/proposals/083-nvoos-complete-console-app-proposal.md`
- `docs/project/plans/nvoos-complete-console-app-implementation.md`
- `docs/project/proposals/078-fnb-toolkit-universalisation-proposal.md` + implementation plan (F&B precedent)
- `docs/project/proposals/041-mcp-apps-remote-sites-connection-type.md`
- `docs/project/proposals/036-woo-product-price-qty-update-tools.md` (+ 037, 038)
- `examples/nvoos-complete-velocity/src/console/nav-features.tsx`
- `includes/class-wp-mcp-ai-feature-flags.php`
- `addons/pro/includes/rest/class-wp-mcp-ai-pro-records-rest-controller.php`
- `addons/pro/includes/rest/class-wp-mcp-ai-pro-remote-sites-rest-controller.php`
- `addons/pro/includes/class-wp-mcp-ai-remote-connection.php`
