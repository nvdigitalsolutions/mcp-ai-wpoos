# Proposal 160 — Tool Readiness Triage Plan

**Status:** Batches 1–6 landed (149 §139–§147); the four image rows landed 2026-11-22 (149 §160). Open: four orchestration rows. · **Date:** 2026-11-21 · **Owner:** platform
**Depends on:** 090-M (the readiness manifest), 149 §123–§134 (machine principals)
**Measured against:** `lib/core/resources/tool-readiness.php`, 2026-11-21

## 1. The measured state

| Fact | Value |
|---|---|
| Manifest rows | **223** |
| Tool classes in `lib/core/src/Tool` | 214 |
| **Servable** (`ready` + `pure` + `delegated`) | **96** |
| `ready` / `pure` / `delegated` | 68 / 22 / 6 |
| `unverified` | **108** |
| `quarantined` | 19 |
| Rows with MCP annotations | 115 |

The 108 unverified are constructible but carry **no evidence**. Under the
manifest's own vocabulary they are *not served*: the default for an unknown slug
is `unverified`, so failing to classify cannot make a tool callable. That default
is the reason this backlog is safe to leave — and the reason it must be cleared
deliberately rather than in bulk.

### 1.1 What the 108 are

| Group | Count |
|---|---|
| Image / file / vector-store | **21** |
| Video | 8 |
| Audio | 2 |
| Cache / cron / jobs / workflow | ~30 |
| Charts / search / research / agents / other | ~47 |
| **Document / PDF / OCR** | **0** |

There is no document-family backlog: documents are covered by Paper Store and
semantic search, which are already classified.

### 1.2 Toolkit manifests are not tool bundles

`resources/pro-toolkits/*.json` (21 of them, 1:1 with
`addons/pro/includes/toolkits/manifests`) contain `screens`, `settings`,
`entities`, `dashboard` and `tab_labels` — **no tool slugs**. A toolkit is an
admin screen specification. It cannot be "added to the machine allowlist": the
allowlist (`oos.machine_tools`, `/ops/machine-tools`) takes tool slugs, and the
picker only offers **servable** tools. The route to allowing document/media work
is classifying the individual tools those screens use.

## 2. Why this is a triage, not a bulk edit

A heuristic pass was attempted and **rejected on its own results**: scanning the
108 classes for I/O markers classified `generate_openai_image`, `deep_research`,
`web_search` and `upload` as "no I/O" — 105 of 108 came back clean. The marker
list missed the runtime's HTTP abstraction. Writing classifications from it
would have made network tools servable, which is precisely the failure the
vocabulary exists to prevent.

**Rule: a readiness decision must be defensible from the class's own code or
from a passing test that calls it. Nothing else counts.**

## 3. Standards basis

- **MCP tool annotations are a risk vocabulary, not a permission system.** The
  MCP guidance is explicit: set `readOnlyHint: true` on reads,
  `destructiveHint: false` on additive writes, `openWorldHint: false` on
  closed-domain tools, and add `idempotentHint` only where repeat-safety has been
  verified. Clients use them for retries and parallel calls — so an inaccurate
  hint is worse than a missing one.
- **Our runtime already treats them as a veto** (§126): `destructiveHint: true`
  or `readOnlyHint: false` routes a machine call to approval; `openWorldHint`
  alone does not veto; no annotation makes no claim. Triage must therefore
  annotate *accurately*, because the allowlist and the veto read the same rows.
- **Inventory before enablement** (OWASP NHI Top 10): a machine identity should
  only reach tools whose behaviour is recorded. `unverified` is the inventory
  state; this plan is the enablement pass.

## 4. The decision tree

Use the first rule that applies; record the reason in the row.

| # | Rule | Readiness | Evidence required |
|---|---|---|---|
| 1 | The class performs the work it claims and a test calls it successfully | `ready` | `evidence` = test path, `probe` = `call`, annotations |
| 2 | No I/O by nature and correctness is self-evident (pure computation) | `pure` | none; reason states the computation |
| 3 | The client performs the work; the server half only describes it | `delegated` | reason naming the client half |
| 4 | The class describes work it does not perform (stand-in, prompt builder, retired slug) | `quarantined` | reason + `replaced_by` when one exists |
| 5 | Needs a service this runtime does not bind | `unavailable` | reason naming the unbound service |
| 6 | None of the above is provable today | leave `unverified` | — |

