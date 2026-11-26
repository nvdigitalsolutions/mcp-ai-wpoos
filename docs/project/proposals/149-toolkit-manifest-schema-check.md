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

**Status:** that follow-up landed as §10, and the nine toolkit pages it unblocked
were authored as §11. The remaining rows stay unauthorable for their own reasons:
`architect-agent` and `cloudways` render no controls, `ai-tool-builder`,
`calendar-booking` and `dj-management` are placeholders, and `pro-packages` says
so explicitly.

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

## 10. The WordPress-side persistence fix (2026-11-18)

§9c concluded that ten pages discarded their own settings and that the fix
belonged on the WordPress side, not in a manifest. This is that fix.

### 10a. Three defects, not one

1. **Raw control names.** Eight pages rendered `name="data_retention"` and
   friends. `options.php` only hands `<option_name>[key]` values to the
   registered sanitiser, so nothing ever reached it.
2. **No schema.** Nine pages — including `media-toolkit`, whose names were
   already correct — relied on the base sanitiser, which whitelists three keys.
   Every other key was dropped; worse, because the sanitiser returned only
   those three, a save also **erased** anything else stored in the option.
3. **Checkbox asymmetry.** The base wrote `enable_remote_sites` and
   `enable_research` only when the key was present in POST. An unchecked
   checkbox is absent, so those tabs could be switched on but never off.

`pro-packages` was never a defect: its configuration tab renders no controls and
says so ("No configuration needed").

### 10b. What changed

- `WP_MCP_AI_Toolkit_Settings_Base::setting_schema()` — a page declares
  `key => type`; the base sanitiser casts against it. Types: `text`, `textarea`,
  `number`, `boolean`, `select`, `email`, `url`, `color`, `key`. A `select` may
  carry an `options` list to be enforced; without one the value is stored as
  text, because a second copy of the option list would be the same drift bug in
  a new place.
- `sanitize_settings()` starts from the **stored** option, so a save no longer
  erases keys the form does not render — `wp_mcp_ai_media_toolkit_settings` is
  read by the Media Command Center, which is not this page's writer.
- Booleans write `false` when unchecked, which is what makes a toggle a toggle.
- The eight raw pages namespace their controls under their own option; all nine
  declare their schema. **60 keys** in total.

### 10c. The gate

`tests/pro/admin/test-toolkit-settings-persistence.php` checks the regression
from three directions:

1. **Markup** — every `name="…"` in each page is namespaced to that page's
   option. A raw control fails the build.
2. **Schema** — the declared schema keys and the rendered keys are the same set,
   so adding a control without declaring it (or the reverse) fails here instead
   of silently dropping data in production.
3. **Behaviour** — a typed payload round-trips through the sanitiser, a stored
   key the form does not render survives a save, and an unchecked Research
   toggle writes `false`.

### 10d. What this unblocks

All nine pages now meet §7b's authoring rule (a field persists when it is named
`<option_name>[key]` **and** the sanitiser accepts it), so they can be authored
as manifests — **nine more, 12 → 21**. That pass shipped as §11 below.

### 10e. What is still broken

`financial-planner` renders three `wp_mcp_ai_settings[...]` controls
(`enable_yfinance_service`, `yfinance_service_url`, `yfinance_cache_ttl`). They
are inert twice over: `settings_fields()` opens the **toolkit's** option group,
so `options.php` never processes them, and the main settings sanitiser registers
none of the three keys — yet `WP_MCP_AI_YFinance_Service` reads all three. The
fix belongs in the main settings form. The gate lists this page as its one
documented exception, so the pattern cannot spread silently.

## 11. Batch 2 — the nine authorable toolkits (2026-11-18)

§10 fixed the persistence defect and named the follow-up explicitly: the nine
pages now met §7b's rule, so they could be authored. This is that pass. It
closes the first row of §9c and takes the catalogue **12 → 21 manifests /
218 settings**, all settings-only.

### 11a. What was authored

| Manifest | Slug registered by the page | Settings |
|---|---|---|
| `analytics` | `analytics` | 3 |
| `architectural-design` | `architectural-design` | 9 |
| `ecommerce` | `ecommerce` | 3 |
| `financial-planner` | `financial_planner` → manifest kebab | 4 |
| `media` | `media` (class `…media-toolkit…`) | 22 |
| `multilingual` | `multilingual` | 3 |
| `regulatory-registration` | `regulatory_registration` → manifest kebab | 10 |
| `social-media` | `social_media` → manifest kebab | 3 |
| `video-production` | `video_production` → manifest kebab | 3 |

Four pages register an underscored `toolkit_slug`; the validator's
`SLUG_PATTERN` is kebab, so the manifest slug is the kebab form. `media` is the
one place the two conventions disagree in the other direction — the class name
says `media-toolkit` but the page registers `media`, so the manifest is `media`
(precedent: `flowhub`, `ezuite`).

Every key is a straight read of the page's `setting_schema()`; the labels,
options and defaults come from the markup. `media` is the only family with
`tab_labels`, because it is the only one of the nine whose configuration tab is
divided into sections (five `<h2>`s → five tabs).

Nine kebab-case feature keys were added to `ProPlanCatalog` in all three plans
and to `ProPlanCatalogTest::testSurfacesTheRuntimeGatesOnAreEntitledByEveryPlan`.

### 11b. Four defaults that the first pass had wrong

The scoping notes recorded `enable_ocr`, `enable_image_preprocess`,
`enable_background_processing` and `auto_cleanup_temp` as `false`. Every one of
them renders `checked` in the markup, so each defaults **true**:

```php
checked( $options['enable_ocr'] ?? true, 1 );
checked( $options['enable_image_preprocess'] ?? true, 1 );
checked( $options['enable_background_processing'] ?? true, 1 );
checked( $options['auto_cleanup_temp'] ?? true, 1 );
```

