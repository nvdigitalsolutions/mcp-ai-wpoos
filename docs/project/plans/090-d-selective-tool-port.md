# Proposal 090-D — Tool Parity: Selective Port

**Status:** plan + backlog (no tool ported yet)
**Decision:** D-2 from `090-c-research-screen-ai-drafting-plan.md` §11 —
*selective port of tools with no WordPress dependency* (option 3)
**Tracker:** `bin/classify-tool-portability.php` (regenerate the backlog any time)

---

## 1. The decision, in one paragraph

The Laravel runtime serves **194** tools from `lib/core`; the WordPress plugin
declares **328 base + 1,270 Pro** slugs. Porting all ~1,434 is neither feasible
nor desirable — most need WordPress, WooCommerce, JetEngine or the media stack
to do anything at all. We port **on demand and by cluster**: a tool moves into
`lib/core` only when (a) a Laravel customer actually needs it and (b) its
**dependency closure** is free of hard WordPress coupling. Everything else
stays in the plugin, and the console tells the truth about which runtime can
execute what.

---

## 2. Why not bulk-port

- **The dependency is often the point.** `create_woo_product` without
  WooCommerce is a facade over an absent system.
- **Duplication is a maintenance tax.** Two implementations of the same slug
  diverge silently; the plugin is the source of truth until a port has a
  parity test.
- **The console is runtime-agnostic.** It renders the registry it is given;
  a smaller catalogue on Laravel is a capability boundary, not a bug — as long
  as it is visible.

---

## 3. Method — classify the closure, not the file

Scoring the tool file alone is misleading. The F&B and GTmetrix families look
dependency-free until you notice their **base class** does all the WordPress
work (`WP_MCP_AI_Fnb_Tool_Base` pulls in five services; the GTmetrix tools
delegate to a shared client).

`bin/classify-tool-portability.php` therefore:

1. extracts every `get_slug()` / `getSlug()` literal from
   `includes/tools/`, `addons/pro/includes/tools/` and `lib/core/src/` — plus a
   second sweep of `addons/pro/includes/` restricted to classes that implement
   `WP_MCP_AI_Tool_Interface`, because six tools live outside `tools/` (the five
   foundry/harness exporters and the MCP-App bridge, which has no fixed slug);
2. diffs WP against `lib/core` (the gap);
3. walks each gap tool's **closure** — the file, its `extends` parent, and the
   files it `require_once`s (one hop, depth-capped);
4. counts *hard* references in that closure.

`WP_Error` is **not** a hard reference: `lib/core` ships an `ErrorFactory` and
the canonical envelope, so it is a mechanical swap. Counting it would inflate
every tier by ~1,000 tools.

### Tiers

| Tier | Hard refs in closure | Meaning |
|---|---|---|
| **A** | 0 | Portable as-is (pure PHP + i18n) |
| **B** | 1–3 | One adapter (capability check, settings read, plugin helper) |
| **C** | 4–10 | Small cluster port (HTTP client, filesystem, store) |
| **D** | 11–30 | WordPress-shaped — port only with a strong reason |
| **E** | 31+ | WordPress-bound — stays in the plugin |

### Current inventory (2026-11-13)

```text
slugs  base 328 · pro 1275 · core 210 · gap 1424
tiers  A 12 · B 143 · C 413 · D 632 · E 224
```

**Interpretation:** 155 tools (A+B) are plausible port candidates; 413 more
(C) become portable once a shared adapter exists. **856 (D+E) should stay.**

**Corrected 2026-11-13.** The previous inventory read `pro 1270 · gap 1434`
because the scan covered only `addons/pro/includes/tools`. Five tools in
sibling directories were invisible to it — `export_trajectory_corpus` (D, 18),
`export_preference_pairs` (C, 9), `export_plugin_docs_corpus` (D, 13),
`export_fine_tune_curriculum` (D, 16) and `suggest_workflows_from_history`
(C, 4). The Corpus Foundry exporters are therefore *in* the backlog, and
classified C/D: they are a store-plus-filesystem port, not a cluster-1 move.

---

## 4. Tier A backlog — the "pure" cluster

`hard: 0` from the classifier is necessary but **not sufficient**. The
classifier scores `wp_`/`WP_`/`$wpdb`/`wc_`/`jet_engine` symbols plus WP
functions such as `sanitize_text_field`; it does not follow calls to plugin
**classes** outside the file's `require_once` set. Read the tool body for
`WP_MCP_AI_*` service calls before assuming a port is one file. Two of the
eight cluster-1 candidates survived that check.