`ready` and `pure` are the only paths that expand the servable set. Both require
the annotations an operator and a machine will read.

## 5. Annotation policy for newly servable rows

| Behaviour | Annotation |
|---|---|
| Reads only (fetch, list, inspect, transform without side effects) | `readOnlyHint: true`, `destructiveHint: false`, `openWorldHint` per whether it reaches outside |
| Adds or updates, repeat-safe | `readOnlyHint: false`, `destructiveHint: false`, `idempotentHint: true` |
| Adds or updates, not repeat-safe | `readOnlyHint: false`, `destructiveHint: false` |
| Deletes or overwrites | `readOnlyHint: false`, `destructiveHint: true` |
| Closed-domain (local computation) | `openWorldHint: false` |
| Reaches the open internet | `openWorldHint: true` |

The 21 image/file tools fetch an `image_url`, so they are **not** pure and not
closed-domain: `openWorldHint: true`, `readOnlyHint: true` for transformations,
`destructiveHint: true` for `delete_file`.

## 6. Batches

Each batch is one PR of **15–25 rows**, applied with
`php bin/audit-tool-readiness.php --classify=map.json`, followed by the gates in
§7. A batch that cannot prove a row leaves it `unverified` and says why.

### Batch 1 — image, file and vector-store (21 rows)

`resize_image`, `crop_image`, `rotate_image`, `convert_image_format`,
`create_image_variation`, `generate_*_image*` (7), `edit_*_image*` (3),
`generate_image_alt_text_validated`, `generate_image_caption_validated`,
`upload_file`, `delete_file`, `get_openai_file_details`, `list_openai_files`,
`manage_vector_store_files`.

- **Transformations** (`resize`, `crop`, `rotate`, `convert`): one evidence test
  with a faked fetcher, one per tool or one table-driven case each. `readOnlyHint:
  true`, `openWorldHint: true`.
- **Generators/editors**: need a provider fake; without one they stay
  `unverified`. Do not mark `ready` because the class "looks complete".
- **`delete_file`**: `destructiveHint: true`; it is the one row in this batch
  that a machine must never run unattended.
- **OpenAI file/vector-store tools**: `ready` only if the OpenAI Files/Vector
  Stores endpoints are actually called and testable; otherwise `unavailable`
  with the unbound-endpoint reason.

### Batch 2 — video and audio (10 rows)

Provider-dependent. Evidence needs a faked provider; `transcribe_openai_audio`
additionally needs a fixture file. Any row whose provider is not bound becomes
`unavailable`, not `ready`.

### Batch 3 — cache, cron, jobs, workflow (~30 rows)

Mostly local stores. `pure` for the arithmetic/decision helpers
(`calculate_erlang_c`, `erlang_c_*`), `ready` for the ones a test can call
against the database, `unavailable` where the service is not bound
(`purge_varnish_cache` when Varnish is absent).

### Batch 4 — charts, search, research, agents (~47 rows)

The riskiest: network and LLM calls. `generate_chart` / `create_chart` are pure
rendering and should classify early; `web_search`, `deep_research`,
`query_remote_site` and the agent tools need a fake transport and a reason that
names what they reach.

## 7. Gates per batch

| Gate | Command | Pass condition |
|---|---|---|
| Manifest integrity | `php bin/audit-tool-readiness.php` | exit 0, no `unclassified`, vocabulary clean |
| App suite | `php -d memory_limit=768M vendor/bin/phpunit` (container) | green |
| Registry parity | `ToolsReadinessTest` | every servable slug constructs |
| Picker | `/ops/machine-tools` | only the newly servable tools appear; no `unverified` row does |
| Reason quality | review | every decision has a reason a reviewer can check |

A `ready` row without an `evidence` path that exists in the tree is a batch
failure, not a warning.

## 8. Risks and rollback

