# Proposal 090-M — Tool registration and readiness

**Status:** Proposed 2026-11-15. Measurement-backed. No production code changed by this document.
**Scope:** `lib/core/src/Tool`, the registration site in `lib/laravel-adapter` and `docker/laravel`, the `GET /api/v1/tools` payload, and the console Tools page.
**Related:** `090-b` (admin surfaces), `090-d` (selective tool port), `090-e` (CPT/CCT schema), `090-k` (harness port), `090-b-assistant-edit-field-parity` §6.

---

## 1. The decision

> **Registration stops being a hand-maintained allowlist and becomes a declared, verified manifest. Every tool carries a readiness class, and only a tool whose readiness class is servable is registered. Prompt-builder stand-ins are quarantined under a different slug.**

Two facts force this:

1. The service bindings were never the blocker. `HttpClientInterface`, `SettingsStoreInterface`, `ContentStoreInterface`, `ProfessionRepositoryInterface` and `MemoryStoreInterface` are all bound in `NvoosServiceProvider` (lines 125–152). What limits Laravel to 55 tools is that `CoreToolCatalogue::selfContained()` is a hand-written list of 29 classes plus 26 explicitly constructed service tools — nothing enumerates the classes that would already construct.
2. Sixteen `lib/core` tool classes **share a slug with a real WordPress tool** but return `success` with a prompt string instead of doing the work. Registering them would be worse than not registering them, because the failure is silent: the assistant receives a success envelope and reports the task as done.

The second fact explains why the first has not been fixed by simply registering more classes. It also means the fix is not "register everything" — it is "classify, then register what can be honoured".

---

## 2. What we measured (2026-11-15)

Scanner: 223 classes under `lib/core/src/Tool` that declare a tool contract (`extends AbstractTool`, `implements ToolInterface`, or define `getSlug()`), excluding abstract bases.

| Measurement | Value |
|---|---|
| Tool-contract classes in `lib/core` | 223 |
| Registered today (29 catalogue entries + 26 service tools) | **55** — matches the live `ToolRegistry::all()` count exactly |
| Unregistered | 168 |
| Unregistered **and** constructible from already-bound services | **128 (upper bound — see §2.1)** |
| Prompt-builder stand-ins shadowing a real WordPress slug | **19** (§9) |
| Classes with no local `execute()` (inherit `AbstractResultTool` or a `*Validated` parent) | 13 — **not** a defect |

**After 090-M Phases 1–2, the read and write sweeps the live count is 85**, with 19 quarantined, **every servable tool carrying a probe tier** (65 `call`, 20 `validate_only`), and 0 violations. The measurements below are the proposal-time baseline; the plan's execution log carries the running numbers.

### 2.1 Why 128 is an upper bound

The scanner classifies a class as constructible when its **own** constructor is satisfied by bound services. `WebSearchValidatedTool extends WebSearchTool` declares no constructor, so it scores as constructible while its parent may need more. Thirteen such inherited-execute subclasses exist. The true number has to be measured per tool, which is itself an argument for the manifest in §5.2: **readiness cannot be inferred from source shape.**

The same limitation produced two false positives worth recording, because they show why a heuristic scan is a discovery tool and not a gate:

| Class | Scanner verdict | Reality |
|---|---|---|
| `ListProfessionsTool` | `LIKELY_STUB` | Real — uses `$this->p->getAll()`, a property name the scanner did not recognise |
| `ValidateToolSchemaTool` | `LIKELY_STUB` | Real — validates the passed schema and documents that file-based extraction is unavailable in both runtimes |
| `FormatBytesTool` | `NO_EXECUTE` | Real — inherits the `final execute()` of `AbstractResultTool`, which delegates to `executeResult()` |

---

## 3. The two defects

### D1 — Registration is an allowlist, not a policy

`CoreToolCatalogue` states its own contract:

> *"`lib/core` ships ~198 tool classes, but the Laravel provider registered only `color_convert` because most tools need constructor dependencies that are not wired yet."*

That was true when it was written. It is no longer true: the dependencies are bound. The catalogue has not been revisited, so the runtime serves 55 of a possible ~110–128 without anyone deciding the rest are unusable.

**Consequence:** 090-d's "gap 1,424" overstates the distance to parity, and understates what is already built. The real split is §7 of this document.

### D2 — Nineteen shadow tools return success without doing the work

`lib/core/src/Tool/AnalyzeImageTool.php` is the clearest case:

```php
/** Analyze Image — AI vision analysis prompt builder. */
public function execute(...): mixed {
    ...
    return $this->success('Image analysis prompt prepared.', [...]);
}
```

Its `getDescription()` still reads *"Analyzes image content using AI vision to extract descriptions, objects, text, and insights."* The WordPress counterpart, `includes/tools/class-wp-mcp-ai-tool-analyze-image.php`, resolves the attachment, fetches the image and calls the vision provider. Same slug, same description, different capability.

This is **internal tool shadowing** — the same failure class as MCP tool shadowing, where one definition displaces another. OWASP's MCP guidance treats shadowing as an attack pattern; here it would be self-inflicted.

**Severity today: latent, not live.** None of the 19 is registered. The defect is that nothing prevents the next person from adding them to the catalogue — and the catalogue is the only gate.

**How the count moved from 16 to 19.** The first pass searched for the literal `"prompt prepared"`. A second pass searched for any `prepared.` success message and for a returned `'prompt' =>` payload, which found `batch_embed_content`, `run_openai_external_action` and `validate_reasoning_chain`. Two candidates that looked hollow were not: `GenerateSoraVideoTool` and `GenerateHiggsfieldImageTool` reach the provider through short property names (`$this->s`, `$this->h`), and `ContentFreshnessCheckerTool` computes real freshness signals before returning a prompt. The lesson repeats §2.1: a heuristic finds candidates, a reader decides.

---

## 4. Research grounding

| Standard / practice | What it contributes | Design consequence |
|---|---|---|
| **MCP specification — `ToolAnnotations`** (`readOnlyHint`, `destructiveHint`, `idempotentHint`, `openWorldHint`) plus JSON Schema `inputSchema` | An established, client-consumed vocabulary for what a tool does to the world. Clients use the hints to gate confirmation and auto-approval. | Reuse the MCP annotation vocabulary rather than inventing one. Readiness is a separate axis from annotations: annotations say *what effects* a tool has; readiness says *whether this runtime can honour it*. |
| **MCP `tools/list` + `initialize` capability negotiation** | Servers advertise what they serve; clients adapt. | The runtime already advertises route availability through `EndpointCapabilities`. Tools should get the same treatment in `GET /api/v1/tools`, which currently lists only slug/name/description/capability. |
| **OWASP MCP Top 10 (MCP03 tool poisoning) and the MCP Security Cheat Sheet** (tool shadowing, rug pulls) | Named attack classes; the mitigation is an approved allowlist of servers/tools plus definition pinning. | The manifest *is* the allowlist, and it must be reviewed like one. |
| **OWASP Top 10 for LLM Applications — Excessive Agency** (LLM06:2025; renumbered LLM03 in the 2026 edition). Root causes: "excessive functionality, excessive permissions, excessive autonomy". Fix: minimise extensions | Registering a capability you cannot honour is excessive functionality — the assistant can claim an action it did not perform. | Deny-by-default registration. An unclassified tool is unregistered, not assumed ready. |
| **MCP rug-pull literature** — the base handshake carries no tool-definition hash, version pin or change notification | A definition can change after approval and the next session inherits it silently. | Commit a hash of the served-tool manifest and require an explicit diff in review when a served tool's schema or description changes (§5.5, G4). |
| **Kubernetes readiness probes** (distinct from liveness) | A process can be running yet excluded from service endpoints until it reports Ready. This is the model the term "readiness" is borrowed from. | Registration and served-ness are different states: a tool may exist in `lib/core`, classed `unverified`, and still not be served. |
| **OpenAPI/schema contract gates** (`oasdiff`, Spectral, PactFlow) | A deterministic diff gate catches contract drift before merge; the widely-reported failure mode is a gate that fires on prose edits and gets switched off. | Schema gates must exclude description/title prose, or they will be disabled within a release (§5.5). |
| **NIST AI RMF + AI 600-1** (carried over from 090-k) | Measurements must be reproducible and attributable. | The audit is a committed script, not a one-off shell session; its numbers are the acceptance gate (§6, Phase 0). |
| **Consumer-driven contract testing (Pact)** | Consumer expectations are executable, and the provider verifies them. | 090-d already places parity fixtures at `lib/core/tests/Fixtures/tools/<slug>.json`; readiness evidence should point at one. |

---

## 5. Design

### 5.1 Readiness taxonomy

Six values, closed vocabulary. Only the first three are servable.

| Class | Meaning | Servable | Example |
|---|---|---|---|
| `ready` | Real implementation; constructor satisfied; parity evidence recorded. | Yes | `verify_information` |
| `pure` | No I/O by nature; correctness is self-evident or unit-tested. | Yes | `math_eval`, `generate_uuid` |
| `delegated` | The client performs the work; the server half only describes the task. | Yes, annotated `client_side` | `client_summarize_text` |
| `unverified` | Constructible, but no evidence recorded. | **No** | most of the 128 |
| `quarantined` | Describes work it does not perform. | **No** | `analyze_image` |
| `unavailable` | Needs a service this runtime does not bind. | No | tools awaiting a port |

`unverified` is deliberately served as **no**: the default for an unclassified tool is "not ready". That is the LLM06 minimisation rule expressed as a default.

### 5.2 The manifest

One file, `lib/core/resources/tool-readiness.php`, keyed by slug:

```php
'analyze_image' => array(
    'readiness'  => 'quarantined',           // §5.1
    'reason'     => 'Prompt-builder stand-in; the WordPress tool is canonical.',
    'replaced_by'=> 'analyze_image',          // served by the WordPress host
    'annotations'=> array( 'readOnlyHint' => true, 'openWorldHint' => true ),
    'evidence'   => null,                     // test or fixture path once ready
    'since'      => '2.0.0',
),
```

Why a manifest and not attributes or reflection:

- **Reflection cannot see inherited behaviour.** The scanner produced 13 false negatives and 2 false positives from inheritance and property naming alone.
- **Attributes live on classes; the manifest must be readable by both runtimes.** WordPress ships `includes/tools/` without `lib/` (the base build strips it), so a manifest is the only artefact both hosts can read from one source.
- **A manifest is diffable.** That is what makes the hash gate (G4) possible.
- **A manifest can carry a reason.** "This is absent on purpose" is the fact a boolean cannot express — the same lesson `EndpointCapabilities::REASONS` already encodes for routes.

### 5.3 Registration pipeline

```
manifest → resolve class → assert constructible → assert readiness ∈ {ready, pure, delegated} → register
```

Fail-closed at every step, and `CoreToolCatalogue` survives as the *bootstrap* for tools whose readiness is `ready` but whose construction needs a bespoke factory (the 26 service tools today). The catalogue becomes an implementation detail of the pipeline, not the policy.

### 5.4 Quarantine of the 19

Recommended order, cheapest first:

1. **Rename now.** `analyze_image` → `analyze_image_prompt`, and update `getDescription()` to say what it returns. This costs 19 small edits, makes the shadowing impossible, and unblocks the gate. None is registered, so nothing breaks.
2. **Delete or implement, per family.** The image/vision family (8 tools) should delegate to the provider through the already-bound `HttpClientInterface`; `run_openai_external_action` and `generate_post_excerpt` need a decision on whether they belong in core at all.
3. **Record the decision in the manifest**, so a later reader sees why the slug differs from WordPress.

### 5.5 CI gates

| ID | Gate | Fails when |
|---|---|---|
| G1 | **Completeness** | A class declares a tool contract but has no manifest row. (New tools cannot be added silently.) |
| G2 | **Manifest integrity** | A manifest row names a class that does not exist, or a slug declared `ready` on one runtime is `quarantined` on another. |
| G3 | **No free success** | A `ready`/`unverified` tool answers a probe call with a success envelope while touching no service, no IO and no pure computation. |
| G4 | **Definition hash** | The hash of the served-tool manifest changes without a matching diff in the PR body. Excludes `reason`, `description`-adjacent prose — a gate that fires on a typo gets switched off. |
| G5 | **Envelope + sanitisation** | Existing rules already enforced by `WPMCPAI.Tools.CanonicalReturnEnvelope` and `WPMCPAI.Tools.SanitizeAtEntry`. |