This is the §7b corollary worth stating plainly: **the default comes from the
rendered control, not from the option's name or from anyone's notes.** A panel
whose default disagrees with the page it replaces is a parity bug even when the
key is correct.

### 11c. What is still not authorable

- `financial-planner`'s three `wp_mcp_ai_settings[...]` controls (§10e). Cross
  option, so §7b excludes them; the manifest carries 4 of the page's 7 controls.
- `pro-packages` — renders no controls.
- `architect-agent`, `cloudways` — informational panels only.
- `ai-tool-builder`, `calendar-booking`, `dj-management` — placeholders.

That accounts for every toolkit settings page without a manifest. Twenty-five
classes extend `WP_MCP_AI_Toolkit_Settings_Base`; **nineteen** of them have a
manifest after this pass, and the remaining six are the ones above. The
catalogue's other two families, `commerce` and `documents`, are authored from
non-toolkit Pro screens — which is what takes the total from 19 to 21.

### 11d. Still open — eight of the nine tabs do not render what they store

§10 made the writes correct; it did not make the reads correct. Only
`media-toolkit` calls `get_option( $this->option_name )` (line 247) and passes
the array into its controls. The other eight print literal defaults. The stored
value is right after a save, but reopening the tab shows the default, and the
next save posts that default back over the stored value.

So the honest statement of parity for this batch was: **`media` is editable from
either surface; the other eight are editable only from the panel** until the
WordPress render side reads the option. **Fixed in §12.**

### 11e. Validation

- Core: `ProPlanCatalogTest` **9 tests / 141 assertions**, `ToolkitManifestTest`
  **34 / 63** — both green, run against this worktree's `lib/core`.
- App: `ToolkitContractTest` **12 tests / 713 assertions** (every one of the 21
  shipped manifests is driven through catalogue → settings → dashboard),
  `EntitlementTest` + `Phase4Test` **26 / 150**, full app suite **1284 / 7483,
  0 failures** (plan §59.4).
- `php bin/audit-admin-manifests.php` exit 0 — **21 manifests**, all
  panel-servable; `php bin/sync-toolkit-manifests.php --check` current;
  `php bin/audit-surface-ownership.php` unchanged at **45 surfaces · 33 built ·
  7 console-only · 3 planned**; phpcs clean on every changed PHP file.

## 12. The read half — tabs now render what they store (2026-11-18)

§10 made the writes correct and §11 authored the manifests. §11d recorded what
neither fixed: eight of the nine tabs printed literal defaults, so a stored value
was invisible on the page that stored it, and the next save posted the default
back over it. This is that fix.

### 12a. What changed

- `WP_MCP_AI_Toolkit_Settings_Base::stored_options()` — reads the page's own
  option once and normalises it to an array. One call site per page, so the
  option name cannot be mistyped and a non-array option cannot raise a
  `TypeError` on PHP 8.
- All nine pages render from it. Text and number inputs take
  `value="<?php echo esc_attr( $options['key'] ?? '<old literal>' ) ?>"`;
  checkboxes take `checked( $options['key'] ?? <old default>, 1 )`; every
  `<option>` takes `selected( $options['key'] ?? '<old default>', '<value>' )`.
- The literals survive as the `??` fallback, because `setting_schema()` carries
  types and not defaults (§10b). The rendered defaults are unchanged, so the
  manifests in §11 did not have to move.

### 12b. The gate grew a fourth direction

`test_the_configuration_tab_renders_the_stored_values` stores a legal, per-key
probe for every declared setting, renders the tab, and requires the markup to
carry it; then stores every boolean `false` and `true` and requires every checkbox
of that page to follow. Two refinements were needed in practice:

- **Radios.** `media-toolkit` renders `sharp_processing_mode` as radio buttons
  although its schema type is `select`. The gate accepts a `<select>` or a radio
  group, because the schema type describes the value, not the widget.
- **Per-key probes.** A single shared sentinel would let a field that ignores the
  option pass on a sibling's output.

Running the same assertions against the `HEAD` revisions of all ten classes:
**38 failures across the eight broken pages, 0 for `media-toolkit`** — correct,
since `media` already read its option. Against the fixed tree: **0**.

### 12c. What this did not fix

Rendering correctly is not consuming. Auditing the readers produced the finding
that now dominates this track (plan §60.3):

| Store | Read outside its writer? |
|---|---|
| `wp_mcp_ai_<toolkit>_toolkit_settings` | `media` only. **Zero** for the other eight. |
| `wp_mcp_ai_pro_toolkit_settings[<slug>]` | the Pro toolkit REST controller and the records layer — not the toolkit tools |
| Laravel `toolkit.<slug>.<key>` | `ToolkitEngine` only |

`architectural-design` is the worked example: the page writes
`wp_mcp_ai_architectural_design_toolkit_settings` while
`class-wp-mcp-ai-architectural-engine.php` reads
`wp_mcp_ai_arch_design_settings`, which nothing writes — and the page's help text
names the engine's option, so the split is a mistake rather than a design.

So: **saving these settings is no longer lossy, and is still inert.** Choosing a
single canonical store per toolkit, pointing the tools at it and deciding whether
the manifest layer reads through is a design decision, recorded as plan §60.4
rather than guessed at here.

### 12d. Validation

- Display assertions against both trees: **38 → 0** (stub harness, real classes).
  The WP PHPUnit suite was not run locally; CI runs the gate.
- The rendered key set of all nine pages is **unchanged from `HEAD`** (60 keys),
  so the §10c markup↔schema check still matches.
- phpcs **0 errors** on all eleven files (warnings all pre-existing), `php -l`
  clean.