| Risk | Mitigation |
|---|---|
| A wrong `ready` makes a tool machine-callable | evidence test required; annotations set from the class's behaviour; §126 veto still routes destructive tools to approval |
| Provider spend during tests | fake the transport; no live calls in CI |
| Manifest drift | `--write` only adds rows; `--classify` only merges a decision, never invents one |
| Batch too large to review | 15–25 rows per PR, one domain per PR |
| Rollback | the manifest is one file; reverting the batch commit restores the prior state exactly |

## 9. What this does for the allowlist

Only servable tools appear on `/ops/machine-tools`. Clearing a batch therefore
does three things at once: it makes the tools **listable**, it makes them
**annotated** (so the veto is accurate), and it makes the machine gate's
behaviour **reviewable** — an operator can see, per row, whether a machine would
run it or be routed to a human.

It does **not** grant anything. Allowlisting is a separate operator action; an
allowlisted state-changing tool still queues an approval (§124, §126).

## 10. Definition of done

- 0 `unverified` rows, **or** every remaining row carries a reason it cannot be
  classified today (e.g. "provider not bound, revisit with the media worker").
- Every servable row has annotations.
- The picker's list is exactly the servable set — no more, no less.
- The plan's batches are recorded in 149 as they land, with counts.

## 11. Batch 1 measured finding - the image contract has one implementation

Enumerating the four transformation tools (`resize_image`, `crop_image`,
`rotate_image`, `convert_image_format`) found a shared hard dependency on
`ImageProcessingInterface`. It has exactly one implementation in the tree:
`lib/wordpress-adapter/src/Adapter/ImageProcessing.php`. There is none in
`lib/laravel-adapter`, none in `app/`, and no container binding; the container
carries neither GD nor Imagick.

`ManifestToolRegistrar` builds tools reflectively and **skips** any constructor it
cannot satisfy, so a `ready` row for these four would have registered nothing and
reported nothing. They are therefore `unavailable`, not `unverified`.

The app already ships the intended backend - `app/Support/Media/MediaWorkerClient.php`
alongside `MediaSweeper`, `MediaLibrary` and `OptimizeMediaFile` - so the follow-up
is a `MediaWorkerImageProcessing` adapter bound to the interface, after which these
four rows can become `ready` with a test as evidence.

**Landed 2026-11-22 (149 §160).** The client moved into `lib/laravel-adapter`
(`Nvoos\Laravel\Adapter\MediaWorkerClient`) so the *package* can construct all
four tools — the manifest's promise has to hold for any runtime that loads it,
not only the app. The adapter adds two sidecar routes, `POST /api/image/transform`
and `POST /api/image/info`, with explicit pixel, dimension and format bounds. The
four rows are `ready`; servable is **199**.

## 12. Contract accounting - every core interface checked against this runtime

The image contract was found by accident, so the rest were checked deliberately.

Method: enumerate all 75 files in `lib/core/src/Domain/Contract/`; collect every
`implements`, `bind`, `singleton` and `scoped` in `lib/core/src`,
`lib/laravel-adapter`, `lib/craft-adapter` and `docker/laravel/app-skeleton/app`;
subtract. 37 of the 75 have an implementation or binding outside WordPress. The
remaining 38 split into four groups, only the first of which affects readiness.

### 12.1 Mandatory tool dependencies with no implementation (11 rows)

| Contract | Rows | Outcome |
|---|---|---|
| `ImageProcessingInterface` | 4 | `unavailable` (§11) |
| `AgentOrchestrationInterface` | `check_workflow_health`, `create_agent_team`, `delegate_to_agent`, `execute_workflow` | `unavailable` |
| `EmailServiceInterface` | `send_group_email`, `send_group_email_validated` (inherits it) | `unavailable` |
| `ProfessionWriteInterface` | `save_profession` | `unavailable` |
| `MjmlCompilerInterface` | `generate_email_template` | stays `pure` - optional constructor arg, registers today |

None of the four unbound contracts appears in either provider, and Laravel's
container cannot auto-resolve an interface, so reflection fails and the registrar
skips the tool. Marking any of these `ready` would be a silent no-op.