G3 is the gate that would have caught D2. It is cheap to implement for the deterministic families and needs a declared expectation for genuinely non-deterministic ones; §10 records that as an open decision.

### 5.6 Write-tool probe contract (ADR M-7)

**Decision.** Write tools are proven by a *refusal*, not by a rehearsal. Two probe tiers, recorded in the manifest's `probe` field:

| Tier | Contract the tool must meet | Probe |
|---|---|---|
| `validate_only` | An incomplete or unauthorised request is refused **before** any store call. | Execute with empty arguments and no auth context; assert a typed refusal and assert the store's row count is unchanged. |
| `dry_run` | A *valid* request with `dry_run: true` returns a plan and mutates nothing. | Opt-in; the tool must implement the argument and the plan payload. |
| *(none)* | Neither | The tool stays `unverified`. |

**Why `validate_only` is the default and `dry_run` is not.** No tool in `lib/core` implements `dry_run` today (measured: 0 of 223). Making it the gate would block every write tool on a product change dressed up as a test. `validate_only` proves the property that actually matters for registration — *an unauthorised or incomplete call writes nothing* — and it is provable without changing any tool.

**Why `dry_run` is still recorded.** It is the established rehearsal pattern (`terraform plan`, `kubectl --dry-run=server`, Ansible `--check`): the caller sees the intended effect before it happens. It is the right forward path for the write families that agents drive autonomously, and the manifest field makes adopting it a per-tool change rather than a schema change.

**Refusal may be returned or thrown.** `create_post` returns an error object from `ErrorFactory`; `create_task` calls `authorize()`, which throws `AccessDeniedException`. Both are typed refusals and both satisfy the contract — WordPress and Laravel signal errors differently, and the probe must not privilege one style.

**Why this is a safety property, not a test detail.** A write tool that mutates before it authorises is excessive autonomy in the sense of OWASP LLM06. The probe pins the *ordering* (authorise → validate → write), which is exactly the bug class that a happy-path test never catches.

**Gap closed.** Every servable tool now carries a probe tier: **65 `call` and 20 `validate_only`**, 0 without evidence. Two tools (`check_capability`, `get_user_info`) are probed in the **app** suite rather than the adapter suite because both read the host `users` table — evidence belongs where the dependency exists, not where it happens to be convenient.

### 5.7 Adoption across toolkits and runtimes (ADR M-8)

**Question.** Should the manifest + probe gate become the default policy for every toolkit and tool, on both WordPress and Laravel? And should `dry_run` be adopted generally?

**Decision.** Yes to the policy, **declaratively**; and `dry_run` phased per family rather than all at once. Four points, in order of consequence.

**1. The manifest covers 223 classes; WordPress serves ~1,747 tools.** Most WordPress tools live in `includes/tools/` and `addons/pro/includes/tools/` and are invisible to `lib/core`. So WP adoption is not a switch: it needs (a) a generated WP-side manifest in the same format, (b) a probe harness in the existing WordPress PHPUnit suite, and (c) a build-time export, because `lib/` is stripped from wp.org builds — `ToolReadiness` already accepts `NVOOS_TOOL_READINESS` for exactly this.

**2. Readiness is per runtime, and the schema does not say so yet.** `check_capability` reads the host `users` table: it is `ready` on Laravel and would be `unverified` on a host without users. Today one row means one verdict for all hosts, so runtime-specific gaps are recorded as `unverified` with a reason (as `get_user_info` was). The next schema change should be `readiness_by_runtime` — `{laravel: ready, wordpress: unavailable}` — so a single row can be honest about both hosts. This is the one genuine limitation of the current design.

**3. Adoption is declarative, not a blocker.** The vocabulary already has `unavailable` for "this host cannot honour it", so registering a family does not require making every tool work everywhere. The rule is: *a tool may be absent; it may not lie.* That is what makes adoption across 1,747 tools tractable — you classify, you do not port.

**4. `dry_run` is a product change, so phase it.**

| Phase | Scope | Status | Why this order |
|---|---|---|---|
| 1 | Every write tool (20) | **Done** | `validate_only` proves authorise → validate → write without touching a tool |
| 2 | Content writes (`create_post`, `save_post`, `update_post`, `delete_post`) | **Done** | Highest consequence, agent-driven, one store |
| 3 | Project/task + classroom/ECA writes | **Core done; WordPress pending** | Same store, same shape — 15 of 16 tools have WordPress counterparts, so parity is its own batch |
| 4 | Pro toolkits as they port | Later | Needs the toolkit manifest engine to carry the argument |
| — | WordPress-only Pro tools | Out of scope | Per-tool work; cannot inherit a shared implementation |

Contract: `dry_run: true` → `{dry_run: true, action, id, would_change, writes: false}`, nothing written. When a tool honours it, its probe upgrades from `validate_only` to `dry_run` and the manifest records the stronger claim.

**Implementation finding (phase 2, measured against the live WordPress runtime).** "Shared in `lib/core` so WordPress gets it the same day" is **only true for tools both runtimes actually share**, and the content writers are not among them. Two corrections came out of the runtime probe:

1. **WordPress registers the `*_Validated` wrappers**, not the base classes — `WP_MCP_AI_Tool_Create_Post_Validated` and `WP_MCP_AI_Tool_Save_Post_Validated`. The wrapper validates arguments into a DTO and then either delegates to the base tool (`create_post`) or **re-implements the write inline** (`save_post`, which calls `wp_insert_post` itself). A guard added only to the base class is therefore silently bypassed — the first probe run **created a post despite `dry_run: true`**.
2. **The DTO drops unknown arguments.** `WP_MCP_AI_Validated_Tool::execute()` maps arguments onto the validation object only when `property_exists()`, so `dry_run` was discarded before any tool code ran. Fixing it required a `dry_run` property on `CreatePostArguments` and `SavePostArguments` in addition to the guard.

So phase 2 landed twice: once in `lib/core` (Laravel inherits it) and once in the WordPress validated path (DTO + `execute_validated`). `delete_post` was the easy case — it is not validated, so the base-class guard sufficed.

**Lesson for phases 3–4:** check which class the registry actually serves before assuming a shared implementation reaches a runtime. The same trap applies to every `*_Validated` family.

**Cost.** Per tool the `dry_run` path is small (build the command, return the plan, skip the store call); per family it is a normal PR. The expensive part is deciding the plan payload shape once, which is why it belongs in the shared package.

### 5.8 Serving it

- `GET /api/v1/tools` gains `readiness` and `annotations` per tool, plus `total` and `servable` counts. Additive, so the current client is unaffected.
- The console Tools page renders a badge per tool and a runtime line in the empty state — closing the open item in `090-d` §9 ("add the runtime tool count to the Tools page empty state").
- WordPress serves the same payload from the same manifest; no new routes. This also removes the `isWordPress` matrix in `runtime-config.ts` for this surface.

---

## 6. Execution plan

PR-sized, in dependency order. Every phase leaves the runtime working.

| Phase | Work | Acceptance |
|---|---|---|
| **0** | Commit the scanner as `bin/audit-tool-readiness.php` in the 090-d house style (`bin/classify-tool-portability.php`). | Reproduces §2 exactly: 223 / 55 / 16. Runs in CI in report mode. |
| **1** | Quarantine the 19 (§5.4 step 1) + manifest skeleton with 55 `ready`/`pure`/`delegated` rows. | Live tool count unchanged at 55. G1 and G2 green. |
| **2** | Register the verified `ready` pool in batches of ~10, measuring the delta per merge. Each batch records evidence. | App suite green; reported count and `servable` agree; no `quarantined` or `unverified` tool registered. |
| **3** | Implement the vision/image family for real against the bound `HttpClientInterface`, then retire their prompt-builder variants. | Each tool has a parity fixture; G3 green. |
| **4** | Expose readiness in `/api/v1/tools` and the console Tools page. | Contract test; smoke shows the count line; 090-d's console note closed. |
| **5** (optional) | WordPress emits the same manifest; `runtime-config.ts` drops the per-backend tool matrix. | One source of truth for both hosts. |

Phase 2 is where the number moves and should not be guessed at. A ~115-tool ceiling exists (§2.1), but registering the upper bound without evidence is the same mistake in the other direction.

---

## 7. Corrected parity picture

Replaces the "55 vs 1,747" framing in `090-d` §9 and my own earlier summary.

| Bucket | Count | Meaning |
|---|---|---|
| Registered and served | 55 → **85** | Verified and in production on Laravel, each with a recorded probe tier |
| Written in `lib/core`, constructible, unregistered | ≤98 | 128 measured at proposal time; 30 since promoted with a probe |
| Written in `lib/core`, needs a service not bound | ~40 | Requires an adapter |
| Written in `lib/core` but a prompt-builder | 19 | Must not be registered under the WordPress slug |
| Port backlog by 090-d tier (A 12 + B 143 + C 413) | 568 | Genuine port work — measured across all 1,603 slugs, so **not additive** with the rows above |
| Should never move (tiers D 632 + E 224) | 856 | By design |

The practical reading: the cheapest large win is **classification plus evidence**, not porting. Wiring the HTTP client — the original item 1 — is already done.

---

## 8. Risks and open decisions

| ID | Risk / question | Recommendation |
|---|---|---|
| M-1 | Renaming the 19 breaks any stored reference to those slugs. | None are registered on either runtime today; grep for the slugs in docs and tests before renaming. |
| M-2 | A manifest can drift from the code (someone edits a class, forgets the row). | G1 fails on an unclassified class; G4 fails on an undeclared definition change. Drift becomes a red build, not a silent rot. |
| M-3 | G3 is hard for non-deterministic tools. | Start with the deterministic families; record an explicit `probe: skip` with a reason for the rest — the reason itself is reviewable. |
| M-4 | Should `unverified` tools be callable behind a feature flag? | No for now. A flag makes the real state harder to see than deleting the tool does. |
| M-5 | Where does WordPress parity evidence live for a core tool? | `lib/core/tests/Fixtures/tools/<slug>.json`, as 090-d T-3 already proposes. |
| M-6 | Per-process manifest parse cost. | One `require` of a PHP array, cached in the registry singleton; measure before optimising. |
| M-7 | Write tools have no `dry_run`, so how can a probe prove safety? | **Decided:** `validate_only` (typed refusal + zero rows written) is the gate; `dry_run` is an opt-in tier recorded in the manifest. See §5.6. |
| M-8 | Should the manifest and probes cover every toolkit on both runtimes? | **Decided:** yes, declaratively (see §5.7). WordPress needs a generated manifest + its own probe harness; `readiness_by_runtime` is the next schema change. |
| M-9 | Does a `lib/core` change reach WordPress automatically? | **No.** WordPress serves `*_Validated` wrappers whose DTOs drop unknown arguments and which sometimes re-implement the write. Phase 2 proved it: the first probe created a post despite `dry_run`. Always check the registered class, then patch the DTO as well as the tool. |
| M-10 | Phase 3 landed in `lib/core` only — is that parity? | **No, and it is recorded as such.** 15 of the 16 phase-3 tools have WordPress counterparts; WordPress parity is a separate batch with the same DTO + wrapper checks M-9 describes. |

---

## 9. Appendix A — the 19 shadow tools

Every one shares its slug with a real class in `includes/tools/`. The first sixteen return a message containing "prompt prepared"; the last three were found by the broader sweep described in §3.

