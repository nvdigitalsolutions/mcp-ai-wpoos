# 090-B — Assistant Edit Field Parity (WordPress metaboxes ↔ Laravel ↔ console)

**Status:** Contract shipped (2026-11-13). Closes the read/write half of
090-B task 2.3 ("WP meta parity ⏳") for the design-time keys. The six
structured fields have bespoke editors (see §9). Skills ride the `advanced`
object (§4.1); MCP apps keep their own resource and save (§4.2). Credentials,
primary roles and harness profiles remain deliberately out of scope (see §6).

**Parent:** [`090-b-laravel-admin-surfaces-implementation-plan.md`](090-b-laravel-admin-surfaces-implementation-plan.md) §6 Phase 2.
**Related:** 090-g (console contract), `mcp-ai-wpoos-assistant-portability`
(`nvoos-assistant` bundle v1), `mcp-ai-wpoos-spa-ui`.

## 1. Why this exists

The WordPress assistant-edit screen is a metabox page
(`includes/assistants/class-wp-mcp-ai-assistant-cpt.php::register_meta_boxes()`)
with **16 metaboxes** (6 inline + 10 dedicated classes in
`includes/assistants/metaboxes/`) covering **20 `register_post_meta()` keys**.
The Laravel runtime and the shared console (`spa-v2` `AssistantsPage`) expose a
**7-field** editor. Until now the two runtimes diverged silently: a save from the
console wrote 6 fields and left every advanced metabox value untouched — or, on
Laravel, wrote them into an opaque `meta` blob with no UI.

This document is the field-by-field map, the wire contract that closes the gap,
and the explicit list of what is *not* covered.

## 2. The three surfaces

| Layer | Location | Shape |
|---|---|---|
| WordPress storage | `mcp_ai_assistant` CPT post meta | `_wp_mcp_ai_*` keys (one row each) |
| WordPress REST | `mcp-ai/v1/assistants` (`includes/class-wp-mcp-ai-rest.php`) | create/update accept 8 top-level fields; `GET /assistants/{id}` returns `{ success, data }` |
| Laravel storage | `assistants` table (`docker/laravel/app-skeleton/database/migrations/2026_01_02_000001_create_assistants_table.php`) | flat columns + `tools` JSON + `meta` JSON |
| Laravel REST | `api/v1/assistants` (`app/Http/Controllers/AssistantController.php`) | same 8 fields + `meta` + `primary_role_slugs` |
| Shared UI | `addons/pro/assets/spa-v2/src/features/assistants/AssistantsPage.tsx` | `AssistantFormFields` (7 fields) mounted by both the Complete Console and the standalone app |

## 3. Metabox → meta key → control inventory

### 3.1 Inline metaboxes (`register_meta_boxes()`)

| # | Metabox | Meta keys | Console control |
|---|---|---|---|
| 1 | Context Window Estimator | *(read-only; computes from tools)* | — (not a field) |
| 2 | Prompt Versions (091-B) | *(revision store, not post meta)* | — (WP-only surface) |
| 3 | Fallback Chain (091-D) | `_wp_mcp_ai_fallback_chain` | **Advanced** — ordered provider list |
| 4 | Available Tools | `_wp_mcp_ai_tools` | main form — `ToolPicker` |
| 5 | Prompt Shortcuts | `_wp_mcp_ai_tool_shortcuts`, `_wp_mcp_ai_tool_prebuilt_shortcuts`, `_wp_mcp_ai_disable_tool_shortcuts` | **Advanced** — JSON + checkbox |
| 6 | Access Control | `mcp_ai_required_capability` (note: **no** leading underscore) | **Advanced** — text |

### 3.2 Dedicated metabox classes (`includes/assistants/metaboxes/`)

