# Proposal 149 — toolkit manifest schema check (CRM)

**Parent:** [`149-laravel-admin-parity-implementation-plan.md`](./149-laravel-admin-parity-implementation-plan.md) §46 (P4), §56 (the renderer)
**Companion:** [`149-toolkit-manifest-renderer.md`](./149-toolkit-manifest-renderer.md) §8 — "the 25-manifest authoring pass … one domain should be checked before the bulk (recommend `crm`)"
**State:** answered 2026-11-18. One schema addition shipped (`textarea`); two exclusions recorded. **Unblocks:** the 23-manifest authoring pass.

## 1. The question

The renderer (plan §56) made a toolkit a manifest commit, but only two manifests
exist (`commerce`, `documents`) and both have two settings. Before writing ~23
more against a five-type schema (`text`, `number`, `boolean`, `select`,
`secret`), the pass needed one enum-heavy, field-heavy domain checked for a
type the schema cannot express. `crm` was the recommended domain because its
WordPress settings page is the largest toolkit settings surface in the plugin.

## 2. Method

Counted every form control in
`addons/pro/includes/admin/class-wp-mcp-ai-crm-settings-page.php` (1,583 lines;
option `wp_mcp_ai_crm_toolkit_settings` plus
`WP_MCP_AI_CRM_Engine::HYGIENE_OPTION`), then mapped each control to a manifest
setting type and recorded anything with no equivalent. The three small
CPT settings pages (`deal`, `company`, `customer`) were counted too, as a
second sample.

## 3. Inventory

| Control | CRM fields | Manifest type | Verdict |
|---|---:|---|---|
| `<input type="select">` with a static enum | 13 | `select` | ✅ direct |
| `<input type="checkbox">` | 13 | `boolean` | ✅ direct |
| `<input type="number">` | 17 | `number` | ✅ direct |
| `<input type="text">` | 12 | `text` | ✅ direct |
| `<input type="email">` | 1 | `text` | ✅ direct — the manifest describes rendering; validation lives in the engine's cast |
| `<input type="password">` | 4 | `secret` | ✅ direct — masked on read, write-only |
| `<textarea>` | 10 | **none** | ❌ **gap → fixed** |
| `<select>` populated from another store | 3 | **none** | ⛔ **not expressible → excluded** |

The second sample (`deal`, `company`, `customer`) adds no new control types.

## 4. Finding 1 — `textarea` was missing (fixed 2026-11-18)

Ten CRM fields are multi-line: six newline-separated lists (exclude list,
priority list, spam domains, promotional domains, priority domains, spam
keywords), two keyword lists under external sourcing, and one free-text
profile (`ideal_client_profile`, five rows). Rendering any of those as a
single-line `text` input is not a parity gap a manifest should paper over: a
list that cannot be read is a list an operator will not maintain.

**Shipped:**

- `ToolkitManifest::SETTING_TYPES` gains `textarea`.
- Optional `rows` (integer **2–20**), valid **only** on `textarea` — a stray
  `rows` on another type is rejected rather than silently ignored, which is
  the fail-closed rule the rest of the schema follows.
- Panel: `ToolkitPage` renders `Filament\Forms\Components\Textarea`.
- Console: `SettingsScreen.tsx` renders a `<textarea rows={field.rows ?? 4}>`.
- `ToolkitSettingField` (spa-v2) types `rows?: number`.

**Representation:** a list is a newline-separated **string**, which is what the
WordPress form produces (`implode("\n", …)`) and what the engine stores. No
array type, no serialisation scheme.

**One engine fix this exposed:** a generated form hydrates an unset text field
as `null`, and `ToolkitEngine::castSetting()` treated `null` as a type error
("Must be a string"). The console always sent `''`, so only the new panel path
hit it. `null` now casts to `''` for `text`/`textarea`/`secret`, pinned by
`AdminToolkitsTest::test_a_blank_text_setting_saves_as_an_empty_string`.

## 5. Finding 2 — dynamic option sources are not expressible (excluded)

Three CRM selects take their options from **another store**, not from the
manifest:

| Field | Options come from |
|---|---|
| `external_sourcing.upwork.default_connection_id` | configured remote sites |
| `external_sourcing.linkedin.default_connection_id` | configured remote sites |
| `research.research_assistant_id` | published `mcp_ai_assistant` posts |

A manifest is static data. Expressing these needs a **source descriptor**
(`source: remote-sites`, `source: assistants`) plus a resolver, a cache, and
its own authorization question: a manifest cannot be allowed to name an
arbitrary table, and an option list is a read of another estate-scoped store
through a surface that has no membership check.