| Slug | `lib/core` class | Behaviour |
|---|---|---|
| `analyze_code_sequence` | `AnalyzeCodeSequenceTool` | returns a code-review prompt |
| `analyze_comment_content` | `AnalyzeCommentContentTool` | returns an analysis prompt |
| `analyze_file_suitability` | `AnalyzeFileSuitabilityTool` | returns a suitability prompt |
| `analyze_image` | `AnalyzeImageTool` | returns a vision prompt |
| `analyze_video` | `AnalyzeVideoTool` | returns a video prompt |
| `auto_categorize_content` | `AutoCategorizeContentTool` | returns a category prompt |
| `content_recommendation_engine` | `ContentRecommendationEngineTool` | returns a recommendations prompt |
| `extract_image_text` | `ExtractImageTextTool` | returns an OCR prompt |
| `generate_image_alt_text` | `GenerateImageAltTextTool` | returns an alt-text prompt |
| `generate_image_caption` | `GenerateImageCaptionTool` | returns a caption prompt |
| `generate_post_excerpt` | `GeneratePostExcerptTool` | returns an excerpt prompt |
| `generate_video_caption` | `GenerateVideoCaptionTool` | returns a caption prompt |
| `seo_meta_optimizer` | `SEOMetaOptimizerTool` | returns an SEO prompt |
| `suggest_internal_links` | `SuggestInternalLinksTool` | returns a linking prompt |
| `vision_object_localization` | `VisionObjectLocalizationTool` | returns a localization prompt |
| `vision_product_search` | `VisionProductSearchTool` | returns a product-search prompt |
| `batch_embed_content` | `BatchEmbedContentTool` | returns an embedding request prompt |
| `run_openai_external_action` | `RunOpenAIExternalActionTool` | returns an action prompt |
| `validate_reasoning_chain` | `ValidateReasoningChainTool` | returns a review prompt |

---

## 10. Appendix B — method and reproduction

The measurement above came from a throwaway scanner (deleted after use) that:

1. walked `lib/core/src/Tool` recursively, skipping abstract classes;
2. kept classes declaring a tool contract (`extends AbstractTool` / `implements ToolInterface` / `getSlug()`);
3. parsed each constructor's parameter types with balanced-paren scanning and diffed them against the interfaces bound in `NvoosServiceProvider`;
4. extracted each `execute()` body with balanced-brace matching and flagged it `REAL` (service/IO call), `PURE_COMPUTE`, `LIKELY_STUB` (short body, no service, stub phrasing), or `NO_EXECUTE`;
5. cross-checked the prompt-builder slugs against `includes/tools/` and `addons/pro/includes/tools/`.

Validation that the scanner was measuring the right thing: its parsed registered set is 55, which equals `ToolRegistry::all()` from the running container.

Known blind spots, recorded so Phase 0 does not repeat them: inherited constructors, inherited `execute()` (`AbstractResultTool`), and property names that do not match a service type (`$this->p`).

---

## 11. Addendum: the discovery tool (2026-11-23)

### The gap

`ToolPresetCatalogue` listed `list_mcp_tools` and `mcp_server_info` in its
discovery family, and `ToolCapabilityFlagCatalogue` carried flags for those
slugs plus `get_site_health` and `get_system_logs`. None of the four had a
readiness row, so none was served: a preset could advertise a discovery step
that failed on its first call, and the capability catalogue described a tool the
runtime could not build.

That is the same failure mode §5.4 quarantined the prompt-builders for, one
layer up — a slug in the data that is not a slug in the registry.

### This slice: `list_mcp_tools`

`Nvoos\Core\Tool\ListMcpToolsTool`, manifest row `ready`, one dependency: the
`ToolRegistry` singleton. The registrar resolves it while the registry is being
filled, which is safe because the tool reads it at execute time, not at
construction.

Decisions worth recording:

- **It reads the live registry, not the manifest.** The manifest is policy —
  which slugs *may* be served. The registry is the fact: what this host built.
  A catalogue built from the manifest would list tools the container skipped for
  an unbound service, which is precisely the drift `ToolReadiness::servable()`
  exists to prevent.
- **It never lists itself.** The model already knows the tool it just called,
  and the self-row is the recursion hazard in visible form.
- **Sorted by slug.** Registration order is a host implementation detail; a
  catalogue that reshuffles between boots is hard to diff and hard to page.
- **`total` is the pre-page total.** A paging client that reads `count` as the
  total stops early.
- **No `toolkit` filter and no `_meta` block.** WordPress derives a toolkit from
  the declaring folder (`tools/<toolkit>/`), which core tools do not have, and
  `_meta` carries WordPress identity this runtime has not yet defined. Both are
  omitted rather than accepted and left empty.
- **Risk levels** (`high`/`medium`/`low`/`info`) use the same four values as the
  WordPress tool, from the tool's declared flags with
  `ToolCapabilityFlagCatalogue::for()` as the fallback, so a client does not
  need a per-runtime mapping.

A docblock trap worth noting: the first draft of the class comment contained
`*/tools/` inside backticks. `*/` closes a block comment regardless of context,
so the file failed to parse. The phrase is now `tools/<toolkit>/`.

### Not done, named

- **`mcp_server_info`** — the WordPress payload is WordPress identity: plugin
  version, distribution mode, site name and URL, `rest_url( 'mcp-ai/v1/mcp' )`.
  This runtime has no MCP JSON-RPC endpoint and no identity contract, so the tool
  needs a `RuntimeIdentityInterface` (name, version, runtime, base URL) and a
  host implementation first. Implementing it from constants would be half an
  answer to a question an agent asks specifically because it has several
  connections.