| Metabox id | Class | Meta keys | Console control |
|---|---|---|---|
| `wp_mcp_ai_defaults` | `metabox-defaults` | `_wp_mcp_ai_provider`, `_wp_mcp_ai_model`, `_wp_mcp_ai_temperature`, `_wp_mcp_ai_system_prompt`, `_wp_mcp_ai_adaptive_tool_cap`, `_wp_mcp_ai_prompt_caching` | provider/model/temperature/prompt = main form; `adaptive_tool_cap` + `prompt_caching` = **Advanced** |
| `wp_mcp_ai_base_knowledge` | `metabox-base-knowledge` | `_wp_mcp_ai_memory_files`, `_wp_mcp_ai_vector_store_id`, `_wp_mcp_ai_corpus_name` | **Advanced** |
| `wp_mcp_ai_credentials` | `metabox-credentials` | `_wp_mcp_ai_credentials` (`WP_MCP_AI_Credentials::META_KEY`) | ❌ excluded — hashed tokens, issuance is a separate surface |
| `wp_mcp_ai_primary_roles` | `metabox-primary-roles` | `_wp_mcp_ai_primary_roles` (WP post IDs) | ❌ excluded — identity model differs (Laravel uses profession **slugs** in `meta.primary_role_slugs`) |
| `wp_mcp_ai_preferred_datasets` | `metabox-datasets` | `_wp_mcp_ai_preferred_datasets` | **Advanced** — JSON |
| `wp_mcp_ai_mesh_routing` | `metabox-mesh-routing` | *(mesh peers, options-backed)* | ❌ excluded — site/network config, not assistant config |
| `wp_mcp_ai_skills` | `metabox-skills` | `_wp_mcp_ai_skills`, `_wp_mcp_ai_skills_progressive` | **Advanced** — skills picker + progressive toggle (§4.1) |
| `wp_mcp_ai_mcp_apps` | `metabox-mcp-apps` | `_wp_mcp_ai_mcp_apps` (`WP_MCP_AI_MCP_App_Registry::META_KEY`) + status meta | **Advanced** — MCP apps panel with its own save (§4.2) |
| `wp_mcp_ai_harness_profile` | `metabox-harness-profile` | service-backed (`WP_MCP_AI_Harness_Profile::get/save`) | ❌ deferred — own service + scheduler |
| `wp_mcp_ai_artifact_governance` | `metabox-artifact-governance` | *(read-only report)* | — (report, not a field) |
| `wp_mcp_ai_tool_role_rules` | inline in CPT | `_wp_mcp_ai_tool_role_rules` | **Advanced** — JSON |
| `wp_mcp_ai_external_action` | inline in CPT | `_wp_mcp_ai_external_action_id`, `_wp_mcp_ai_external_action_type` | **Advanced** — text + enum |

## 4. The `advanced` wire contract (v1)

Additive object accepted on `POST /assistants`, `PUT /assistants/{id}` and
returned inside `data` by `GET /assistants/{id}`. Both runtimes implement the
same shape and the same defaults.

| `advanced` key | Type | WordPress meta | Sanitizer |
|---|---|---|---|
| `fallback_chain` | `string[]` | `_wp_mcp_ai_fallback_chain` | `sanitize_fallback_chain_meta` |
| `adaptive_tool_cap` | `'' \| 'on' \| 'off'` | `_wp_mcp_ai_adaptive_tool_cap` | `sanitize_adaptive_tool_cap_meta` |
| `memory_files` | `int[]` (attachment IDs) | `_wp_mcp_ai_memory_files` | `sanitize_memory_files_meta` |
| `vector_store_id` | `string` | `_wp_mcp_ai_vector_store_id` | `sanitize_vector_store_meta` |
| `corpus_name` | `string` | `_wp_mcp_ai_corpus_name` | `sanitize_corpus_name_meta` |
| `prompt_caching` | `bool` | `_wp_mcp_ai_prompt_caching` | `sanitize_prompt_caching_meta` |
| `disable_tool_shortcuts` | `bool` | `_wp_mcp_ai_disable_tool_shortcuts` | `sanitize_disable_tool_shortcuts_meta` |
| `tool_shortcuts` | `object[]` | `_wp_mcp_ai_tool_shortcuts` | `sanitize_tool_shortcuts_meta` |
| `tool_prebuilt_shortcuts` | `object` | `_wp_mcp_ai_tool_prebuilt_shortcuts` | `sanitize_prebuilt_tool_shortcuts_meta` |
| `tool_role_rules` | `object[]` | `_wp_mcp_ai_tool_role_rules` | `sanitize_tool_role_rules_meta` |
| `external_action_id` | `string` | `_wp_mcp_ai_external_action_id` | `sanitize_external_action_id_meta` |
| `external_action_type` | `'' \| 'workflow' \| 'assistant'` | `_wp_mcp_ai_external_action_type` | `sanitize_external_action_type_meta` |
| `required_capability` | `string` | `mcp_ai_required_capability` | `sanitize_required_capability_meta` |
| `preferred_datasets` | `object[]` | `_wp_mcp_ai_preferred_datasets` | `sanitize_preferred_datasets_meta` |
| `skills` | `string[]` (skill-name slugs) | `_wp_mcp_ai_skills` | `sanitize_skills_meta` |
| `skills_progressive` | `bool` | `_wp_mcp_ai_skills_progressive` (`1` or row deleted) | `wp_validate_boolean` |

Rules:

1. **Whitelist only.** Unknown keys in `advanced` are ignored, never written.
2. **Delete-on-empty.** `''`, `false`, and `[]` delete the meta row (matches the
   CPT `save_post` handler: unticking a box removes the row rather than storing
   `0`).
3. **Partial updates are per-key.** A key absent from the request is left
   untouched; a key present with an empty value clears it.
4. **One sanitizer source.** WordPress routes every value through the CPT's own
   `sanitize_*_meta()` callbacks so the REST path and the metabox save path
   cannot drift.
5. **No secrets.** `_wp_mcp_ai_credentials` is not in the map and must never be
   added.

### 4.1 Skills catalogue route

`GET mcp-ai/v1/skills` (base plugin, `manage_options`) returns the installed
Agent Skills catalogue for the picker:

```json
{ "success": true, "skills": [ { "name": "…", "description": "…", "license": "…", "compatibility": "…" } ], "total": 1 }
```

- **Metadata only.** `instructions` and `allowed_tools` are never shipped on this
  route; the prompt builder injects instructions server-side.
- **Base, not Pro.** The registry lives in `includes/`; routing the picker
  through the Pro namespace would 404 on base-only installs.
- **Empty endpoint hides the picker.** Runtimes that do not expose the route
  (e.g. Laravel today) must not claim "no skills are installed".
- **Unknown assignments survive.** A skill assigned on another runtime (or since
  uninstalled) is kept and flagged "not installed here" rather than dropped.
- `skills_progressive` maps to the metabox's unticked-box semantics: `true`
  stores `1`, `false` deletes the row.

### 4.2 MCP apps (separate resource, separate save)

MCP apps are **not** part of the `advanced` object. They keep their own
registry-managed resource, mirroring the WP metabox's
`$this->metaboxes['mcp-apps']->save()`:

| Route | Purpose |
|---|---|
| `GET mcp-ai/v1/mcp-apps/{assistant_id}` | Assigned apps, secrets masked |
| `PUT mcp-ai/v1/mcp-apps/{assistant_id}` | Replace the list (console write path) |

Write contract:

- **Secrets are write-only.** `token` and `oauth_data` are masked on read
  (`••••••••abcd`); the console never sends them back. The server discards any
  `••`-masked echo and restores the stored credential by identity
  (`connection_ref` first, else `server_url`).
- **Connection references, not URLs+secrets.** The panel adds apps from a
  Remote Sites connection (`connection_ref`); the endpoint and credentials are
  resolved at chat time, so no inline secret is stored.
- **Same acceptance rule as the metabox.** An entry needs a usable
  `server_url` or a `connection_ref`; otherwise it is dropped.
- **Registry limits still apply** — 10 apps per assistant.
- Unknown assistant IDs return `404`, never a silent write.

## 5. Runtime mapping

| `advanced` key | WordPress | Laravel |
|---|---|---|
| all of the above | one `_wp_mcp_ai_*` post-meta row | flat key inside the `meta` JSON column; `Assistant::toRecord()` promotes the whitelist to `advanced` |
| `primary_role_slugs` | not exposed (post IDs) | `meta.primary_role_slugs` (unchanged, Laravel-only) |

Laravel writes go through `AssistantController::withAdvancedFields()`, which
whitelists, coerces (bool cast, `string[]`/`int[]` filtering, enum clamping) and
merges into `meta` — the same flat keys, so an `nvoos-assistant` bundle exported
from Laravel imports into WordPress and vice versa.

## 6. Deliberate exclusions (v1)

| Surface | Why | Follow-up |
|---|---|---|
| Credentials | Issuing/hashing bearer tokens is a dedicated service with its own capability + audit path; exposing it on a generic config save would be a privilege-escalation foot-gun | Keep the WP metabox + `WP_MCP_AI_Credentials`; Laravel keeps the explicit token-scoping deferral recorded in 090-B §6 |
| Primary roles | WP stores profession **post IDs**; Laravel stores catalogue **slugs**. Round-tripping requires a catalogue lookup, not a coercion | Own task; the console already shows the Laravel-only slug picker |
| Harness profile | Service-backed (`WP_MCP_AI_Harness_Profile`) with scheduler coupling | Port the harness service, then add a panel |
| Mesh routing | Site/network topology, not per-assistant config | — |
| Prompt versions | Revisions, not meta; WP-only history surface | Optional read-only panel later |

## 7. Verification

- **WordPress:** `tests/rest-api/test-rest-assistant-crud.php` — advanced read,
  write round-trip, clear-on-empty, unknown-key rejection, skills round-trip and
  slug sanitation, credentials never returned;
  `tests/rest/test-rest-skills-catalogue.php` — route registration, admin/editor
  gate, metadata-only payload, empty registry.