| Origin | Tool | State | Blocker for a core port |
|---|---|---|---|
| pro | `verify_information` | ✅ ported | — |
| pro | `validate_tool_schema` | ✅ ported | — |
| base | `list_prompt_cues` | ✅ ported | `ListPromptCuesTool` + `PromptCueLibrary` in `lib/core`. The catalogue moved with the tool (eight seeded cues, relicensed MIT — see `docs/developer/licence-transitions.md`); the WordPress `do_action` and discovered-cue option stay behind |
| base | `scope_memory` | ✅ ported | `ScopeMemoryTool` + `HarnessProfileProviderInterface`. Tag computation is pure; the only store read (`memory.pii_filter`) goes through the provider, which is optional — a runtime without harness storage reports the documented default |
| base | `enable_reasoning_mode` | ✅ ported | `EnableReasoningModeTool` + `ReasoningController` in `lib/core`. Scoring is pure; the port drops the WordPress learning-loop history write (nothing reads it, and it never affects a response) and has no hook seam. The clock is injectable for deterministic tests |
| pro | `generate_email_template` | ✅ ported | `MjmlCompilerInterface` + `GenerateEmailTemplateTool`; branding/footer moved to `MjmlTemplateBuilder::withBrandingHeader()` / `withFooter()`. The compiler stays optional — without one the tool serves `output_format: mjml` and reports a 501 for `html` |
| pro | `import_email_marketing_blueprint` | ❌ WordPress-only | **Corrected 2026-11-13.** The contract gap is real (`ContentStoreInterface` has no exact-title lookup and no `deleteMeta`), but the tool also installs the `email-campaign-manager` blueprint, whose 12 tools include 8 that do not exist in `lib/core` (`send_brevo_email`, `send_mailjet_email`, `send_mailgun_email`, `manage_brevo_contacts`, `manage_mailjet_contacts`, `get_brevo_statistics`, `get_mailjet_statistics`, `unified_channel_broadcast`). Porting it would produce an assistant Laravel cannot serve. It stays in the plugin |
| pro | `get_loop_metrics` | ⏳ blocked on a decision | Reads `WP_MCP_AI_Execution_History_CCT::get_session_history()` — a JetEngine CCT that is not even provisioned in the test environment. `period` is accepted but never used; the tool is purely session-scoped. Core records overlapping data through `SessionLogStoreInterface` (`TYPE_TOOL_RESULT` carries `name`, `outcome`, `duration_ms`), so a port is possible — but it would read a *different source* than the WordPress tool, which is a design decision rather than a mechanical move |
| pro | `list_ecas` | ✅ ported | `lib/core/src/Tool/Classroom/ListEcasTool.php` + `EcaRow`. Same slug, filters, pagination envelope and row key order as `WP_MCP_AI_Tool_List_ECAs`. Two documented divergences: `sort_by` accepts `name` / `created_date` only (WordPress also sorts by `day` / `type` through `meta_value`, which `ContentQuery` does not expose) and `url` is always empty |
| pro | `get_eca` | ✅ ported | `GetEcaTool` + `EcaRow::detail()`. Same fields in the same order, plus the optional `include_enrollments` student list. Divergence: `enrollment_breakdown` counts `_eca_student_enrollments` when the per-enrolment records are absent — see the defect note below |
| pro | `get_eca` enrolment records | ✅ corrected | **2026-11-14.** Enrolment meta is an **id-keyed map of records**, not a list of ids: `[ <student_id> => { enrollment_type, enrollment_date, payment_status, amount_due, notes, enrolled_by } ]`. Every WordPress consumer relies on that (`isset( $enrollments[ $id ] )`, `foreach ( … as $id => $record )`). `Enrollments::map()` normalises the map and the legacy plain list, so readers cannot silently produce id `0` / record `12`. The `ClassroomSeeder` now writes the real map shape and both schema descriptors are `object` with `additionalProperties` |
| pro | `list_students` | ✅ ported | `ListStudentsTool` + `StudentRow`. Same row keys, filters (`year_group`, `house`, `search`) and `pagination` envelope. `per_page` outside 1–100 resets to 20 rather than clamping, matching WordPress. `enrollment_count` reads the reverse link, so it stays 0 until `enroll_student_eca` is ported — the mirror records enrolment on the activity side |
| pro | `get_student` | ✅ ported | `GetStudentTool` + `StudentRow::enrollments()`. Same flat field order, `enrolled_ecas` row keys, `weekly_schedule` grouping (Monday-first, on by default) and `payment_status` `n/a` fallback. Unresolvable or wrong-type activity ids in the map are skipped, as WordPress skips deleted posts |
| pro | `list_classroom_courses` / `list_classroom_guardians` / `list_classroom_submissions` / `classroom_course_analytics` | ❌ WordPress-only | **Corrected 2026-11-14.** All four are Google API client tools — they call `WP_MCP_AI_ECA_Classroom_Helper::get_client()` and read Google Classroom directly, so there is no store a local mirror could serve them from. The cluster-3 port target is the **37 local-only ECA tools** instead (`get_eca`, `list_students`, `get_student`, `enroll_student_eca`, `mark_eca_attendance`, …) |
| pro | ECA / student store | ✅ store landed | `mcp_ai_eca` + `mcp_ai_student` + `mcp_ai_eca_category` descriptors, `ClassroomSeeder`, `ClassroomSchemaTest`. Local-mirror scope: the Google Classroom / ISAMS sync stays WordPress-only, and its fields are mirrored as empty strings. `_eca_is_paid` and `_eca_requires_audition` are declared as `yes`/`no` strings because that is the encoding `get_post_meta(...) === 'yes'` expects |
| pro | `git_inspect` / `git_change` | ⏳ | **excluded** — needs a process port and its own security review |