- **`get_site_health`** — the app has `App\Support\Health\*` and a
  `SiteHealthPage`; a core tool would need a health-report contract so the tool
  does not depend on `App\`.
- **`get_system_logs`** — same shape: a log-source contract over Laravel's log
  channel and the observability store.

### Validation

`ListMcpToolsToolTest` (10 unit tests: ordering and self-exclusion, pre-page
total, limit clamping, search on slug and description, disabled tools absent,
`include_schemas`, `tool_slug`, unknown slug, self-slug fall-through, risk
mapping) and `docker/laravel/app-skeleton/tests/Feature/ListMcpToolsToolTest.php`
(3 tests: the container builds it, it lists this host's real surface, it fetches
one definition). `lib/core` 2006 tests / 15,807 assertions, adapter suite 348 /
1,826, skeleton registration and diagnostics 17 — all green. phpcs clean.

---

## 12. Addendum: host health as a tool (2026-11-23)

### The gap, again the same shape

`get_site_health` was named by four presets and carried flags in
`ToolCapabilityFlagCatalogue`, but had no readiness row. The Laravel console had
a `/ops/site-health` page — an operator could see the probes, an agent could not.

### The contract, and why the split is where it is

Health checks are **host facts**. The Laravel app knows its database, cache,
queue, scheduler, providers and security flags; the package host knows only
whether the manifest's tools registered and whether a default model is set.
`Nvoos\Core\Domain\Contract\HealthReportInterface` is the seam:

| Host | Binding | Probes |
|---|---|---|
| Package (`NvoosServiceProvider`) | `Nvoos\Laravel\Health\PackageHealthReport` | `tools`, `settings` |
| App (`AppServiceProvider`) | `App\Support\Health\SiteHealthReport` | the six the page renders |

The app adapter does nothing but call `SiteHealth::probes()`, so the page and the
tool cannot drift: one producer, two readers. Reporting a probe a host cannot run
would be worse than reporting fewer — a synthetic "database: good" on a host with
no database is a lie an agent would act on.

The app binding replaces the package binding because package discovery registers
`NvoosServiceProvider` before `bootstrap/providers.php`; the same override pattern
the app already uses for five other contracts.

### Aggregation rules

- **`critical` wins.** One critical probe makes the report critical.
- **`recommended` downgrades but does not fail.** The vocabulary is WordPress's
  (`good`, `recommended`, `critical`), so an operator who has seen Site Health
  does not learn a second one.
- **A status outside the vocabulary is not a pass.** It is counted as
  `recommended` and passed through unchanged in the probe list — the same
  "absence means unknown, not safe" rule the capability-flag catalogue states for
  undeclared flags.
- **A probe with no status is not a pass** for the same reason.
- **An empty report is `good`**, not an error: a host with nothing to check has
  nothing wrong.

Capability is `edit_posts`, matching the WordPress tool. That is not a hole: the
contract's docblock makes "never return a secret" a rule of the interface, and
every probe reads configuration *flags* (is debug on, is a key set, which
provider slug) rather than values.

### Not done, named

- **`get_system_logs`** — needs a log-source contract first, and a decision about
  which store answers: Laravel's log channel, the observability trace store, or
  both. The WordPress tool exposes `activity_limit`, `activity_types` and
  `since`; picking a backend is the work, not shaping the payload.
- **`mcp_server_info`** — still needs the runtime identity contract described in
  §11.

### Validation

`GetSiteHealthToolTest` (6 unit tests: all-good, critical wins, recommendation
downgrades, out-of-vocabulary status, missing status, empty report) and
`docker/laravel/app-skeleton/tests/Feature/GetSiteHealthToolTest.php` (2 tests:
the container builds it, and the app probe set — not the package default — is
what answers). `lib/core` 2014 tests / 15,832 assertions; adapter suite 348 /
1,827; skeleton health + registration 14. phpcs clean on all nine files.

---

## 13. Addendum: system logs from stores, not files (2026-11-23)

### The gap

`get_system_logs` was named by presets and carried flags, with no readiness row.
The app had the evidence all along — `/ops/audit-log` reads the append-only
`nvoos_admin_audit` trail and the observability page reads
`nvoos_harness_trace_records` — but an agent debugging a failed call could read
neither.

### Why this is not a `debug.log` port

The WordPress tool's arguments describe a filesystem: `include_debug_log`,
`debug_log_bytes`, `plugin_log_directories`, `plugin_log_depth`,
`plugin_log_bytes`. Those are not ported. A log tail is unstructured, the path is
host-specific, and pasting it into a model's context leaks absolute paths and
hostnames. The two structured stores answer the same questions with fields.

The portable arguments are kept, including the WordPress name `activity_limit`,
because an agent that has read the WordPress schema will send it. The
file-scanning arguments are omitted rather than accepted and ignored: a parameter
that silently does nothing is worse than a parameter that is not offered.

**Capability is `manage_options`**, not the WordPress tool's declared
`edit_posts`. The trail is operator-only here (the page is operator-gated and
cross-estate), and the WordPress tool enforces `manage_options` inside
`execute()` anyway — the declaration now matches the effective rule.

### The contract

`Nvoos\Core\Domain\Contract\SystemLogInterface` carries entries, not lines:

| Field | Meaning |
|---|---|
| `id` | stable, namespaced (`audit:41`, `run:01H…`) |
| `at` | ISO 8601 UTC |
| `source` | which store answered (`audit`, `traces`) |
| `type` | `audit.record`, `audit.request`, `run.incomplete`, `run.aborted` |
| `level` | `critical`, `error`, `warning`, `notice`, `deprecated` |
| `summary`, `actor`, `detail` | what a reader needs without a second query |

`levels` reuses the WordPress vocabulary so a caller's existing filter works.
`sources()` is the answer, not a hint: a host with no queryable log returns an
empty list and the tool says "this host keeps no queryable system log" — an empty
list that reads as "nothing happened" is the failure mode this avoids.

The tool re-applies `types`, `levels`, `since` and `search` after the host
returns, because a host implementation is not the place to discover that a filter
was advisory. The stores are read bounded (the tool caps at 100, the audit reader
at 200) and sorted newest-first across sources.

### The two sources, and the level mapping

- **audit** — `AuditTrail::rows()`, the reader the `/ops/audit-log` page already
  uses. Both row kinds are included: record rows ("what changed") and request
  rows ("who tried and was refused"). `5xx` → `error`, `4xx` → `warning`, `2xx`
  → `notice`, and a record row → `notice`.
- **traces** — `TraceRuns::recent()`. Only `incomplete` (no `end` record) and
  `aborted` runs become entries: `error` and `warning` respectively. A completed
  run is not a log event, and fifty healthy turns would bury the two that failed.

`AuditTrail` gained a `since` filter (`created_at >= ?`). The page does not pass
it, so its behaviour is unchanged; the tool needs the predicate *before* the
limit, because filtering after a bounded read under-returns silently.

### The nullable collaborator, on purpose

`GetSystemLogsTool` takes `?SystemLogInterface $log = null`. That is the
registrar's documented "this host has no such service" seam: `class_exists()` is
false for an interface, so an unbound optional collaborator receives its default
instead of failing the build. The adapter suite therefore constructs the tool
with `null` and registers it; `AppServiceProvider` binds the app implementation,
so the same tool answers with two sources here. Verified in the skeleton test,
which asserts `sources === ['audit','traces']`.

### Validation

`GetSystemLogsToolTest` (core, 9 tests: ordering and cap, limit clamp, type/level
re-filtering against a host that ignores them, search over summary/actor/detail,
`2h` and bare-number windows captured from the host query, unparseable `since`,
null service, empty source list) and
`docker/laravel/app-skeleton/tests/Feature/GetSystemLogsToolTest.php` (3 tests
against real rows: the container builds it, a 403 request row becomes a `warning`
with `(system)` as actor, a change row becomes a `notice` with `has_diff`, and
type/level filters apply). `lib/core` 2025 / 15,865; adapter 348 / 1,828;
skeleton tool + registration 9. phpcs clean on all eight files.

`mcp_server_info` remains the only member of the original four with no manifest
row; it still needs the runtime identity contract described in §11.

---

## 14. Addendum: runtime identity for `mcp_server_info` (2026-11-23)

### The gap

The last of the four preset-named introspection tools with no manifest row. The
app already described itself in `DiagnosticsController` —
`rest_context: { backend: laravel, contract: mcp-ai/v1 }` — but an agent could
not ask. An agent with several connections uses this tool to tell one backend
from another before it acts.

### What is reported, and the two WordPress fields that are not

`RuntimeIdentityInterface` carries `name`, `version`, `runtime`, `contract`,
`site_name`, `site_url`, `api_base`.

Two fields from the WordPress payload are deliberately absent:

- **`mode`** (`base` vs `complete`) is a WordPress distribution concept with no
  equivalent here.
- **`mcp_endpoint`** names a JSON-RPC mount this runtime does not serve.
  `api_base` + `contract` answer the same question — where the API lives and
  what it speaks — without pointing a caller at a route that 404s. The contract
  value is the one `DiagnosticsController` already declares, so the two surfaces
  cannot disagree.

`bridge_name` is also absent: the WordPress tool reads it from an
`X-MCP-Bridge-Name` header on the MCP surface, which this runtime has no
equivalent of.

### The version key that was never declared

Four readers already did `config('app.version', '1.2.0')` — the diagnostics page,
the diagnostics controller (twice) and assistant portability — but
`config/app.php` had **no `version` key**, so every one of them silently used the
fallback. §14 declares it:

```php
'version' => env('APP_VERSION', '1.2.0'),
```

The default is the value those readers already assumed, so nothing changes for a
deployment that does not set `APP_VERSION`; it simply becomes configurable, and
the identity tool reads the same key rather than inventing a constant.

### Shape and secrecy

- **The schema is guaranteed.** The tool casts every field and fills absent ones
  with `''`, so a host that returns a partial array still produces the seven keys
  its schema promises.
- **No secrets, no redaction pass.** Every field is a public identifier, and the
  contract makes "no secrets" a rule rather than an implementation detail. The
  feature test walks the payload and asserts no credential-shaped key
  (`token`, `secret`, `password`, `api_key`, `authorization`, `email`) appears —
  a guard against a future host adding one.
- **Capability `read`**, matching the WordPress tool. Unlike the audit trail,
  identity is not operator-only: it is the same information a login page already
  discloses.
- **Nullable collaborator**, the §13 seam: the package has no deployment, so it
  registers the tool with `null` and the tool says so; `AppServiceProvider` binds
  `App\Support\Identity\RuntimeIdentity`.

### Validation

`McpServerInfoToolTest` (core, 5 tests: full identity, no host identity, partial
identity still shaped, no-argument schema, capability/flags) and
`docker/laravel/app-skeleton/tests/Feature/McpServerInfoToolTest.php` (3 tests:
the container builds it, the values match this deployment — including
`contract: mcp-ai/v1` and an `api_base` ending `/api/v1` — and no
credential-shaped keys). `lib/core` 2032 / 15,894; adapter 348 / 1,829;
skeleton identity + registration + diagnostics 17. phpcs clean on all eight
files.

This closes the original four. `list_mcp_tools`, `get_site_health`,
`get_system_logs` and `mcp_server_info` all have manifest rows and evidence; the
next gap in the queue is the assistant / research lifecycle tools, then memory
extras and WP-shaped media naming.

---

## 15. Addendum: the queue reviewed, and a validated `replaced_by` (2026-11-23)

### What the review found

The four introspection tools are served (§11–§14) and the readiness auditor now
reports **223 classes, 227 rows, 208 servable, no violations**. So there is no
"cheap row" left: every remaining gap needs a design decision or a new store, not
a classification. Recording the findings so the next slice starts from evidence:

| Candidate | Why it is not a slice |
|---|---|
| `trace_memory_provenance` | Needs version history and a graph neighbourhood. `MemoryStoreInterface` has `store/get/update/delete/search/listByAgent` — no history, no graph bridge. Implementing it would mean inventing history. |
| `memory_audit_trail` | There is no memory-write audit store. `nvoos_admin_audit` records operator/admin actions, not memory operations, so reading it here would misattribute the source. |
| `batch_manage_memory` | A large destructive surface: bulk update, bulk delete, export, import and three tag operations, with filters and `dry_run`. It needs its own safety design (caps, per-id outcomes, confirmation), not a wrapper. |
| `create_assistant` | `AssistantServiceInterface` is read-only (`validate`, `getConfig`, `getDefault`, `listForUser`). A create/update tool needs a write contract and estate-scoping rules first. |
| `analyze_image` / `analyze_video` / `analyze_code_sequence` | Provider dispatch with cost and privacy consequences, and the biggest of the queue. M, not S. |
| WP-shaped media CRUD names | WordPress `get_media` returns an attachment payload; `get_file_info` returns storage metadata. An alias would lie about the shape. |
| `chat-profiles` console key | No SPA chat-profile UI exists, so the key would be a control with no reader. It has to ship with the switcher. |
| Higgsfield async persistence | Persisting provider-returned URLs means fetching them, which needs an https-only host allowlist and a size cap first. |
| A2A / mesh | Needs a transport decision. |

This is why §15 does something small and certain instead of half of an M.

### The gap: a cross-host pointer with no check

`replaced_by` is a **cross-host** pointer: it names the canonical slug on the
WordPress host (`analyze_image_prompt` → `analyze_image`), which is what §5.2
documented. Nothing validated it, so a typo in one of the 19 values would send a
reader to a slug no host serves — the same failure the quarantined rows exist to
prevent, one step later.

The current values all resolve. Checked against the auditor's WordPress map (356
slugs indexed from `includes/tools` + `addons/pro/includes/tools`): 19 of 19
found. So this is a gate, not a data repair — and the first draft of this section
claimed a typo (`generate_image_captiont`) that `git log -S` shows never existed;
the "finding" was interleaved terminal output, not the file.

Added:

- The manifest header states what `replaced_by` means and where it is checked.
- `bin/audit-tool-readiness.php` reports a new **G2 replacement** violation when a
  target resolves to neither a WordPress tool nor a manifest row. The WordPress
  map is the one the auditor already builds for the shadow check
  (`includes/tools` + `addons/pro/includes/tools`), so the gate costs one lookup
  per row and no new dependency.

The rule is not in `ToolReadiness::violations()`: core ships to hosts that have no
WordPress corpus, and validating a cross-host pointer without the other host is
how a check becomes a guess. The auditor owns the corpus, so the auditor owns the
check.

Proven by injecting a bad pointer into a synthetic manifest:

```
G2 replaced_by names a slug no host serves (1):
  - demo_replacement => replaced_by 'no_such_wordpress_slug' is not a WordPress tool or a manifest row