- **WordPress (Pro MCP apps):**
  `tests/mcp-apps/test-rest-mcp-apps-connection-enhancements.php` — the `PUT`
  route preserves stored tokens and OAuth blobs across a masked echo, accepts
  `connection_ref`-only entries, drops target-less entries, enforces the 10-app
  cap and 404s unknown assistants.
- **Laravel:** `docker/laravel/app-skeleton/tests/Feature/AssistantAdvancedFieldsTest.php`
  — create/show/update round-trip, enum clamping, unknown-key rejection,
  `meta` merge preserves `primary_role_slugs`. Skills/MCP apps are not exposed
  by the Laravel runtime yet (the console hides both pickers when the endpoints
  are absent).
- **SPA:** `addons/pro/assets/spa-v2/src/features/assistants/__tests__/assistants-advanced.test.tsx`
  — 15 tests: seed from `GET`, save round-trip, add/reorder/remove a prompt
  shortcut, add a role rule with an access flag, add a dataset with
  category/priority, block save on an unapplied JSON draft, report invalid
  applied JSON inline, hide the skills picker without a route, assign a skill,
  toggle progressive disclosure, flag an uninstalled assignment, hide the MCP
  panel without an endpoint, list assigned apps, and add-from-connection +
  save without secrets.
- **Bundle rule:** a spa-v2 source change ships only when BOTH
  `addons/pro/assets/spa-v2/assets/dist/` and
  `examples/nvoos-complete-velocity/dist/` are rebuilt (see
  `mcp-ai-wpoos-spa-ui` → "Two-bundle rule").

## 8. Exit gate

> A console edit of any advanced field round-trips through both runtimes, and a
> WordPress metabox save of the same key is visible in the console without a
> reload of the assistant list.

## 9. Editor UX standards (research-backed)

The six structured fields (`tool_shortcuts`, `tool_prebuilt_shortcuts`,
`tool_role_rules`, `preferred_datasets`, `skills`, `mcp_apps`) render as bespoke
editors in
`addons/pro/assets/spa-v2/src/features/assistants/AdvancedFieldsEditor.tsx`,
with a per-section "Edit as JSON" fallback for the first four (apply / revert,
shape validation, and a blocked Save while a draft is unapplied). Each editor
encodes the server sanitizer's drop rules inline so a value cannot silently
disappear on save.

The skills picker adds: a search filter, a scrollable checkbox list
(`fieldset`/`legend`, native checkboxes), a selected-of-installed counter, an
"assigned but not installed here" warning, and the progressive-disclosure
toggle with its own explanatory hint. The MCP apps panel is a separate
`fieldset` with its own dirty state and Save/Discard pair, an add-from-connection
`select` (only unassigned connections are offered), a per-app enabled toggle,
and a live region announcing add/remove/save.

Decisions and their sources:

| Decision | Source |
|---|---|
| No `role="grid"`; use `fieldset`/`legend` and native controls in the tab order | W3C **ARIA Authoring Practices Guide — Grid pattern**: a grid is a composite widget that owns roving tabindex and requires an Enter/F2 edit-mode switch for editable cells; unnecessary for a handful of rows |
| Unique, descriptive action names per row (`Remove shortcut “Summarise”`) | **DWP Design System — Add another thing, Accessibility** (visually hidden text per `Change`/`Remove` link) |
| Inline error next to the control, `aria-describedby`, `aria-invalid`, visually hidden “Error:” prefix, Save blocked while invalid | **GOV.UK Design System — Error summary / Validation** (identical wording inline and in the summary; focusable first erroneous field) |
| Live region present at mount (`role="status"`, `aria-live="polite"`, `aria-atomic="true"`) announcing add/remove/move | **MDN ARIA live regions** + Sara Soueidan’s *Accessible notifications with ARIA Live Regions* (inject the region before its first message) |
| Reorder via buttons, not drag-only; ≥24 px targets; visible focus ring | **WCAG 2.2** SC 2.5.7 (dragging alternative), 2.5.8 (target size), 2.4.11 (focus not obscured), 1.4.11 (non-text contrast) |
| JSON fallback per section instead of a single global blob | Product judgement (labelled as such): the structured editor covers the supported shape, JSON preserves forward compatibility for filter-added keys and keys a future server adds |

Server-semantics rules surfaced as inline warnings (never silent loss):

- A shortcut with an empty label **and** payload is dropped.
- A shortcut `tool` that is not in the registry is dropped (the shortcut
  survives).
- A prebuilt group with no shortcuts is dropped.
- A role rule needs a registered tool and at least one role, group, or flag.
- A dataset needs a non-empty `dataset`; the list is capped at 10.