**WordPress defect found while porting `get_eca` (2026-11-14).**
`WP_MCP_AI_Tool_Get_ECA` builds its `enrollment_breakdown` and
`include_enrollments` list from `_eca_enrollments`, but nothing in the family
writes that key: `enroll_student_eca`, `bulk_enroll_students`,
`manage_eca_waitlist`, `create_eca` and `delete_eca` all maintain
`_eca_student_enrollments`. On real WordPress data the breakdown is therefore
always `{confirmed: 0, waitlist: 0, trial: 0, preference: 0}` and the enrolment
list is always empty. The Laravel port keeps the record shape when records
exist and otherwise counts the authoritative `_eca_student_enrollments`, so it
reports real numbers; the WordPress tool still has the defect. Left unfixed
here because changing it alters production output and belongs in its own PR.

Four `*_capture_*` tools (`pm_capture_decision`,
`social_capture_post_performance`, `docgen_capture_style_memory`,
`health_capture_encounter`) also score A, but each pulls a second file — they
are cluster 3 candidates once that store is understood.

**Port evidence.** The two shipped ports live at
`lib/core/src/Tool/VerifyInformationTool.php` and
`lib/core/src/Tool/ValidateToolSchemaTool.php`, with
`lib/core/tests/Unit/Tool/PortedClusterOneToolsTest.php` (18 tests, 51
assertions) and registration in `CoreToolCatalogue`. Boundaries are
deliberate: the core envelope nests the fields under `data`, and
`validate_tool_schema` does not reproduce the WordPress tool's
caller-supplied file read (its extractor is a stub that always yields an
empty schema).

**Verification recipe for the remaining rows.** Every candidate that looked
like a file move so far has hidden a call behind a trait or a sibling class
— `WP_MCP_AI_MJML_Service` looked pure until `sidecar_request()` turned out
to be an HTTP call from a trait. Before classifying a row, grep the file
**and every class it names** for `use .*Trait`, `get_option`, `update_option`,
`apply_filters`, `do_action`, `wp_remote_`, `wp_insert_post` and
`file_put_contents`. A row is only "move to core" when that set is empty
except for escaping and `__()`.

---

## 5. Tier B highlights (one adapter each)

143 tools, dominated by three shapes:

| Shape | Examples | Adapter |
|---|---|---|
| Capability check only | `okf_*`, `paper_store_*` | `AuthProviderInterface` / a capability port |
| "Current user" scoping | `get_schedule_latest_result`, `list_pro_schedules`, `render_schedule_result` | Same auth port + the schedule store |
| Plugin helper (`wp_mcp_ai_*`) | `self_consistency_vote`, `apply_prompt_cue`, `retrieve_with_provenance` | Move the helper into `lib/core` first |

When one adapter lands, its whole cluster drops a tier at once — that is why
the unit of work is a **cluster**, not a slug.

---

## 6. Port recipe

1. **Confirm demand.** A named customer workflow or a console surface that
   cannot work on Laravel without it. "It exists in WP" is not a reason.
2. **Read the closure.** `php bin/classify-tool-portability.php --json` and
   open every file in `files[]` — including the base class.
3. **Port the seams first.** If the closure needs HTTP, files or settings, use
   the core contracts (`HttpClientInterface`, `FileStoreInterface`,
   `SettingsStoreInterface`) — never `wp_remote_*`, `WP_Filesystem` or
   `get_option`.
   **CPT/CCT-backed tools are different:** they need the family schema, not an
   adapter — see `090-e-cpt-cct-schema-port.md` (654 of the 1,434 gap tools).
4. **Write the tool** in `lib/core/src/Tool/<Name>Tool.php`, extending
   `AbstractTool`, with `getSlug()`, `getName()`, `getDescription()`,
   `getRequiredCapability()` and `execute()`.
5. **Keep the contract identical.** Same slug, same input schema, same output
   shape, same canonical envelope (success array or `ErrorFactory` error).
   The console must not be able to tell which runtime answered.