Adapter size where an adapter is the right answer: `EmailService` (3 methods,
over Laravel Mail), `ImageProcessing` (7, via the media worker),
`AgentOrchestration` (4, needs the workflow tables).

`ProfessionWrite` is the exception - it is not a missing adapter but a decision.
The catalogue is code-owned reference data (090-g §4.3), and
`ProfessionRepositoryTest::test_the_laravel_catalogue_is_read_only` pins that this
runtime's catalogue is not writable: `save_profession` is registered only on the
WordPress CPT. Implementing the interface here would reverse a deliberate choice,
so the row stays `unavailable` for good rather than waiting on work.

### 12.2 Declared but not consulted (28 contracts)

28 contracts are referenced by no core application file at all - only their own
declaration. They are declarations ahead of use, not gaps: `ChatContinuation`,
`ChatService`, `CodeFormatting`, `CostTrackingService`, `CronStatus`, `EmbeddingService`,
`ErrorTrackingService`, `FileOrchestration`, `FileValidationService`, `FinancialData`,
`LanguageDetection`, `ModelCatalog`, `MusicGeneration`, `OcrService`,
`OrchestrationControl`, `PerformanceMonitor`, `TimeoutDetection`, `TokenBudgetService`,
`ToolAsyncExecutor`, `ToolDataContract`, `ToolExecution`, `ToolFlowStage`,
`ToolLlmSanitizer`, `ToolLoadBalancer`, `ToolRules`, `ToolWriteClass`,
`ValidationService`, `VisionInference`.

Several have WordPress-named counterparts in `includes/interfaces/` that the
WordPress adapters satisfy (`HttpClient`, `Options_Store`, `Embedding_Provider`,
`Cron_Status_Job_Source`, `Tool_Queue`, `Generic_Tool_Response`) - those *are*
accounted for, under their own names.

### 12.3 Marker interfaces WordPress enforces and core never consults

This is the finding that is not about boot: a ported tool can construct fine and
still behave differently. WordPress reads these markers across its tool corpus;
core declares four of them and consults none.

| WordPress interface | Consumers in `includes/` + Pro | Core counterpart consulted? |
|---|---|---|
| `WP_MCP_AI_Tool_Data_Contract_Interface` | 78 | contract exists, 0 readers |
| `WP_MCP_AI_Tool_LLM_Sanitizer_Interface` | 30 | contract exists, 0 readers |
| `WP_MCP_AI_Tool_Model_Requirements_Interface` | 30 | **no core contract** |
| `WP_MCP_AI_Tool_Rules_Interface` | 22 | contract exists, 0 readers |
| `WP_MCP_AI_Tool_Safety_Profile_Interface` | 17 | **no core contract** |
| `WP_MCP_AI_Tool_Flow_Stage_Interface` | 1 | contract exists, 0 readers |

Consequence: an LLM sanitizer, a data contract, a rule set or a safety profile
declared by a ported tool is silently ignored in Laravel. Readiness does not catch
this because the tool still runs - it just runs with fewer guards. This belongs in
149 as a behavioural row rather than here; it is recorded so a green batch is not
mistaken for behavioural equivalence.

### 12.4 One adapter is missing a capability its WordPress twin has

`ChatOrchestrator` and `ToolRegistry` gate every waterfall hook on
`$events instanceof WaterfallEventDispatcherInterface`. The WordPress dispatcher
implements it (`implements EventDispatcherInterface, WaterfallEventDispatcherInterface`);
the Laravel one declares only `EventDispatcherInterface`. Every waterfall filter is
therefore skipped in Laravel, silently, by design of the `instanceof` check.

Cheap to fix and cheap to test; it changes chat behaviour, so it warrants its own
loop rather than riding along with a manifest batch.

### 12.5 What this changes here

- Manifest: 11 rows now `unavailable` with reasons naming the missing contract;
  servable count is unchanged at 96 because none of the 11 was servable.
- Nothing else moved. The 28 inert contracts and the 6 unenforced markers do not
  affect readiness and are left unclassified on purpose - inventing a readiness
  value for a contract nothing consults would be noise.
- Batch 1's remaining 20 rows are unaffected: they need provider fakes, not
  adapters, and are triaged in batch 1 itself.
