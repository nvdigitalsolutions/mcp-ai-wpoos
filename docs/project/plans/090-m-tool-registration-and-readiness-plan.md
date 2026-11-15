# 090-M — Implementation plan: tool registration and readiness

**Status:** In progress (2026-11-15).
**Parent:** [`090-m-tool-registration-and-readiness.md`](../proposals/090-m-tool-registration-and-readiness.md).
**Scope:** `lib/core` (manifest + loader), `lib/laravel-adapter` (registrar + gates), `bin/` (auditor), `docker/laravel` (API payload), console Tools page.
**Non-goals:** Phase 3 (real vision implementations) and Phase 5 (WordPress emits the same manifest) beyond the seams recorded here.

---

## 1. What "done" means

Six observable outcomes. Each maps to a gate that fails if it regresses.

| # | Outcome | Gate |
|---|---|---|
| O1 | Every tool-contract class in `lib/core` has a manifest row | `bin/audit-tool-readiness.php` exits 1 on an unclassified class; `ToolReadinessTest` |
| O2 | No `quarantined` tool is registered on any runtime | `ToolReadinessRegistrationTest` |
| O3 | No prompt-builder shares a slug with a WordPress tool | Auditor slug-collision check (collisions must carry a `parity` claim) |
| O4 | Registration is derived from the manifest, not a hand list | `ManifestToolRegistrar` + the live count reported in the PR |
| O5 | The console can see readiness | `GET /api/v1/tools` carries `readiness`, `annotations`, `servable` |
| O6 | The manifest is pinned | `ToolReadiness::hash()` + a committed hash in the audit report |

---

## 2. Task list

### Phase 0 — Auditor (T0)

| ID | Task | Files | Acceptance |
|---|---|---|---|
| T0.1 | Port the throwaway scanner into a committed tool: walk `lib/core/src/Tool`, collect tool-contract classes, parse constructor params with balanced-paren scanning, parse `execute()` with balanced-brace scanning | `bin/audit-tool-readiness.php` | Reproduces 223 classes / 55 registered / 16 shadow |
| T0.2 | Add `--write` mode: inserts a missing manifest row as `unverified`; **never overwrites an existing decision** | same | Second run is a no-op; hand-edited rows survive |
| T0.3 | Add `--json` and human output; non-zero exit on G1 (unclassified) / G2 (dangling row, bad vocabulary) / O3 (slug collision with `includes/tools`) | same | Exit codes proven in T1.4 |
| T0.4 | Record the known blind spots in the script header (inherited ctor, inherited `execute`, non-service property names) | same | Reviewed in diff |

### Phase 1 — Manifest and quarantine (T1)

| ID | Task | Files | Acceptance |
|---|---|---|---|
| T1.1 | `ToolReadiness` loader: closed vocabulary, `classify()`, `isServable()`, `annotations()`, `servable()`, `hash()`, `violations()`, path override for other hosts | `lib/core/src/Application/Tool/ToolReadiness.php` | `ToolReadinessTest` (T1.5) |
| T1.2 | Generate `lib/core/resources/tool-readiness.php`; classify the 55 registered (`ready`/`pure`/`delegated`), the 16 shadow tools (`quarantined`), the rest `unverified`/`unavailable` | `lib/core/resources/tool-readiness.php` | Auditor reports 0 unclassified |
| T1.3 | Quarantine the 19: rename slug to `<slug>_prompt`, rewrite `getName()`/`getDescription()` to say what they actually return | 19 files in `lib/core/src/Tool/` | No `lib/core` slug matches an `includes/tools` slug for these classes |
| T1.4 | Verify exit codes: unclassified class → 1; dangling row → 1; clean tree → 0 | `bin/audit-tool-readiness.php` | Manual run, recorded below |
| T1.5 | Core tests: vocabulary is closed; every row's class exists; no duplicate slugs; `hash()` is stable; `quarantined` is not servable; unknown slug defaults to `unverified` | `lib/core/tests/Unit/Application/ToolReadinessTest.php` | Core suite green |

### Phase 2 — Registration (T2)