```

and the committed manifest audits clean (223 / 227 / 208, no violations).

### The next real slice, named

`create_assistant` is the highest-value item that is implementable next, and it
begins with a contract rather than a tool: an `AssistantWriteInterface` with
create/update semantics, estate scoping, and the same confirm-then-apply shape as
the root-key-gated operator writes. That is the decision §16 should open with.

---

## 16. Addendum: create_assistant, on the one writer (2026-11-23)

### The slice

`create_assistant` was named by two presets and had no row. The app already had
one write path — `App\Services\AssistantWriter`, created because the REST
controller and the `/admin` resource would otherwise have been two
implementations of the same five invariants — so this is the **third transport
onto that path**, not a second writer:

```
tool ──► AssistantWriteInterface ──► LaravelAssistantWriter ──► AssistantWriter ──► Assistant
REST ──────────────────────────────────────────────────────────► AssistantWriter
/admin ────────────────────────────────────────────────────────► AssistantWriter
```

`AssistantWriteInterface` carries `title`, `description`, `system_prompt`,
`provider`, `model`, `temperature`, `max_tokens`, `tools` and returns
`{success, assistant_id, slug, status}`. The writer keeps what a transport cannot
be trusted with: **estate from `TenantContext`, never the payload**; slug derived
and de-duplicated per estate (`(tenant_id, slug)` is the index); `status` defaults
to draft; `is_default` exclusivity; profession and `advanced` flattening.

### What the tool withholds, and why

| Field | WordPress allows it | Here | Why |
|---|---|---|---|
| `status` | yes | **no** | Publishing is an operator action. A model that can mint a live assistant can grant itself a capability in one call. |
| `is_default` | via the REST form | **no** | Promoting one assistant clears the flag on every other in the estate — wide blast radius for a tool argument. |
| `slug` | yes | **no** | Derived from the title and made unique per estate; exposing it would let a caller choose a collision. |
| `professions` / `regions` | yes (enums) | **no** | WordPress taxonomy. The REST transport validates `primary_role_slugs` against the profession catalogue; the tool defers that until it has the catalogue dependency. |
| knowledge-base attachments, `async` | yes | **no** | No equivalent feature, and no cron path for assistant creation. |

### Provider and model resolution

- Omitted provider → the site default.
- Omitted model → the site default **only when the provider is also the default
  one**. Pairing `gpt-4o` with `gemini` is how a broken assistant gets created
  quietly.
- A supplied provider is checked against `ProviderRouter::getRegisteredSlugs()`
  **when the router knows any**. An empty registry means provider registration has
  not run yet; rejecting every slug then would make a fresh install unable to
  create an assistant at all. An unknown provider is refused at create time
  rather than failing at the assistant's first turn.

### Capability and failure shape

`edit_posts`, which is what `AssistantPolicy::create`
(`hasNonViewerMembership()`) already requires and what the WordPress tool
declares. Flags: `write`, `state-changing`, `local-only`,
`requires-capability`.

A writer exception becomes `assistant_create_failed` with a generic sentence:
the adapter calls `report()` and never passes a driver message — column names,
SQL fragments or paths — into a transcript.

### Not done, named

`create_assistant_validated` (schema-strict variant), `duplicate_assistant`,
`export_assistant` and `import_assistant`. Import already exists as the
portability REST surface; the others are separate tools with their own argument
contracts, not aliases of this one.

### Validation

`CreateAssistantToolTest` (core, 11 tests: title gate, draft creation and
reported fields, never-sends `status`/`is_default`/`slug`, provider/model
defaults, non-default provider does not borrow the default model, unknown
provider refused, empty registry tolerated, invalid tool slugs refused,
deduplication, no-writer host, writer failure). Feature test
`docker/laravel/app-skeleton/tests/Feature/CreateAssistantToolTest.php` (3 tests
on the real writer: the container builds it, the row lands as a draft in the
current estate with the supplied tools, a repeated title gets `-2`).
`lib/core` 2045 / 15,931; adapter 348 / 1,830; skeleton creation + registration +
diagnostics 17. phpcs clean on all six files.

## 17. Addendum: duplicate_assistant, a copy that is not a migration (2026-11-23)

### The slice

`duplicate_assistant` was named by two presets and had no row. WordPress
implements duplication as **export the bundle, import the bundle** — the right
shape for crossing a site boundary, and heavy for a copy: the bundle exists to
survive a transport this operation never touches.

This is a read-then-create **inside one estate**, on the same writer §16 put in
place:

```
tool ──► AssistantWriteInterface::duplicate() ──► LaravelAssistantWriter ──► AssistantWriter::create()
```

`duplicate()` was added to `AssistantWriteInterface` rather than the tool reading
`Assistant` itself. A tool that queried the model would have been the second
place that knows an assistant's tenancy rule, and the first place to forget it.

The adapter reads with `Assistant::query()->find()`, so the model's tenant global
scope supplies the estate boundary — the tool passes an id and nothing else.

### What is not copied, and why

| Field | Copied | Why |
|---|---|---|
| `description`, `system_prompt`, `provider`, `model`, `temperature`, `max_tokens`, `tools`, `meta` | **yes** | This is the assistant. |
| `status` | **no** | WordPress lets a duplicate be published in the same call. A duplicate here is a draft; publishing stays the separate operator action it is on `create_assistant`. |
| `is_default` | **no** | Copying it would either create a second default or silently un-default the source. Either way the result is not a copy. |
| `slug` | **no** | Derived from the new title and de-duplicated per estate, so `support-bot` copies to `support-bot-copy`. |
| ids, timestamps | **no** | Properties of the source, not of a new record. |
| credentials | **no** | Nothing to copy by design: issuance is a separate service, and the writer's `advanced` whitelist excludes them. |

The draft is an **explicit allowlist**, not `$source->getAttributes()`. A column
added to `assistants` next quarter is not silently copied by this tool because
nobody decided it should be.

An omitted title lets the host name the copy (`"<original> (Copy)"`), which keeps
the naming rule on the side that also owns slug derivation.

### A source in another estate is *not found*

`404`, not `403`. A forbidden would confirm that an assistant with that id exists
somewhere — which is precisely the fact a cross-tenant probe is asking for. The
global scope makes the foreign id indistinguishable from a missing one, and the
tool maps `assistant_not_found` to `errors->notFound()`.

### Capability and failure shape

`edit_posts`, matching `create_assistant` and `AssistantPolicy::create`. Flags:
`write`, `state-changing`, `local-only`, `requires-capability`; annotations
`readOnlyHint: false`, `destructiveHint: false`, `openWorldHint: false` — a draft
appears in the estate, nothing is overwritten or removed.

A write exception becomes `assistant_duplicate_failed` with a generic sentence;
the adapter calls `report()` and never puts a driver message in a transcript. The
nullable `?AssistantWriteInterface $assistants = null` collaborator is the same
"this host has no such service" seam as §13/§14/§16 — a host without assistant
writes says so instead of erroring on an unbound interface.

### Validation

`DuplicateAssistantToolTest` (core, 8 tests: id required, not-found mapping,
host failure mapping, title passed through and copy returned, omitted title
passed empty, no-writer host, capability declaration). Feature test
`docker/laravel/app-skeleton/tests/Feature/DuplicateAssistantToolTest.php`
(5 tests on the real writer: the container builds it, the copy lands as a draft
with prompt/provider/tools/meta intact and a distinct id, a supplied title wins,
duplicating the estate default leaves the flag on the source, a foreign id is
404 **and creates nothing** in the current estate).
`lib/core` 2054 / 15,966; adapter 348 / 1,831; skeleton duplicate + registration +
diagnostics 19. Auditor 229 rows / 210 servable, no violations. phpcs clean on
all six files.

### Still queued after this

`export_assistant` and `import_assistant` (the portability REST surface exists;
import needs a duplicate-vs-merge decision), `create_assistant_validated` (what
it checks that `create_assistant` does not is undecided), and the memory trio
(`trace_memory_provenance`, `memory_audit_trail`, `batch_manage_memory`) which
need version history, a write-audit store and a destructive-bulk safety design
respectively.

## 18. Addendum: export_assistant, and the denylist it exposed (2026-11-23)

### The slice

`export_assistant` was named by two presets and had no row. The app could
already export the estate from `AssistantPortabilityController` and from the
`/admin` portability page; an agent could not.

The tool is a third transport onto the one engine, not a third bundle builder:

```
tool ──► AssistantExportInterface ──► LaravelAssistantExporter ──► AssistantPortability::export()
REST  ────────────────────────────────────────────────────────────► AssistantPortability::export()
/admin ───────────────────────────────────────────────────────────► AssistantPortability::export()
```

The reason is not tidiness. The `nvoos-assistant` v1 shape **and the credential
denylist** live in that engine, and the portability skill's rule is explicit:
every surface delegates, because a surface that builds its own meta loop drifts
from the denylist the first time one of them changes. `export()` already reads
through `estate()`, so the estate stays ambient and there is no tenant argument
for a caller to get wrong.

### The gap this slice found

The Laravel engine had **no denylist at all**. `meta` round-trips verbatim —
which is exactly what makes a WordPress bundle importable here — so an assistant
created by importing a hand-edited bundle could hold `_wp_mcp_ai_credentials`
and an `_wp_mcp_ai_mcp_apps` entry carrying a live `token` / `oauth_data`, and
`export()` returned them to every surface.

Export-side redaction now lives in the engine (`redact()`), so the panel, the
REST route and the tool produce the same bundle:

| Key | Treatment |
|---|---|
| `_wp_mcp_ai_credentials` | removed from `meta` |
| `_wp_mcp_ai_mcp_apps[].token`, `.oauth_data` | removed per entry; the entry itself survives so the receiving runtime can still see *what* was connected and issue its own |

**The import side is still open** and is recorded as a separate issue rather
than fixed here: `import()` writes `meta` verbatim, so a hand-edited bundle can
still plant a denylisted key. That is a change to import semantics with
round-trip fidelity consequences, and it deserves its own tests rather than a
line in this slice.

### Formats: one, named

WordPress offers `json`, `a2a` and `blueprint` and embeds A2A cards by default.
This runtime has no A2A agent card and no Pro blueprint installer, so `json` is
the only dialect that can be produced honestly. A caller asking for another is
**refused with a sentence that names why** — returning a JSON bundle to someone
who asked for an A2A card is a wrong answer delivered confidently.

`include_a2a` is deliberately absent rather than accepted-and-ignored. A
parameter that cannot change the output is a lying surface.

### The inline ceiling

A tool result is not a file download: it becomes part of the conversation, and
every later turn pays for it. An estate of 32k-character system prompts exported
in one call would exhaust the context window of the model that asked for it, and
the bundle would arrive truncated by the transport rather than refused by us.
Above **256 KB** of encoded bundle the call is refused with the batch size to use
instead. The REST route and the panel page stream the same bundle with no such
limit — the ceiling is a property of the chat surface, not of the export.

### Cross-estate, again as *not found*

`export()`'s only documented refusal is an id that is not in the estate, so a
`BundleException` maps to `assistant_not_found` → `errors->notFound()` (404) —
the same status §17 uses for a foreign duplicate source. Two tools that disagreed
about whether an id exists elsewhere would be a cross-estate oracle built out of
their difference. The feature test asserts a foreign id and a missing id return
an identical status.

### Flags: `idempotent`, not `cacheable`

Two exports with no intervening write produce the same bundle, so the call is
safe to retry. It is *not* cacheable in spirit: an assistant created a second ago
must appear in the next export, and a cached export would hide exactly the change
an operator is trying to confirm. WordPress declares `cacheable`; this is a
considered deviation.

### Not done, named

`save_as_attachment` (WordPress's opt-in to write the bundle to the media
library). The file registry (§162) can carry it, but it needs a bytes → temp file
→ `FileVisibilityStoreInterface` collaborator, and the ceiling message already
gives the batching route. `a2a` and `blueprint` dialects have no surface.
`import_assistant` is next, and needs a duplicate-vs-merge decision plus the
import-side denylist above.

### Validation

`ExportAssistantToolTest` (core, 10 tests: export-all passes `null`, an explicit
id list is forwarded, an empty list means all, the dialect is case-insensitive,
`a2a` is refused **without reaching the engine**, unknown id → 404, host failure
→ its own code, host without an exporter, over-ceiling refused with a batch hint,
capability flags). Feature test
`docker/laravel/app-skeleton/tests/Feature/ExportAssistantToolTest.php` (6 tests
on the real engine: container registration, the v1 bundle shape, id filtering,
**credential and MCP-App tokens absent while the connection survives**, foreign
and missing ids indistinguishable at 404 with nothing leaked, `blueprint` refused
at 422). `lib/core` 2066 / 16,009; adapter 348 / 1,832; skeleton export +
portability + registration + diagnostics + duplicate 45 / 171. Auditor 230 rows /
211 servable, no violations. phpcs clean on all eight files.

## 19. Addendum: import_assistant, and the sanitiser that would have eaten the prompts (2026-11-23)

### The slice

`import_assistant` was named by two presets and had no row. The app could import
from the REST portability controller and the `/admin` portability page; an agent
could not.

The tool is a third transport onto `App\Services\AssistantPortability`:

```
tool ──► AssistantImportInterface ──► LaravelAssistantImporter ──► AssistantPortability::importJson()
REST  ────────────────────────────────────────────────────────────► AssistantPortability::importJson()
/admin ───────────────────────────────────────────────────────────► AssistantPortability::importJson()
```

**Not** a loop over `create_assistant`. A bundle is a document with a shape, a
version and conflict semantics; creating each entry individually would ignore
`format_version`, create duplicates instead of matching existing assistants, and
cannot express `skip` at all.

### `manage_options`, because overwrite *is* restore

`overwrite` replaces an existing assistant's prompt, provider and tool list. That
is not a side effect — it is what restore is, and it is why WordPress gates this
tool on `manage_options` while `create_assistant` needs only `edit_posts`.
`edit_posts` may add an assistant; it may not replace one. The approvals queue
picks the call up from the `state-changing` flag.

### Modes are refused, never coerced

The engine falls back to `skip` for a mode it does not know. Left to itself that
would make an unknown mode silently mean *did nothing*, and a caller that asked
to overwrite would be told it had. The tool refuses the mode before the engine
sees it, naming the three it accepts.

`status_override` is **not** offered. A bundle records each assistant's status
and restore puts it back — a restore that levelled everything to draft would not
be a restore. An override would be a way to *change* status during a restore,
which is the operation `create_assistant` withholds, so the document is the only
source of status.

`confirm_destructive` is **not** offered either: nothing in this runtime reads
it. Confirmation is the approvals queue's job, and a parameter with no consumer
is a lying surface.

### The sanitiser that would have eaten the prompts

The first draft read the payload through `AbstractTool::stringParam()`. That
helper is right for a value typed by a human into a field and wrong for a
document: it runs **`strip_tags()`** on the whole string. A bundle whose system
prompt contains `<b>` or `<script>` would have arrived at the engine with those
fragments removed — silently, and only inside the prompts. The tool now reads
`$arguments['json']` directly, type-checks it, trims the edges, and lets the
engine decide whether it is a bundle. A unit test pins a document containing HTML
reaching the engine byte-for-byte.

This is the two-gate rule applied honestly: sanitise *at entry* means establish
type and shape, not rewrite content the caller is responsible for. A transport
document is not user-facing HTML.

### Input ceiling vs output ceiling

2 MB, matching WordPress. It is a different guard from §18's 256 KB: an export's
ceiling protects a conversation from a result it must carry for every later turn,
while an import's payload is already in the conversation because the caller sent
it. The report that comes back is small regardless of bundle size.

### Two failure classes, and why this mapping differs from §18

| Failure | Code | Status |
|---|---|---|
| not JSON, not a bundle, wrong version, empty | `bundle_invalid` | **422** |
| host failure (database, disk) | `assistant_import_failed` | its own code |

Every import refusal is about the payload, so 422 is right and replaying the
bytes will fail the same way. `export_assistant` maps its one refusal to 404
instead, because that one is about a record — the difference is the meaning, not
an inconsistency.

### Not done, named

`file_id` / attachment input. WordPress offers `attachment_id` because it has a
media library; the file registry (§162) can carry the equivalent here, but it
needs an ownership check and a read path that this slice did not need, and
pasting a document is the path an agent actually takes.

### Validation

`ImportAssistantToolTest` (core, 11 tests: document required, over-ceiling
refused, unknown mode refused **without reaching the engine**, `skip` is the
default, mode is case-insensitive, `dry_run` forwarded and reflected in the
message, `bundle_invalid` → 422 carrying the host's reason, host failure → its
own code, host without an importer, `manage_options` + no `reversible` flag, and
the document arriving unsanitised). Feature test
`docker/laravel/app-skeleton/tests/Feature/ImportAssistantToolTest.php` (7 tests
on the real engine: container registration, a dry run reporting `imported: 1`
while writing nothing, `skip` leaving the stored prompt untouched, `duplicate`
producing two rows, `overwrite` replacing the prompt in place, `not a bundle` →
422, and an **editor refused with 403** through the registry's capability gate).
`lib/core` 2079 / 16,068; adapter 348 / 1,833; skeleton import + import-redaction
+ export + portability + registration + diagnostics 51 / 191. Auditor 231 rows /
212 servable, no violations. phpcs clean on all seven files.

## 20. Addendum: analyze_image, and the transport that was already there (2026-11-23)

### The slice

`analyze_image` was the first of the **19 `replaced_by` promises** — the WP slugs
that only had a `*_prompt` stand-in, quarantined in the manifest with a pointer to
the tool they stand in for. `analyze_image_prompt` has carried
`replaced_by: analyze_image` since §15; this is what it pointed at.

### The finding: no new provider surface was needed

The plan going in assumed a new "run this prompt through a model" contract, because
`AgentRunnerInterface` is not it — `run()` needs an assistant id, executes that
assistant's **tool set** (a utility call would recurse into tools), and has no
image input.

It turned out not to be needed at all. `GeminiClient` and `AnthropicClient`
already accept OpenAI-format messages carrying an `image_url` segment and convert
them to their own dialect, and `OpenAiCompatibleClient` speaks it natively. So the
multimodal transport existed; only the tool did not. `VisionRequest` builds that
message, `ProviderRouter` picks the client, and the tool is argument handling and
response extraction. One contract was avoided, not added.

### Where the bytes come from, and the URL refusal

| Source | Path |
|---|---|
| `file_id` | the §162 registry: `getMetadata()`, the registry's own `userCanAccess()`, `isImage()`, a size cap, then the bytes |
| `image_data` | a `data:` URI the caller already holds |
| `url` | **refused** |

A remote URL is refused for two independent reasons, either sufficient:

1. **Both non-OpenAI converters treat the value as base64 payload.** They strip a
   `data:` prefix if present and forward the remainder as the image bytes, so a
   `https://…` string arrives at the provider as garbage. The request would fail,
   or the model would be asked about nothing.