6. **Parity test.** One fixture per tool: the same arguments produce the same
   result shape as the WordPress implementation. Assert on fields, not prose.
7. **Register + verify.** Bind it in the runtime registry; run
   `php bin/classify-tool-portability.php --tier=A` and confirm the slug is
   gone from the gap.
8. **Update this tracker** (status table) and, if the console count changes,
   the parity matrix in 090-C §8.2.

### Acceptance for any ported tool

- [ ] Slug absent from the classifier's gap.
- [ ] Core unit test + one parity fixture against the WP output.
- [ ] No `wp_`, `WP_`, `$wpdb`, `wc_` or `jet_engine` symbols in the file.
- [ ] Canonical envelope; two-gate sanitisation at entry/exit.
- [ ] `composer run lint` + `php -l` clean.
- [ ] Both runtimes return the same shape for the same fixture.

---

## 7. Guardrails

1. **No double registration.** A slug is served by exactly one runtime. When a
   tool is ported, the WP plugin keeps its implementation (WP still serves it);
   the Laravel registry gains the core one. There is no cross-runtime routing.
2. **The plugin stays the source of truth** until the parity test is green;
   after that, the core file is the reference for Laravel and the WP file for
   WordPress. Divergence is a bug, not a feature.
3. **Do not port D/E.** If a workflow needs one, the answer is Remote Sites /
   MCP, not a fork.
4. **Security review for anything that shells out or writes files**
   (`git_*`, `execute_shell_command`, upload tools) — these need their own
   proposal, not a batch port.
5. **Tell the truth in the UI.** Until a cluster is ported, the console's tool
   count on Laravel is smaller by design; document it rather than hide it.

---

## 8. Sequencing

| Cluster | Contents | Pre-req | Est. |
|---|---|---|---|
| **1. Pure computation** | `verify_information`, `generate_email_template`, `validate_tool_schema`, `scope_memory`, `list_prompt_cues`, `enable_reasoning_mode`, `get_loop_metrics`, `import_email_marketing_blueprint` | none | 1 PR |
| **2. Auth-scoped reads** | `okf_*`, `paper_store_*`, schedule reads | capability/auth port **+ the store seam (090-F)** | 1–2 PRs |
| **3. Store-backed** | `*_capture_*`, classroom reads | storage port | 1–2 PRs |
| **4. HTTP wrappers** | GTmetrix, DietPi, social metrics | `HttpClientInterface` binding | 1–2 PRs |
| **Deferred** | `git_*`, shell, uploads | separate security proposal | — |

Cluster 1 is the recommended first PR: eight tools, one file each, no I/O, and
it proves the recipe end-to-end (port → parity test → registry → classifier).

**Cluster 2 correction (2026-11-12).** The pre-req is not auth —
`AuthProviderInterface::userCan()` already exists and Laravel's execution
paths inject it. The blocker is storage: the Laravel `ContentStore` accepted
`metaQuery` / `taxQuery` and silently ignored both, so any ported CPT-shaped
tool would have returned unfiltered rows. `090-f-storage-bridge-plan.md`
Phase 1 fixes that (meta/tax translation, order mapping, pagination clamp);
cluster 2 proceeds once it lands.

---

## 9. Status

| Item | State |
|---|---|
| Classifier | ✅ `bin/classify-tool-portability.php` |
| Backlog generated | ✅ this document |
| Registry seeded with self-contained core tools | ✅ 23 tools bound in the Laravel provider (`CoreToolCatalogue`) — no slugs moved, the gap is unchanged |
| Cluster 1 ported | 🟡 6 of 8 — `verify_information`, `validate_tool_schema`, `generate_email_template`, `scope_memory`, `list_prompt_cues`, `enable_reasoning_mode`. The other two are not portable as written: `import_email_marketing_blueprint` installs a blueprint whose tools do not exist in core, and `get_loop_metrics` would have to change data source (see §4) |
| Cluster 2–4 | ⏳ |
| CPT/CCT families | ⏳ design: `090-e-cpt-cct-schema-port.md` (654 tools are storage-shaped) |
| Console parity note | ⏳ add the "runtime tool count" line to the Tools page empty state |

---

## 10. Open questions

| ID | Question | Recommendation |
|---|---|---|
| T-1 | Does the console need per-tool "available on this runtime" badges? | Yes, once cluster 1 lands — the registry is the source |
| T-2 | Do ported tools need to be *removed* from the WP registry? | No — WP keeps serving them; the slug set may overlap across runtimes |
| T-3 | Where do parity fixtures live? | `lib/core/tests/Fixtures/tools/<slug>.json`, shared by both suites |
| T-4 | Do we publish the tier table? | Internal first; publish a summary count in the operator docs |