| ID | Task | Files | Acceptance |
|---|---|---|---|
| T2.1 | `ManifestToolRegistrar`: for each servable slug, reflect the constructor and resolve each parameter from the container; skip (never throw) on failure and record the reason | `lib/laravel-adapter/src/Tool/ManifestToolRegistrar.php` | — |
| T2.2 | Seed the registry from the registrar **before** the bespoke `CoreToolCatalogue`/service lists, so those become overrides rather than the source | `NvoosServiceProvider::registerTools()` | Boot does not double-register |
| T2.3 | Reclassify the batch whose constructor is satisfied by bound services **and** whose behaviour is verified by a probe; leave the rest `unverified` | manifest + `ToolReadinessRegistrationTest` | Count delta recorded |
| T2.4 | Assert no `quarantined`/`unverified` slug is registered | `lib/laravel-adapter/tests/ToolReadinessRegistrationTest.php` | Suite green |

### Phase 3 — Profile surface (T3)

| ID | Task | Files | Acceptance |
|---|---|---|---|
| T3.1 | `GET /api/v1/tools` emits `readiness`, `annotations` per tool and `total`/`servable` | `docker/laravel/app-skeleton/app/Http/Controllers/ToolsController.php` | App suite green |
| T3.2 | Feature test pins the payload shape and that every returned tool is servable | `docker/laravel/app-skeleton/tests/Feature/ToolsReadinessTest.php` | Suite green |
| T3.3 | Console: show a readiness badge and the runtime count in the Tools empty state | `addons/pro/assets/spa-v2/src/features/tools/…` | Build + existing Vitest green |

### Phase 4 — Deferred seams (T4)

| ID | Task | Files | Acceptance |
|---|---|---|---|
| T4.1 | Leave a documented seam for WordPress (`ToolReadiness` path override + a copy step), not an implementation | proposal §5.6 | Recorded |
| T4.2 | Leave the vision family `quarantined` with `replaced_by` pointing at the WordPress host | manifest | Recorded |

### Phase 5 — CI gate (T5)

| ID | Task | Files | Acceptance |
|---|---|---|---|
| T5.1 | Report-only workflow running the auditor and uploading the JSON report | `.github/workflows/tool-readiness.yml` | Green on the current tree; `continue-on-error` documented as the promotion switch |

### Phase 6 — Batch 2 (T6)

| ID | Task | Files | Acceptance |
|---|---|---|---|
| T6.1 | Promote the next read-only pool with a probe per tool | manifest + `ToolProbesTest` | Count delta recorded; every probe asserts a real payload or a typed error |
| T6.2 | Demote anything the probe cannot prove | manifest | `get_user_info` returned to `unverified` with a reason |

### Phase 7 — Batch 3 (T7)

| ID | Task | Files | Acceptance |
|---|---|---|---|
| T7.1 | Promote ten more read-only tools with a probe each | manifest + `ToolProbesTest` | 20 probe cases, all green |
| T7.2 | Fix what a probe exposes instead of papering over it | `lib/laravel-adapter/src/Adapter/MemoryStore.php` | `whereIn` for array filters; probe is the regression test |

### Phase 8 — Write-tool probes (T8)

| ID | Task | Files | Acceptance |
|---|---|---|---|
| T8.1 | Decide the write-probe contract (ADR M-7) | proposal §5.6 | `validate_only` is the gate; `dry_run` an opt-in tier |
| T8.2 | Promote the content writers under the contract | manifest + `WriteToolProbesTest` | Refusal asserted **and** row count unchanged |
| T8.3 | Retro-probe the five project/task writes already `ready` | manifest | Same assertion, no registration change |
| T8.4 | Record the probe tier on every probed row | manifest + probe tests | `probe: call` / `validate_only`; auditor rejects an unknown tier |
| T8.5 | Record the remaining evidence gap | proposal §5.6, plan T9 | 56 servable rows without a probe, 11 of them writes |

### Phase 9 — Remaining evidence gap (T9, open)

Not started; recorded so the number cannot drift unnoticed.

| Gap | Count | Next |
|---|---|---|
| Servable rows with no probe tier | **0** | Closed by T10 — 65 `call`, 16 `validate_only`, 4 `dry_run` |
| …of which are writes | **0** | Closed by T9.1 |
| Evidence living outside the adapter suite | 2 | `check_capability`, `get_user_info` — both read the host `users` table, probed in `ToolsReadinessTest` |
| `dry_run` support in `lib/core` | 15 of 223 | Content writers + project/task + ECA/student writes (phase 3) |
| `dry_run` on WordPress | 3 tools | `create_post`, `save_post`, `delete_post` (phase 2, runtime-verified) |
| Phase 3 WordPress parity | 15 tools | Every phase-3 tool except `bulk_import` has a WP counterpart — see T13 |