2. **An SSRF guard could be written but not enforced.** `HttpClientInterface::send()`
   exposes no redirect or size controls, so a redirect into a private range walks
   past any pre-flight check. A guard that cannot be enforced is worse than no
   guard, because it reads as protection.

The refusal names both alternatives, because a "no" without a next step is how a
model retries the same call five times. `file_id` also gets the ownership check
for free: a caller cannot analyse a file it could not download. A file it may not
access answers **404**, not 403 — the same shape §17 and §18 use, so the three
tools cannot be compared to learn whether a row exists.

### The Gemini bug this exposed

`GeminiClient::convertMessages()` hardcoded `mimeType: 'image/jpeg'` for every
inline image, and its data-URI pattern (`image/\w+`) could not match
`image/svg+xml` or `image/x-icon` even if it had looked. `inlineData` carries
bytes and nothing else, so the declared type is the *only* evidence of what they
are — every PNG and WebP sent through Gemini was labelled JPEG.

Since this tool sends non-JPEG data URIs by design, the mislabelling moved from
latent to load-bearing: it is fixed here, with a test over PNG, WebP and
`image/svg+xml`.

### Two gates that disagreed with the change, and what each meant

- **G6 (encoded payload with no sanitizer)** flagged the tool on first run. The
  detector greps the class source for `b64_json|audio_base64|image_base64|inline_data|inlineData`,
  and the tool's docblock named Gemini's inline image field. The tool returns text
  and no encoded payload, so silencing it with a no-op `sanitizeForLlm()` would
  have been a lying surface and baselining it would have diluted a signal whose
  value is that it stays empty. The docblock now describes the conversion without
  the identifier.
- **`testPromptBuildersAreNotReachableUnderTheWordPressSlug`** failed: it listed
  `analyze_image` among the slugs that must *not* be registered. That assertion
  encoded the gap as a requirement. The list is now the slugs that still have only
  a stand-in — `analyze_video`, `extract_image_text`, `batch_embed_content`,
  `validate_reasoning_chain` — and its docblock says that it shrinks as tools land,
  so the next agent does not read a stale expectation as a spec.

### Capability and flags

`upload_files`, matching WordPress: a caller may analyse the images it could have
uploaded. Nine of WordPress's ten flags are declared; **`async` is not**, because
this call is synchronous and declaring it would send a finished request down the
queue path. `read-only` is accurate (nothing is written locally), and
`consumes-tokens`/`external-api`/`rate-limited` are what the necessity gate and the
read-only profile read.

`max_tokens` is bounded at 8192 so a single call cannot ask for an unbounded
completion; images are capped at 6 MB raw, which is ~8 MB of base64, and the
message for the over-size case points at `file_id`.

### Not done, named

The other 18 promises, which fall into three groups: eight more vision tools
(`analyze_video`, `extract_image_text`, `generate_image_alt_text`,
`generate_image_caption`, `generate_video_caption`, `vision_object_localization`,
`vision_product_search`, `analyze_code_sequence`), eight text-model tools
(`analyze_comment_content`, `analyze_file_suitability`, `auto_categorize_content`,
`content_recommendation_engine`, `generate_post_excerpt`, `seo_meta_optimizer`,
`suggest_internal_links`, `validate_reasoning_chain`), one embeddings tool
(`batch_embed_content`) and one OpenAI-only dispatch (`run_openai_external_action`).
The vision group is now mostly wiring — `VisionRequest` is the shared piece — and
the text group needs only the same `messages()` call without the image segment.

### Validation

`AnalyzeImageVisionToolTest` (core, 16 tests: the OpenAI message shape and prompt
ordering, the WordPress default prompt, a remote URL refused naming both
alternatives, exactly-one-source, registry read using the stored mime, an
inaccessible file answering 404, a non-image file, a host without a registry, an
over-size data URI, an unknown provider named with the registered list, no default
provider distinguished from an unknown one, a provider refusal surfaced with its
message, an empty answer treated as an error, a host without a router, and the
capability/flags). `GeminiClientTest` gains the media-type regression over PNG,
WebP and `image/svg+xml`. Feature test
`docker/laravel/app-skeleton/tests/Feature/AnalyzeImageVisionToolTest.php` (4 tests
with a recording provider on the real singleton router: container registration, the
end-to-end request including `max_tokens`, a missing `file_id` at 404, and a remote
URL refused **before** any provider call).
`lib/core` 2098 / 16,154; adapter 348 / 1,833; skeleton analyze + registration +
diagnostics + file store 37 / 131. Auditor 232 rows / 213 servable, no violations,
G6 empty. phpcs clean on all seven files.

## 21. Addendum: the completion seam, and five tools on it (2026-11-23)

### The seam, as promised in §20

§20 closed one promise and left eighteen. Those split into groups by what they
actually need, and the largest group — every tool that asks a model a question
without needing a post store — needs the same four things:

- resolve a provider (and name the registered alternatives when it cannot),
- bound the answer so a call cannot ask for an unbounded completion,
- call it,
- turn three distinct failures into something a model can relay.

That is now `AbstractCompletionTool`. `analyze_image` was **folded onto it** in the
same change rather than left as a second copy: its 16 unit and 4 feature tests are
the proof the refactor preserved behaviour, and one of them caught a real
regression while doing it — the base had collapsed "no provider router" and "no
default provider configured" into one code, and the merged tool had distinguished
them. `noDefaultProviderCode()` exists because a test refused to let that slide.

Two small collaborators came with it:

| Class | Job |
|---|---|
| `CompletionRequest` | build the conversation — text, optionally with an image segment. The only difference between a text tool and a vision tool is whether a data URI is passed. |
| `JsonPayload` | read an object out of a model answer, fences and preamble included, and coerce closed sets (`oneOf`, `stringList`, `confidence`) |

`VisionRequest` keeps only the image ↔ bytes conversions; the message building
moved to `CompletionRequest` so there is one description of "a conversation" in the
runtime.

### The five tools

`generate_post_excerpt`, `seo_meta_optimizer`, `analyze_code_sequence`,
`analyze_comment_content`, `validate_reasoning_chain`. All five take the text the
caller supplies and return the result; none of them reads or writes a post.

Two decisions are shared, and stated once so they do not drift:

- **No `post_id`.** WordPress reads the post by id. That is a content-store
  dependency, and a tool that accepted an id it then ignored would be worse than
  one that never took it.
- **No `auto_save`.** The value comes back; saving it is `update_post`, which owns
  the capability check and the revision history. Same rule as §16's `status` and
  §19's `status_override`: a generator that also commits is a second write path.

### Prose is sanitised; code is not

`stringParam()` runs `strip_tags()`. For an excerpt or a meta description that is
right — the word count would otherwise be wrong and the result should not carry
markup. For code it is destructive: `strip_tags('<?php echo 1;')` returns an empty
string, and `if ($a < $b)` loses everything from the `<` onward. A code reviewer
that mangles what it reviews is worse than no reviewer, because the model then
reports confidently on something the caller never wrote.

So `AbstractCompletionTool` has `documentParam()` and `documentListParam()` beside
`stringParam()`, and `analyze_code_sequence` and `validate_reasoning_chain` use
them. A unit test and a feature test both pin `<?php` and `if ($a < $b)` arriving
verbatim.

### What is withheld: commenter PII

WordPress's `analyze_comment_content` also accepts `comment_email`, `comment_url`
and `user_ip`. None are accepted here. They are personal data, the model needs none
of them to judge whether text is abusive, and passing them to a third-party
provider turns a content decision into a data-disclosure one. The author's display
name stays, because impersonation and naming are part of what the text is doing.

The verdict shape is WordPress's field for field, including
`sensitivity_applied`. Unknown `sensitivity` values are **refused**, not
defaulted: silently becoming `medium` would mean a caller who asked for strict
moderation got balanced and was never told.

### Two bugs the tests found before CI did

- `strictChoice()` treated an **absent** value as invalid, so every call that
  relied on the documented `sensitivity` default was refused. It now takes a
  default and only returns null for a value that was supplied and is not in the
  set.
- The excerpt tidier stripped quotes before fences, so the common
  ``"```text … ```"`` shape survived intact. It now strips quotes, fences, then
  quotes again.

### The class-name collision, and a restored mistake

Every one of these five already had a `_prompt` stand-in class named
`<Name>Tool` — `GeneratePostExcerptTool`, `AnalyzeCodeSequenceTool`,
`AnalyzeCommentContentTool`, `ValidateReasoningChainTool`,
`SEOMetaOptimizerTool`. Writing the real tool under the obvious name overwrote
four **tracked** files before the pattern was obvious; they were restored from git
immediately and verified by slug. The real tools therefore carry a
`…CompletionTool` suffix, as `AnalyzeImageVisionTool` carries `Vision`.

The lesson for the remaining promises: check for an existing `<Name>Tool` before
writing a tool class, because the stand-in owns that name.

### The stale list, third time

`ToolReadinessRegistrationTest` in the adapter listed `validate_reasoning_chain`
among slugs that must not be registered — and this change registers it. Rather
than shrink the list a third time, it now derives the non-servable set from the
manifest exactly as `ToolsReadinessTest` does since #8179. Both suites now express
the invariant ("nothing outside the servable classes is registered") instead of a
snapshot of which tools happened to be missing, so the remaining thirteen
promises cannot break the build this way.