**Decision: do not add a source type now.** The authoring pass omits these
three fields (the console already offers a connection picker where a connection
is actually used), and a future "options from a service" feature is a proposal,
not a manifest key. This is the "decide once" answer the open question asked
for.

## 6. Finding 3 — nesting maps to tabs, not to a new type

CRM's option is nested up to three levels
(`external_sourcing.upwork.default_connection_id`,
`external_sourcing.deduplication.strategy`,
`integrations.gmail_poll_interval`, `routing.strategy`, `consent.*`,
`optimization.*`).

The manifest is flat by design. The mapping the authoring pass uses:

1. **Flatten the leaf path** to a snake_case key
   (`external_sourcing_upwork_default_connection_id`).
2. **Top-level groups become tabs** through `tab_labels` + per-setting `tab`
   (added in plan §56): `qualification`, `routing`, `integrations`,
   `external_sourcing`, `hygiene`, `optimization`, `consent`.
3. **Sections within a tab are not expressible**, and were not added. CRM's
   groups fit the tab budget; a section would only be needed if one tab carried
   enough fields that ordering alone stopped being readable. If that happens,
   it is a `section` string on the field plus a `section_labels` map — the same
   additive shape as tabs — decided once, by the same rule.

## 7. Verdict

**CRM is expressible with the six types.** After the `textarea` addition the
only unrepresentable fields are the three that read another store, and those
are deliberately out of scope. The 23-manifest pass can proceed with:

- newline-separated strings in `textarea` for list fields;
- flattened keys + `tab_labels` for groups;
- the three dynamic selects omitted, recorded in the manifest's `description`
  if an operator needs to know why.

## 7a. What writing the first manifest found (2026-11-18)

`crm.json` shipped as the worked reference — **66 settings across 10 tabs**, no
entities. Authoring it surfaced one more schema assumption:

- **`entities` was required**, so a settings-only toolkit could not exist. CRM's
  real records are CPTs with their own REST/pages; the engine owns no table for
  them, so declaring an entity workbench would have rendered an empty one. The
  schema now treats **omitted** `entities` as settings-only and still rejects an
  explicitly empty list (declaring the key and naming nothing is a mistake, not
  a decision). `ToolkitContractTest` skips the entity lifecycle for such a
  family instead of indexing `entities()[0]`.

CRM declares `screens: ["settings"]` only. It has no dashboard because every
card aggregates `nvoos_toolkit_records`, which would read zero for a family the
engine does not store.

## 7b. The authoring recipe (for the remaining 22)

1. Open the WordPress settings page for the toolkit and enumerate the controls.
2. Map to `text | textarea | number | boolean | select | secret`; a newline list
   is a `textarea` with `rows` (2–20); a password input is `secret`; an `email`
   input is `text`.
3. Flatten nested option paths to snake_case keys; one tab per top-level group,
   labelled in `tab_labels`, every setting carrying its `tab`.
4. Give every field a `default` matching the WordPress default. A boolean gets
   `false`, a text/textarea/select gets `""` (list `""` in `options` when the
   WordPress field has an empty first option). This keeps a freshly generated
   form saveable.
5. Omit a field whose options come from another store, and say why in the
   manifest description or the tracking issue.
6. Declare `screens` from what the engine can actually serve: `settings` always;
   `entities`/`dashboard` only when the family owns `nvoos_toolkit_records` rows.
7. `php bin/sync-toolkit-manifests.php` then
   `php bin/audit-admin-manifests.php` — the mirror and the lint are the gate.
8. `ToolkitContractTest` covers the new family automatically (catalogue, settings
   round-trip, dashboard, entity lifecycle where declared).

## 8. Validation

- Core: `ToolkitManifestTest` **34 tests / 63 assertions** — textarea accepted
  with a `rows` hint, `rows` rejected on other types, `rows` bounds
  (1/21/`'4'`/4.5 rejected), and a settings-only manifest accepted while an
  explicitly empty `entities` list is still rejected.
- Panel: `AdminToolkitsTest` **13 tests / 60 assertions** — the fixture
  manifest carries a tabbed `textarea`; the settings page renders
  `<textarea`, a multi-line value round-trips, and a blank text setting no
  longer errors.
- Contract: `crm.json` is exercised by `ToolkitContractTest` — catalogue,
  manifest, settings round-trip — and the entity lifecycle skips it because it
  declares no entities.
- spa-v2: `tsc --noEmit` + eslint clean; bundle rebuilt
  (`assets/dist/pro-spa.js`).
- `php bin/audit-admin-manifests.php` exit 0 with **3 manifests / 66 CRM
  settings**; the mirror is current.