| ID | Task | Files | Acceptance |
|---|---|---|---|
| T9.1 | Probe the 11 remaining write tools under `validate_only` | `WriteToolProbesTest` | 34 cases green; `of_which_write=0` |
| T9.2 | Record the tiers | manifest | `validate_only` 20, `call` 19 |
| T10.1 | Probe the 45 remaining reads | `ToolProbesTest` | 65 `call` tiers; 0 servable rows without a probe |
| T10.2 | Fix what the probes exposed | `ContentFreshnessCheckerTool`, `ToolProbesTest` | "Freshness analysis prepared." → "Freshness indicators computed."; six wrong argument shapes corrected |
| T10.3 | Move host-dependent probes to the suite that has the dependency | `ToolsReadinessTest` (app) | `check_capability` + `get_user_info` probed where `users` exists; `get_user_info` re-promoted |
| T11.1 | Add `dryRunPlan()` + `isDryRun()` to the shared base | `AbstractTool` | One plan shape for every runtime |
| T11.2 | Honour `dry_run` in the four content writers | `lib/core/src/Tool/{Create,Save,Update,Delete}PostTool.php` | Plan returned after validation/authorisation; no write |
| T11.3 | Prove it in the adapter suite | `WriteToolProbesTest` | Row count unchanged; update leaves the title; delete leaves the post |
| T11.4 | Reach WordPress | `includes/tools/class-wp-mcp-ai-tool-{create,save,delete}-post*.php`, `includes/validators/arguments/class-{create,save}-post-arguments.php` | Verified against `oos-wp`: plan returned, zero delta |
| T12.1 | `dry_run` in the project/task writes | `Create{Task,Project}Tool`, `Update{Task,Project}Tool`, `Delete{Task,Project}Tool` | Plan returned after validation; no write |
| T12.2 | `dry_run` in the ECA/student writes | `Classroom/{Create,Update}{Eca,Student}Tool`, `BulkEnrollStudentsTool`, `ManageEcaWaitlistTool`, `MarkEcaAttendanceTool`, `EnrollStudentEcaTool`, `WithdrawStudentEcaTool`, `BulkImportTool` | Same |
| T12.3 | Probe the 11 that need no classroom fixture | `WriteToolProbesTest` | 36 tests / 326 assertions; every case asserts zero row delta |
| T12.4 | Add `dry_run` to the 16 schemas | tool schemas | `phpcbf` clean; every tool advertises the flag |
| T12.5 | Leave the 5 enrolment tools `validate_only` | manifest | They need a populated classroom fixture; recorded, not ignored |

### Phase 10 — WordPress parity for phase 3 (T13, open)

Not started. 15 of the 16 phase-3 tools have WordPress counterparts
(`bulk_import` is the exception), so each needs the M-9 checks: which class the
registry serves, whether the DTO declares `dry_run`, and whether the wrapper
re-implements the write.

| Gap | Count | Next |
|---|---|---|
| Phase-3 tools with WP counterparts | 15 | Batch them like T11.4, verifying against `oos-wp` |
| Enrolment tools without a probe on either runtime | 5 | Needs a classroom fixture (ECA + students + enrollments) |

---

## 3. Validation matrix

| Suite | Command | Baseline | After |
|---|---|---|---|
| Core | `php -d extension=fileinfo vendor/bin/phpunit` (`lib/core`) | 1,751 tests / 6,185 assertions | **1,760 / 6,221** |
| Adapter | `php -d extension=fileinfo vendor/bin/phpunit` (`lib/laravel-adapter`) | 180 tests / 678 assertions | **285 / 1,533** |
| App | `docker exec -w /app oos-laravel-app php artisan test` (with the two `NVOOS_PM_*` fixtures) | 694 passed / 4,070 assertions | **697 / 4,183** |
| Lint | `php vendor/bin/phpcs --standard=phpcs-laravel.xml.dist --error-severity=1 --warning-severity=8` (root) | 807 files, 0 errors | **814 files, 0 errors** |
| SPA typecheck | `npx tsc --noEmit` (`addons/pro/assets/spa-v2`) | clean | **clean** |
| SPA unit | `npx vitest run` | 523 passed, **2 failed** (pre-existing `media-ops-client` `formatBytes`/`formatPercent` drift) | unchanged |
| Console smoke | `npm run test:e2e` (`examples/nvoos-complete-velocity`) | 6 passed | not run (stack-heavy; see §4) |