### Not done, named

Thirteen promises remain. Seven vision tools (`analyze_video`,
`extract_image_text`, `generate_image_alt_text`, `generate_image_caption`,
`generate_video_caption`, `vision_object_localization`, `vision_product_search`)
are now mostly wiring onto `VisionRequest`. Three need a content index
(`suggest_internal_links`, `content_recommendation_engine`,
`auto_categorize_content`), one needs the file registry plus a purpose taxonomy
(`analyze_file_suitability`), one needs an embedding call
(`batch_embed_content`), and `run_openai_external_action` is OpenAI-only dispatch
with no cross-provider meaning.

### Validation

`TextCompletionToolsTest` (core, 12 tests: slug/capability/flags for all five, the
no-router error for all five, wrapper cleanup, **code reaching the model
unmodified**, verdict normalisation including unknown-enum coercion and confidence
clamping, unknown sensitivity refused, unreadable verdict refused, the missing
`valid` field refused, a broken-step verdict, SEO lengths reported instead of
truncated, a provider refusal surfaced, and content validated before any call).
Feature test `docker/laravel/app-skeleton/tests/Feature/TextCompletionToolsTest.php`
(5 tests on the real singleton router: all five register, an excerpt end to end
with HTML stripped from prose, a code review receiving `<?php` and `<` verbatim,
the WordPress verdict shape surviving the round trip, and an unknown provider
refused at 422). `lib/core` 2120 / 16,292; adapter 348 / 1,853; skeleton text tools
+ readiness + analyze + registration + diagnostics 26 / 358. Auditor 237 rows /
218 servable, no violations, G6 empty. phpcs clean on all thirteen files.

## 22. Addendum: three image readers, and the fourth one deferred (2026-11-23)

### What shipped, and what did not

`extract_image_text`, `generate_image_alt_text` and `generate_image_caption`. The
plan was four; `vision_object_localization` was **deliberately not built**. Its
WordPress implementation does not use a chat completion at all — it builds an image
source and calls a `Cloud_Vision_Client` with its own response shape. Porting it
means porting a second vision transport and guessing a bounding-box schema that
cannot be verified from this repository, and a tool that returns plausible-looking
boxes it cannot validate is worse than a tool that is not there yet. It stays
quarantined behind its `replaced_by` pointer.

### `ImageInput`, and folding `analyze_image` onto it

Four vision tools need the same six steps — one source only, ownership checked, type
checked, size capped, bytes read, data URI built — and `analyze_image` had grown a
private copy. Rather than copy it three more times, it is now
`Application\Media\ImageInput`, and `analyze_image` was folded onto it in the same
change. Its 16 unit tests and 4 feature tests are the proof the extraction
preserved behaviour, and they stayed green.

`ImageInput::parameters()` also returns the two schema fragments, so the four tools
declare their image arguments identically instead of drifting — the same pattern
`GeneratedMediaPersister::parameters()` already uses for the generation tools.

### An empty answer is a finding, for exactly two tools

Every completion tool treats a blank answer as a failure, because a blank success
reads as "nothing to report" and that is how a caller concludes the input was
empty. Two of these tools invert that:

- **`extract_image_text`** — a photograph of a dog contains no text. That is a
  correct result, and the payload says so with `has_text: false`.
- **`generate_image_alt_text`** — a purely decorative image should have *empty* alt
  text, not a description of itself. The model is asked to return a sentinel and
  the tool maps it to `alt_text: ""` with `decorative: true`.

Both call `completeTool(..., allowEmpty: true)`. The flag defaults to false, so a
tool added later cannot inherit the permissive behaviour by accident.

### Two tidiers, because one would destroy layout

`tidyText()` collapses whitespace to a single line — right for an alt text, a
caption or an excerpt, all of which are rendered where a newline shows as a stray
gap. `extract_image_text` with `preserve_layout` needs the opposite: collapsing its
newlines would throw away the thing the caller asked for. So `tidyBlock()` does the
structural cleanup only (quotes, fences) and `tidyText()` is `tidyBlock()` plus the
collapse. A test asserts the line break survives when layout is requested and is
gone when it is not.

### Alt text: reported, not truncated

Guidance for accessible alt text sits near **125 characters** — not a hard limit,
but the length past which a screen-reader user starts skimming. The tool asks for
that, then reports `characters` and `within_recommended_length` rather than cutting
the string: truncating mid-phrase leaves a reader with a broken sentence and no
sign that anything was dropped. Same principle as §21's SEO lengths.

`context` is a parameter because the same photograph needs different alt text on a
product page and in a blog post, and the caller knows the page while the model
does not.

### A caption is not alt text

They are separate tools with separate instructions and separate fields, not one
calling the other with a longer limit. Alt text *replaces* the image for someone who
cannot see it; a caption is *visible* and adds what the picture cannot show — what
happened, who is involved, why it matters. `generate_image_caption` imposes no
length ceiling and says so: a hard limit there would be a product decision the tool
has no basis for.

### One WordPress parameter not offered

`extract_image_text` also offers `include_metadata`. It is not offered here: the
character and word counts are always returned because they cost nothing, and a
parameter that changes nothing in the answer is a lying surface.

### Validation