## 9. The authoring pass, part 1 (2026-11-18)

Nine further manifests shipped: **12 in total, 158 settings**. The pass stopped at
eight of the twenty-five `WP_MCP_AI_Toolkit_Settings_Base` subclasses because
inspection disproved the premise the recipe rests on — that every settings page
*persists* what it renders.

### 9a. The persistence test that governs authoring

`register_setting()` hands the submitted array to `sanitize_settings()`, and the
base implementation whitelists exactly three keys: `enable_remote_sites`,
`enable_research`, `research_assistant_id`. A field not named
`<option_name>[key]` in the markup never reaches that array at all. So a
manifest may only describe a control that survives one of two paths:

1. the page overrides `sanitize_settings()` and reads the key from `$input`; or
2. the page reads a prefixed key straight from `$_POST` (the sync toolkits).

Everything else is decoration. That is the rule this pass applied, and it is the
first thing to check for any toolkit authored later.

### 9b. What shipped

| Manifest | Settings | Source of truth |
|---|---|---|
| `project-management` | 8 | `sanitize_settings()` whitelist |
| `pro-schedule` | 8 | `sanitize_settings()` whitelist |
| `law-firm` | 7 | `sanitize_settings()` whitelist |
| `comic-creation` | 11 | `sanitize_settings()` whitelist |
| `chat-channels` | 4 | `sanitize_settings()` whitelist |
| `shopify-sync` | 7 | prefixed `$_POST` keys |
| `ezuite` | 5 | prefixed `$_POST` keys |
| `flowhub` | 12 | prefixed `$_POST` keys |
| `dietpi` | 26 | `sanitize_settings()` whitelist, nested `apps` flattened |

Each declares `screens: ["settings"]` and no entities: none of these families
owns `nvoos_toolkit_records` rows. `pro-schedule` reuses the existing
`schedules` feature; the other eight added one kebab-case feature key each to
`ProPlanCatalog` in all three plans, which preserves the pre-existing behaviour
of every plan (the Q-5 re-tiering decision is untouched). The one pre-existing
gate key that used the underscore form, `surface-ownership.json`'s
`feature:chat_channels`, was aligned to `feature:chat-channels` to match the
manifest; no code referenced the old string.

### 9c. Blocked, and why

| Toolkit | Blocker |
|---|---|
| `analytics`, `architectural-design`, `ecommerce`, `financial-planner`, `media-toolkit`, `multilingual`, `pro-packages`, `regulatory-registration`, `social-media`, `video-production` | Base `sanitize_settings()` only. Their configuration tabs use raw `name="..."` inputs, so every control is discarded on save. A manifest would add a *second* settings screen that writes to a store nothing reads. |
| `architect-agent`, `cloudways` | Render no controls at all — both configuration tabs are informational panels pointing at Connections / Tools settings. A manifest would render an empty screen. |
| `ai-tool-builder`, `calendar-booking`, `dj-management` | Placeholder pages ("Coming Soon"). |

**The actionable finding is the first row, not the manifest gap.** Ten Pro
toolkits ship a settings tab that silently drops every value. Fixing that means
naming their controls `<option_name>[key]` and extending `sanitize_settings()` —
a WordPress-side change that must land before any of them can be authored. It is
worth a follow-up issue rather than a parity workaround.

### 9d. Fields omitted by rule

- **Assistant selects** (`research_assistant_id`, `default_assistant`): options
  come from the assistant store (§7b rule 5).
- **Remote-site connection checklists** (`sync_connections`): options come from
  the Remote Sites store.
- **Key/value mappings** (`field_mapping`): a repeating row pair, not a scalar.
- **`catalog_search_terms`**: the WordPress screen persists it as a **list**
  (split on newlines, capped at 20). A manifest `textarea` stores a string, so
  including it would change the store's shape.
- **`flowhub.location_id`**: persisted and consumed, but rendered by no
  WordPress control.

### 9e. Validation

- Core: `ProPlanCatalogTest` + `ToolkitManifestTest` **43 tests / 159
  assertions**.
- App: `ToolkitContractTest` + `EntitlementTest` **22 tests / 402 assertions**,
  including the durable gate that every manifest `feature` is entitled by the
  base plan; full app suite **1277 tests / 7243 assertions, 0 failures**.
- `Phase4Test::test_toolkit_list_returns_the_entitled_manifests` pins the committed
  catalogue by slug — it is the one test a new manifest must be added to.
- `php bin/audit-admin-manifests.php` exit 0 — **12 manifests**, all
  panel-servable; `php bin/sync-toolkit-manifests.php --check` current;
  `php bin/audit-surface-ownership.php` exit 0; phpcs clean.