---

## 4. Execution log

Filled in as each phase completes. Numbers are from this machine on 2026-11-15.

| Phase | Result |
|---|---|
| T0 | `bin/audit-tool-readiness.php` committed. Reproduces 223 tool-contract classes; `--write`, `--classify`, `--manifest`, `--report`, `--json`. Exit codes proven: 1 on G1/G2/O3 violations, 0 clean, 2 on usage errors. |
| T1 | Manifest generated (223 rows) and classified. **61 servable** (6 delegated, 21 pure, 34 ready), **19 quarantined**, 143 unverified, **0 violations**. 19 prompt-builders renamed to `<slug>_prompt` with honest descriptions; 5 core test assertions updated. New `ToolReadinessTest`: 9 tests / 36 assertions. |
| T2 | `ManifestToolRegistrar` seeds the registry from the manifest before the legacy catalogue. Live count **55 → 61**, **0 skipped**. New `ToolReadinessRegistrationTest`: 5 tests / 90 assertions (all-registered-are-servable, no-quarantine-leaks, container-builds-every-servable, registry-matches-manifest, slugs-not-reachable). |
| T3 | `GET /api/v1/tools` adds `readiness`, `annotations`, `servable`, `endpoint_contract_version` (additive). New `ToolsReadinessTest`: 2 tests; the servable count must equal the served list. Console `ToolDefinition` gains optional `readiness`/`annotations` and the Tools table renders a readiness badge; `tsc --noEmit` clean. |
| T4 | Seams recorded: `ToolReadiness` accepts a path override (`NVOOS_TOOL_READINESS`) for the WordPress copy; the 19 quarantined rows carry `replaced_by` pointing at the WordPress slug. |
| T5 | `.github/workflows/tool-readiness.yml` runs the auditor and uploads `tool-readiness.json` for 14 days. Job-level `continue-on-error: true` keeps it report-only while it soaks; removing that line makes it a blocking gate with no other change. |
| T6 | **Batch 2: 9 promoted, 1 refused.** `count_posts`, `get_recent_posts`, `search_content`, `get_post_taxonomies`, `get_post_type_schema`, `get_site_summary`, `list_available_models`, `get_setting`, `list_settings` each carry a probe (`ToolProbesTest`, 10 tests). `get_user_info` was promoted, failed its probe (`no such table: users` — the users table belongs to the host, not the adapter), and went back to `unverified` with that reason recorded. Live count **61 → 70**, **0 skipped**. |
| T7 | **Batch 3: 10 promoted, 0 refused — and one real defect found.** `get_cache`, `get_file_info`, `get_job_status`, `get_post`, `list_batches`, `list_cron_jobs`, `list_jobs`, `list_skills`, `recall_memory`, `search_attachments`. `recall_memory` initially threw `Array to string conversion` (500): the tool passes `tier => ['core','recall']` but `MemoryStore::applyFilters()` cast the value with `(string)`. Fixed in the adapter with `whereIn` for array filters — the tool's `include_tiers` list is the contract, so the adapter was the outlier. The probe stays as the regression test. `get_job_status` returns a synthesised status for an unknown id (success, not an error), which is the adapter's documented behaviour — the probe asserts the payload instead. Live count **70 → 80**, **0 skipped**. |
| T8 | **Write probes: 4 promoted, 5 retro-probed, 0 refused.** Contract decided in proposal §5.6 (ADR M-7): `validate_only` is the gate because no tool implements `dry_run` (measured 0 of 223). `create_post`, `save_post`, `update_post`, `delete_post` promoted; `create_task`, `update_task`, `delete_task`, `create_project`, `delete_project` gained probes without a registration change. Each case asserts a typed refusal **and** that `nvoos_posts` did not grow — all ten passed first run, so the authorise→validate→write ordering holds across both refusal styles (returned `ErrorFactory` errors and thrown `AccessDeniedException`). Probe tiers recorded as `probe: call` (19 read probes) and `probe: validate_only` (9 write probes); the auditor now rejects an unknown tier. Live count **80 → 84**, **0 skipped**. |
| T9 | **Write sweep complete: 11 more tools probed, 0 refused.** The classroom/ECA family plus `bulk_import` and `update_project`, each tested in **both guard modes**: anonymous (thrown `authorize()` refusal) and authenticated-but-empty (`user_id: 1` → returned validation error). 34 cases green first run; `nvoos_posts` unchanged after every call. **Every write tool is now probed** (`of_which_write=0`); 45 read-only rows remain without a tier. Live count unchanged at 84 — this pass added evidence, not surface. |
| T10 | **Read sweep complete: 45 probed, gap closed, one message fixed.** 21 pure, 6 delegated and 18 service-backed reads. Six argument shapes were wrong on the first run (`count_tokens` takes `text` not `content`; `client_question_answering` needs `context`+`question`; `client_semantic_search` needs `query`+`documents`; `client_translate_text` needs `text`; `verify_information` needs `sources`; `enable_reasoning_mode` needs `task`), and four list tools return entity-named keys (`ecas`, `students`, `tasks`, `projects`) rather than `items`. **One real fix:** `ContentFreshnessCheckerTool` returned "Freshness analysis prepared." while computing real indicators — the message now says "Freshness indicators computed.". Seven reads are auth-gated and needed a `user_id` context. `check_capability` and `get_user_info` read the host `users` table, so their probes moved to the app suite; `get_user_info` is re-promoted on that evidence. Live count **84 → 85**, **0 servable rows without a probe tier**. |
| T11 | **ADR M-8 phase 2 shipped: `dry_run` on the four content writers, both runtimes — and it caught a WordPress bypass.** `lib/core` gained `AbstractTool::dryRunPlan()`/`isDryRun()`; `create_post`, `save_post`, `update_post`, `delete_post` return `{dry_run, action, id, would_change, writes:false}` after every validation and capability check, and write nothing. Probes upgraded to `probe: dry_run` (4 rows) and assert the store is untouched, including that an update does not change the title and a delete leaves the post present. **WordPress needed two extra fixes:** the registry serves `*_Validated` wrappers, so (a) `dry_run` had to be added to `CreatePostArguments`/`SavePostArguments` (the wrapper drops unknown arguments), and (b) `save_post`'s validated class re-implements the write inline and needed its own guard — the first live probe **created a post despite `dry_run: true`**. Verified against the running `oos-wp` container: all three WordPress tools return a plan with zero row delta; `delete_post` leaves the target in place. `update_post` has no WordPress counterpart (WP folds it into `save_post`). |
| T12 | **ADR M-8 phase 3 (core side): 16 tools honour `dry_run`, 11 probed, 5 held back.** Project/task (`create/update/delete` × task and project, plus `bulk_import`) and classroom/ECA (`create/update` ECA and student) return a plan after validation and write nothing. `bulk_import` already had a `preview` contract, so `dry_run` forces it and annotates the report. Probes seed a task, project, ECA and student, then assert per tool: success, `dry_run`, `writes:false`, correct action and target id, **zero row delta**, and that the seeded rows survive the delete plans — 36 tests / 326 assertions. The five enrolment tools stay `validate_only`: they need a populated classroom fixture, recorded rather than faked. `phpcbf` cleaned 94 whitespace errors across 10 files. **WordPress parity is not included** — 15 of the 16 have WP counterparts, so T13 carries it. |

**Discoveries during execution.**

- The original 16 prompt-builders was an undercount: a broader sweep (`prepared.` success messages plus a returned `'prompt' =>` payload) found 19. `ContentFreshnessCheckerTool` computes real signals and stays; `GenerateSoraVideoTool` and `GenerateHiggsfieldImageTool` reach providers through `$this->s` / `$this->h` and are real.
- The O3 gate in the proposal was too strict: it flagged the 090-D ports (`client_*`, `count_tokens`, `list_professions`, …) as shadowing. It now requires a `parity` declaration, so a collision must be *classified* rather than merely absent.
- `bin/audit-tool-readiness.php` is the reproduction path for every number above; the one-off scanners used while writing the proposal are gone.

---

## 5. Risks carried from the proposal

- **M-1** slug rename: grep docs/tests for the 16 old slugs before renaming.
- **M-3** G3 is implemented as a *probe assertion per selected tool*, not a static rule; tools without a probe stay `unverified`.
- **M-6** manifest parse cost: one `require` per process, cached statically.
