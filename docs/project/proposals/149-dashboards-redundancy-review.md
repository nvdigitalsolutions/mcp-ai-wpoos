# Proposal 149 — Dashboards redundancy review

**Decision:** S-3 (user, 2026-11-17) — *"ship all of them, but review for redundancy before consolidating to three."*
**Status:** review complete, 2026-11-17; **implemented 2026-11-20** as plan §112. The recommendation became [158](158-parity-open-decisions.md) D1 and shipped as a cross-runtime catalogue change — no code changed at review time, which is what the review was for.
**Evidence base:** `addons/pro/includes/rest/class-wp-mcp-ai-pro-dashboards-rest-controller.php` (the WordPress catalogue, which is the source of truth) and `docker/laravel/app-skeleton/app/Support/DashboardCatalogue.php` (the ported catalogue). Both were read; neither was executed.

---

## 1. The redundancy test

A dashboard is redundant when it answers **the same question over the same shaped data**, differing only in a label or a data source. Three properties are compared:

1. **Filters** — does it slice the same dimensions?
2. **Render spec** — are the metric keys, list keys and column shapes the same?
3. **Audience and gate** — is it the same person asking, under the same toolkit flag?

Two dashboards that differ in only one of these are *similar*. Two that differ in none are *duplicates*. Two that differ only in the metric set are *one dashboard with a selector*.

---

## 2. Inventory — 10 dashboards, not 23

The earlier figure of 23 referred to the plugin's admin **pages**, not dashboards. Both runtimes declare the same ten:

| # | id | Label | Toolkit flag |
|---|---|---|---|
| 1 | `cre` | CRE Debt Portfolio | `enable_cre_debt_toolkit` |
| 2 | `law` | Law Firm | `enable_law_firm_toolkit` |
| 3 | `registration` | Registration | `enable_regulatory_registration_toolkit` |
| 4 | `eca` | ECA | `enable_eca_management` |
| 5 | `health` | Health & Wellness | `enable_health_wellness_management` |
| 6 | `vitals` | Medical Vitals | `enable_medical_vitals` |
| 7 | `ezuite` | EZuite Inventory | `enable_ezuite_toolkit` |
| 8 | `shopify` | Shopify Sync | `enable_shopify_sync_toolkit` |
| 9 | `flowhub` | FlowHub Inventory | `enable_flowhub_toolkit` |
| 10 | `observability` | AI Observability | *(none)* |

Only `eca` is served by the Laravel runtime; the other nine are reported `disabled` with their toolkit flag, which is the same shape WordPress uses for a switched-off toolkit. That split is unchanged by this review.

---

## 3. Findings

### 3.1 `ezuite` ≡ `flowhub` — **duplicate**

Byte-identical render spec in the WordPress controller:
same five metric keys (`in_stock`, `low_stock`, `out_of_stock`, `total_items`, `total_value`), same format list (`number`, `number`, `number`, `number`, `money`), and the same single list with `name` / `count` columns. The only differences are the label of the list ("Top Warehouses" vs "Top Locations") and the data source.

This is the strongest merge case in the set: one panel shape, two connectors.

### 3.2 `health` ≡ `vitals` — **duplicate, one level up**

Identical filter set (`member_id` of type `member`, `days_back` with the same 7/30/90 options), identical structure (empty `metrics`, one `history` list), differing only in columns.

The reason they are separate today is real — they are gated by **different toolkit flags** (`enable_health_wellness_management` vs `enable_medical_vitals`) — but that is a visibility rule, not a surface difference. One "Member metrics" dashboard whose sections are gated per flag preserves the gate exactly.

### 3.3 `shopify` — **partial duplicate**

Shares `cached_items` and the connector-health framing, but its other metrics are GraphQL cost budget (`cost_remaining_pct`, `cost_used`, `cost_limit`) and `connections`. It belongs on the same *dashboard* as the inventory connectors, with its own metric group — not a separate page.

### 3.4 `cre`, `law`, `registration`, `eca` — **similar, not redundant**

All four are "filters + metrics + charts + lists", but they differ in every substantive dimension: filters (`loan_type`/`status` vs none vs none vs `day`/`eca_type`), metrics, audience, and toolkit. Collapsing them would not remove a surface, it would remove a domain.

What they *do* share is the render contract — `filters` / `metrics` / `charts` / `lists` — which is exactly the manifest shape Wave 3 already plans. That is a **renderer** consolidation, not a dashboard one.

### 3.5 `observability` — **unique**

Chat annotation totals and GenAI span counts from the audit log. Different question, different data, no toolkit gate. Nothing overlaps it.

---

## 4. Recommendation

**10 → 7 surfaces.** Two merges, no capability lost:

| New surface | Replaces | Shape |
|---|---|---|
| Connector health | `ezuite`, `flowhub`, `shopify` (3 → 1) | one panel per connection; inventory panels share the exact spec, Shopify adds its budget group |
| Member metrics | `health`, `vitals` (2 → 1) | one dashboard, section visibility driven by the two toolkit flags |
| CRE Debt Portfolio | `cre` | unchanged |
| Law Firm | `law` | unchanged |
| Registration | `registration` | unchanged |
| ECA | `eca` | unchanged |
| AI Observability | `observability` | unchanged |

That is seven surfaces: 1 + 1 + 5. The "three" in the original framing does not survive contact with the data — forcing `cre`, `law`, `registration` and `eca` into one page would produce a page with four mutually exclusive filter sets and four metric groups, which is a navigation menu with a chart in it. Three would be reachable only by deleting domains, not by removing duplication.

---

## 5. Why this was not implemented in the review

Three constraints, each of which makes a Laravel-only change the wrong move:

1. **WordPress is the source of truth.** The catalogue is read by both runtimes and the labels/descriptions must match. Merging only the Laravel catalogue would break parity on the first request.
2. **The gate is already explicit.** `DashboardsTest::test_schema_reports_the_unserved_dashboards_as_disabled` pins the disabled entries and their toolkit flags; changing the set is a deliberate test change, not a side effect.
3. **Saved state.** A dashboard id appears in console URLs and, on WordPress, in dashboard-widget registration. A merge needs a redirect or alias from the retired ids, not just a deletion.

The merge is therefore a **Wave 5 task with a matching WordPress change**, not a port task.

---

## 6. What this changes now

- The contract's `dashboards` row records the finding and the **10 → 7**
  recommendation, so the next person does not re-derive it.
- **Decided 2026-11-20** — [158](158-parity-open-decisions.md) D1 accepts this
  review's §4 as written (two merges, five unchanged surfaces) and adds three
  conditions: alias the retired ids, ship WordPress first in the same change, and
  keep the number at seven. The earlier "10 → 8" figure in this bullet was stale;
  two merges (3 → 1 and 2 → 1) give seven, which is what §4 has always said.
- No `DashboardsTest` change, no catalogue change **yet**. The test pins the
  disabled entries and their toolkit flags; changing that set belongs to the merge
  itself, not to this review.
- **Implemented 2026-11-20** — plan §112. Both catalogues declare seven surfaces;
  the retired ids resolve through an alias map published in the schema envelope;
  the toolkit gates survive as per-section visibility, including the Medical
  Vitals back-compat fallback. `DashboardsTest` moved from nine disabled entries
  to six, and `tests/rest-api/test-rest-dashboards.php` now pins the merged shape
  instead of the ten.
- S-3's other half — whether an unlicensed toolkit *disappears* or *renders an upsell* — remains open and is deferred to Wave 3 with the manifests: absence is an authorisation statement, an upsell is a product surface, and the two should not be decided per-dashboard.