`VisionImageToolsTest` (core, 10 tests: slug/capability/flags for all three, image
required before any provider call, a missing file at 404, an image with no text
succeeding with `has_text: false`, layout preserved with `preserve_layout` and
collapsed without it, the decorative sentinel producing empty alt text, over-long
alt text reported and left intact, a caption that was only framing refused, and a
caption's length agreeing with its `characters`). Feature test
`docker/laravel/app-skeleton/tests/Feature/VisionImageToolsTest.php` (4 tests on the
real singleton router: all three register, an OCR read end to end including the
data URI reaching the provider, over-long alt text reported, the decorative
sentinel, and an unknown provider refused at 422). `lib/core` 2136 / 16,372;
adapter 348 / 1,856; skeleton vision + text + readiness + analyze + registration +
diagnostics 31 / 385. Auditor 240 rows / 221 servable, no violations, G6 empty.
phpcs clean on all ten files.

### What is left

Ten promises. Two video tools (`analyze_video`, `generate_video_caption`) need frame
extraction the runtime does not have. Three need a content index
(`suggest_internal_links`, `content_recommendation_engine`, `auto_categorize_content`),
one needs the file registry plus a purpose taxonomy (`analyze_file_suitability`),
one needs an embedding call (`batch_embed_content`), one needs a product catalogue
(`vision_product_search`), one needs the cloud-vision transport
(`vision_object_localization`), and `run_openai_external_action` is OpenAI-only
dispatch with no cross-provider meaning.

## 23. Addendum: suggest_internal_links, and retrieval before generation (2026-11-23)

### The slice

`suggest_internal_links` is the first of the three tools that need a content
index. Two of those three turn out to need something subtler than an index: they
need **retrieval the model cannot fake**, which is a different design question and
the reason this one is worth documenting at length.

### The model does not choose what exists

A model asked "what should this page link to?" invents plausible titles. So it is
never asked that. The candidates come from `ContentStoreInterface::query()` — real
rows, real ids, scoped to what the calling user may read — and the model's only job
is to pick from the list, write the anchor text and say why.

The check that makes the output safe to act on:

> **An id the model returns that is not in the candidate list is dropped, and the
> count is reported** (`dropped`, and named in the message).

Without it a hallucinated id becomes a link to a page that does not exist, and the
caller has no way to tell it apart from a real one. The unit suite and the feature
test both pin it; the feature test does it against the real store, with one real id
and one invented id in the same answer.

### Terms, derived locally

The search needs terms. Spending a second model call to guess them would double the
cost to answer a question the text already answers: words of four characters or
more, stopwords removed, ranked by frequency, top three. `query` overrides the
derivation for a page whose body does not name the thing it is about — the escape
hatch, not the default.

### Published only, and scoped to the reader

`ContentQuery` is built with `statuses: ['publish']` and the calling user's id, so
an unpublished draft cannot be suggested as a link target and a caller cannot be
shown content it could not open. The feature test asserts the draft is invisible
even though it matches the term.

### Empty is a finding

Two different empties, both reported as results rather than errors:

- **No terms derivable** — a page of one-letter words has no topic to link on.
- **No candidates** — nothing in the store matches, and the message names the terms
  that were tried.

Only two things are failures: the host has no content store, and the model's answer
is unreadable.

### What the remaining two need

`content_recommendation_engine` and `auto_categorize_content` share the retrieval
half of this tool. The recommendation engine is the same machinery with a different
prompt and no anchor text. `auto_categorize_content` needs one more dependency: the
category list. `ContentStoreInterface` exposes `ContentQuery.taxQuery` for
*searching* by term, not for *listing* the terms, so the tool would need a taxonomy
read that does not exist yet — which is the honest reason it is not in this slice.

### Validation

`SuggestInternalLinksToolTest` (core, 11 tests: slug/capability/flags, content
required, missing content store, no derivable topic, nothing matching, a successful
retrieval with derived terms, **an invented id dropped and counted**, a below-floor
relevance discarded, `max_suggestions` honoured, the `query` override, and an
unreadable answer). Feature test
`docker/laravel/app-skeleton/tests/Feature/SuggestInternalLinksToolTest.php`
(4 tests against the real store: registration, a real page suggested while an
invented id is dropped, a topic nothing covers, and a draft that matches the term
but is not published and therefore invisible). `lib/core` 2149 / 16,414; adapter
348 / 1,857; skeleton links + vision + text + readiness + registration +
diagnostics 31 / 382. Auditor 241 rows / 222 servable, no violations, G6 empty.
phpcs clean on all four files.

## 24. Addendum: content_recommendation_engine, and the AI that was not there (2026-11-24)

### The slice

`content_recommendation_engine` was recorded in §23 as sharing the retrieval half of
`suggest_internal_links` and being "the same machinery with a different prompt". That
was wrong, and reading the WordPress implementation is what showed it. There is no
prompt to write, because there is no model to call.

### What the WordPress tool actually does

The tool advertises "AI-powered related content recommendations with personalization,
collaborative filtering, semantic similarity, and user behavior tracking". Read line by
line:

- `use_semantic_search` is accepted and passed to `calculate_similarity_score()`, whose
  parameter carries `phpcs:ignore ... UnusedFunctionParameter.Found -- Parameter
  reserved for semantic search`. It is never read. `use_collaborative` is the same in
  `calculate_personalized_score()`.
- `category_based` runs a `WP_Query` for same-category posts in random order and scores
  every one of them with `format_recommendation( $post, 0.8, 'category' )` — a literal.
- `train_model` calls `train_recommendation_model()`, which returns
  `array( 'features' => n, 'accuracy' => 0.85, 'algorithm' => 'collaborative_filtering' )`
  with the comment "Simplified training - in production would use actual ML". The
  hardcoded **accuracy is written to an option** and the action answers `success: true`
  with the next step "Model is now active for personalized recommendations".
- `trending` scores `engagement × (1 − w) + recency × w`, where engagement is a post
  meta value only `track_interaction` ever writes. On a store with no tracked
  interactions the engagement term is zero and the ranking is recency over a seven-day
  window.
- `personalized` with an empty history returns `get_trending_content( ..., 0.5 )`.

So three of the four actions and one of the four recommendation types depend on an
interaction store, and the "model" was a constant. What is actually computed from
content is: a similarity score, a category filter, and recency.

### The port ranks; it does not generate

A recommendation is a ranking over rows that exist. Ranking by signals the caller can
inspect — shared terms, shared words, age — is auditable, and every id in the answer is
a row that was retrieved. Asking a model to rank without embeddings would produce
relevance numbers it cannot compute, which is the failure §23 exists to prevent, one
layer up. So this tool computes the score, returns it, and returns its components.

Its capability profile follows: `read` rather than the WordPress `edit_posts`, and
flags `idempotent`, `local-only`, `read-only`, `requires-capability` — no
`consumes-tokens`, no `external-api`, no `requires-credentials`. A host with a database
and no API key can serve it.

### Three corrections, named

For an item that carries taxonomy terms the published weights are kept —
`0.4 · taxonomy + 0.3 · tag + 0.3 · title` — so the score means what it meant there.
Three things change, each for a stated reason:

1. **Title similarity is token overlap, not `similar_text()`.** WordPress divides the
   matching-character count by the longer title length, which scores two unrelated
   English titles of equal length highly because they share most of the alphabet. A
   test pins two unrelated titles at `0.0`.
2. **`category_based` returns the real score.** The filter is the same — the candidate
   must share a category or tag with the source — but the number is computed, not
   assigned. A test asserts the score is *not* `0.8`.
3. **An item with no terms falls back to text overlap** (`0.7 · text + 0.3 · title`).
   WordPress's formula would score every candidate zero on both term components and
   leave the order to the store. A post with no categories and no tags has nothing for
   that formula to weigh; text is the signal that remains. The payload shows which
   components were used, so a caller can see which formula ran.

### What is refused, and what is served as a fallback

`train_model`, `track_interaction` and `analyze_performance` return
`unsupported_action` with a message naming the missing dependency — no model to fit, no
interaction store, no history to analyse. They do not write an option and report
success. A test asserts each refusal's message, in the app, against the real error
factory.

`personalized` is served as the recency fallback, which is what WordPress itself does
when a user has no history — and on a host with no interaction store, every user has no
history. The payload says so: `fallback: trending` plus a
`fallback_reason`. `trending` reports `strategy: recency`, so nobody reads popularity
into it.

### Three WordPress parameters not offered

`use_semantic_search` and `use_collaborative` did nothing in WordPress. `recency_weight`
is only meaningful against engagement data that does not exist here; with the engagement
term at zero it is a monotone rescaling of recency and cannot change the order. A
parameter with no reader is the rendered-control failure this proposal keeps naming, so
none of the three appears in the schema.

### `TopicTerms`, extracted

Both retrieval tools need the same search terms. Rather than grow a second copy of the
stopword list and the frequency ranking, `suggest_internal_links` now takes
`Nvoos\Core\Domain\Service\Text\TopicTerms` — one class, one rule, two callers. The
move is not cosmetic: two copies of a ranking rule drift, and the same page would yield
different candidates depending on which tool asked. The existing eleven tests still pin
the behaviour; ten new ones pin what the move made explicit — the tie order is stable
between runs, a limit below one still returns a term rather than an empty query, and
accented words survive the split.

### One default widened

WordPress defaults `include_post_types` to `array( 'post' )`. Here it defaults to
`array( 'post', 'page' )`, matching `suggest_internal_links`, because the Laravel
content model holds pages in the same table and a page that recommends only posts is the
less useful half of the same store. A caller who wants the WordPress behaviour passes
the type explicitly.

### Validation

`ContentRecommendationEngineToolTest` (core, 24 tests: slug/capability/flags without
`consumes-tokens` or `external-api`; the missing store; a required source; a
non-resolving `post_id`; **the three action refusals naming what is missing**; the
published-only statuses reaching the query; the source excluded from its own results;
caller exclusions reaching the query; ranked-by-shared-terms with the components
reported; the published `0.4/0.3/0.3` weights producing `1.0` on a perfect match;
unrelated titles scoring `0.0`; the no-terms text fallback; reproducible tie order;
the limit; category mode keeping only sharers and **not** returning `0.8`; category mode
without terms as a finding; `trending` ranking the seven-day window and excluding a
30-day-old row; an empty window as a finding; the personalized fallback reported; empty
retrieval naming its terms; a source with no words; an unknown recommendation type).
`TopicTermsTest` (core, 10 tests). Feature test
`docker/laravel/app-skeleton/tests/Feature/ContentRecommendationEngineToolTest.php`
(7 tests against the real store and real taxonomy rows: registration, a real row ranked
above an unrelated one with the source excluded, a draft never recommended, category
mode filtering on the `nvoos_post_term` rows the database actually holds, a source
without categories reported as a finding, trending returning recent rows, and the three
refusals surfaced through the real error factory).

`lib/core` 2,185 / 16,509; adapter 348 / 1,858; skeleton full 2,232 / 22,339 with five
environment-only errors in a one-off runner (three need
`examples/nvoos-astro-velocity/src/tokens/core.json` outside the mounted tree, two data
providers yield no datasets for the same reason — none in a changed area). Auditor 242
rows / 223 servable, no violations. phpcs clean on all three files at the CI severities.

## 25. Addendum: analyze_file_suitability, and an answer that disagrees with itself (2026-11-24)

### Why this one was cheap

It was recorded as needing "a file registry and a purpose taxonomy". The registry landed
with the media work, and the "taxonomy" turned out to be a five-row table of provider
limits, not data. The tool is size, extension and a bounded read of the bytes: no model,
no network, no credentials. Its flags say so — `read-only`, `local-only`, `idempotent`,
`requires-capability`.

### The defect it replaces

The WordPress tool reports two things that contradict each other. It collects a warning
when an image is under 100 pixels wide…

```php
if ( $width < 100 || $height < 100 ) {
    $warnings[] = __( 'Image is very small. May not provide good results for vision tasks.' );
}
```

…and returns `suitable => true`, because only size and format ever flip that flag. The
message is then built from the count it just collected:

```php
_n( 'File has %d warning preventing use.', 'File has %d warnings preventing use.', $warning_count )
```

So a usable file is announced as having warnings "preventing use". A caller has no way
to tell advice from a refusal.

### One invariant

```
suitable === (warnings === [])
```

Blocking, in `warnings`: over the purpose's size ceiling; a format the purpose does not
accept; a spreadsheet or presentation aimed at a vector store; a text file with nothing
in it; a JSONL file whose first lines do not parse. Everything else is
`recommendations`: an image under 100px or over 4096px, text that is not UTF-8, one
malformed JSONL line, a file at 90% of its ceiling, the PDF/DOCX ingestion advice.

The two lists never overlap, and a test runs five differently-broken files through the
tool asserting the invariant on each. Two cases moved in the move: the encoding check
stays advisory (WordPress also kept it non-blocking) and "no JSONL line parses" became
blocking, because a fine-tune or batch input with no parseable line cannot be used
whatever the caller intends.

### Privacy, and why "not found" is the right answer

When the caller passes a user id, `FileStoreInterface::userCanAccess()` runs before the
metadata is read, and a file the caller may not see answers exactly as a file that does
not exist. `forbidden` would confirm that a file with that id exists, which is the thing
the check is protecting.

### Two things the runner taught us

- **The real file store refuses a zero-byte upload.** It sniffs
  `application/x-empty` and rejects it at `store()`. So the tool's "empty file" branch is
  reachable only just under the 10-byte threshold, and the app test uses `"hi\n"`. The
  unit test keeps the true-zero case, which a host with a laxer store can still reach.
- **A store can hand back a path that does not exist.** The file check therefore reports
  `content_checked: false` with a recommendation rather than claiming the content was
  inspected. A metadata-only answer remains a valid answer, and the payload says which
  one it gave.

### Validation

`AnalyzeFileSuitabilityToolTest` (core, 18 tests: slug/capability/flags; the missing
`file_id`; the missing store; an unknown purpose; a missing file; **another user's file
answering as missing**; a suitable UTF-8 text file; over the ceiling; an unaccepted
format naming what is accepted; a spreadsheet blocked with the conversion advice; an
empty file; JSONL with no parseable line; non-UTF-8 as advice; a one-pixel image as
advice; one bad JSONL line as advice; **the invariant across five broken files**;
`check_content: false` touching no file; an unopenable file reported honestly). Feature
test `docker/laravel/app-skeleton/tests/Feature/AnalyzeFileSuitabilityToolTest.php`
(5 tests against the real store and real disks: registration, a stored text file whose
`localPath` the check can actually open, a near-empty file, a missing id, and another
user's file answering as missing).

`lib/core` 2,205 / 16,580; adapter 350 / 1,871 with the two new probes
(`content_recommendation_engine` on its success path and `analyze_file_suitability` on
its not-found path). Auditor 243 rows / 224 servable, no violations. phpcs clean on all
four files at the CI severities.

## 26. Addendum: a taxonomy read, and auto_categorize_content (2026-11-24)

### The slice

§23 recorded `auto_categorize_content` as blocked on one dependency: the category list.
`ContentQuery.taxQuery` *filters* by term names it is given; nothing enumerates them. A
categorizer that cannot see the vocabulary cannot ask a model to choose from it, so the
read came first.

`ContentStoreInterface::listTaxonomyTerms( string $taxonomy, int $limit = 200 )` now
returns `array{id, name, slug, count}` ordered by name. There are exactly two
implementations — the Laravel and WordPress adapters — so the method went on the
existing contract rather than into a second interface with an `instanceof` dance at
every call site. The two adapters were loaded and reflected against the interface to
prove both still satisfy it; lint alone would not have caught a missing method, because
PHP only fatals at class load.

An unused term is still listed. It is the *vocabulary* that matters to a categorizer,
not the usage: a term nobody has used yet is a legitimate answer.

### A closed list, and invented ids dropped

The model is given the real ids and names and asked to pick. An id it returns that was
never in the list is dropped and counted — the same rule as §23, for the same reason:
assigning it would fail, or silently create a near-duplicate of a term that does exist,
and the caller could not tell the difference.

An empty vocabulary returns a finding **before** the model call. There is nothing to
choose from, so nothing is spent saying so.

### `create_new` is not offered

WordPress accepts `create_new` and never reads it: `assign_categories()` calls
`wp_set_object_terms()` with ids that already exist, and nothing creates a term. It is
the same inert-parameter defect as §24's `use_semantic_search`, so the port does not
declare it.

### `auto_assign` adds here; WordPress replaces

This is a real semantic split, not a preference. `wp_set_post_terms( ..., $append =
false )` removes every term the model did not name. The Laravel store's update path
merges (`syncWithoutDetaching`), so the same call would append. The two adapters have
disagreed about `UpdateContentCommand::$taxonomyInput` since it was written.

Rather than change either adapter — which would alter every existing `save_post` and
`update_post` caller — the tool computes the union itself and passes the **full desired
set**. WordPress replaces with that set, Laravel appends it, and both hosts converge on
the same state without a suggestion deleting curation a human made. The payload reports
`assigned` (newly added) and `already_present` separately, and nothing is written at all
when every suggestion is already attached, so an idempotent call does not touch the row
or fire update hooks.

A follow-up worth its own issue: the adapters should agree on what `taxonomyInput`
means before more tools write through it.

### Dry run, and what confidence is

`dry_run` with `auto_assign` reports what would be added and writes nothing — the safe
way to look at a categorizer before trusting it.

`min_confidence` filters on the number the model returned. That number is a claim, not a
measurement, so every suggestion carries the confidence next to the term it refers to
rather than being collapsed into one opaque relevance score.

### Validation

`AutoCategorizeContentToolTest` (core, 18 tests: slug/capability/flags with `write` and
`state-changing` present and `read-only` absent; the missing store; a required source;
an empty taxonomy; a non-resolving `post_id`; an unreadable answer; **an empty
vocabulary as a finding that spends no completion**; **an invented id dropped and
counted**; the confidence floor; `max_categories`; equal confidence ordered by name for
reproducibility; no write without `auto_assign`; the term added; **an existing term kept
through the union**; no write when everything is already present; dry run writing
nothing; a missing caller refused with `user_required`; inline content only suggesting).
Feature test `docker/laravel/app-skeleton/tests/Feature/AutoCategorizeContentToolTest.php`
(6 tests against the real store and the real `nvoos_terms` rows: registration, the
vocabulary read, a real term suggested while an invented id is dropped, `auto_assign`
attaching a term while the existing one survives the round trip, dry run writing
nothing, and a term-free taxonomy returning a finding without a completion).

`lib/core` 2,225 / 16,639; adapter 350 / 1,872; auditor 244 rows / 225 servable, no
violations. phpcs clean under both the PSR-12 ruleset (core, Laravel adapter, skeleton)
and the WordPress ruleset (the WordPress adapter).
