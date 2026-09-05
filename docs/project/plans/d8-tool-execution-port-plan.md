# D8 — Tool Execution Port — Cluster Plan

**Date:** 2026-09-05
**Status:** Proposed (awaiting approval)
**Scope:** Port the base tool inventory and the `tools/call` execution path into the Content Graph ecosystem (`nvoos-content-graph-ai`, with the harness-tool cluster landing in `nvoos-content-graph-ai-platform`), so standalone installs can execute tools end-to-end.
**Tracked in:** [`ecosystem-port-tracker.md`](../ecosystem-port-tracker.md) Wave D row **D8** (previously the un-enumerated "D-Tools" referenced in the D5c deviation).
**Depends on:** Waves D1–D4, D7 (all ✅), platform extraction Wave E2 queue classes (✅), harness extraction Wave C (✅).
**Constraints:** D-NOBASE (zero changes to `mcp-ai-wpoos`), D-NOCORE (zero changes to `nvoos-content-graph`), additive-only, byte-identical slugs / error codes / envelopes / hook names, one subsystem per PR with characterization tests green in both matrices.

---

## 1. Inventory facts (verified 2026-09-05)

Base tool surface (all slugs extracted from `get_slug()` implementations):

| Source | Count |
|---|---|
| `includes/tools/*.php` registry default map registrations | 223 |
| `includes/validators/validated-tools-init.php` (24 `*_validated` destructive-op wrappers) | +24 |
| `includes/tools-init.php` side-loader (`trace_memory_provenance`, `wait_for_user`) | +2 |
| `includes/tools/harness/` (7) + `evolve_harness` | +8 |
| Sub-directories: `okf/` (10), `paper-store/` (6), `orchestration/` (9) | +25 |
| **Total unique base slugs** | **271** |

The target engine (`lib/core`, the nvoos-core registry wired by CG-AI's `CoreBridge`) already ships a large, slug-compatible inventory:

| Fact | Count |
|---|---|
| Tool classes implementing `ToolExecutionInterface` / extending `Abstract*Tool` in `lib/core/src/Tool/` | 180 |
| Unique slugs extracted from `getSlug()` | 191 |
| **Base slugs already implemented by a core class** (`create_post`, `count_tokens`, `deep_research`, `client_*`, …) | **148** |
| Core-only utility slugs with no base counterpart (`base64`, `format_date`, `generate_uuid`, `parse_csv`, …) | 43 (additive — no port work) |
| **Base slugs with no core implementation** | **123** |

Today the standalone CG-AI registry serves only **27 tools** (13 `src/Tools/` AI tools + the parent graph plugin's bridged tools). `McpController::mcp_tools_call()` validates parameters byte-identically then answers `wp_mcp_ai_mcp_unavailable` (503) — execution is the missing link, not the inventory.

## 2. The 123-tool delta, bucketed

| Bucket | Slugs | Owning wave | D8 action |
|---|---|---|---|
| Harness tools (8) | `evolve_harness`, `apply_prompt_cue`, `list_prompt_cues`, `record_reflection`, `retrieve_with_provenance`, `scope_memory`, `select_prompt_cue`, `self_consistency_vote` | Platform (harness Wave C ✅) | **Cluster 3** — port into the platform plugin |
| Self-contained base WP tools (~46) | taxonomy CRUD (`create_term`, `list_terms`, `list_taxonomies`, `update_term`), security audits (`2fa_setup_assistant`, `check_site_security`, `login_security_monitor`, `password_strength_analyzer`, `user_activity_auditor`, `memory_audit_trail`), site/admin (`get_environment_status`, `get_site_health`, `get_system_logs` + validated, `list_mcp_tools`, `create_assistant` + validated), model (`add_model_config`, `discover_new_models`, `research_model`, `enable_reasoning_mode`), agent coordination (`aggregate_agent_results`, `delegate_to_a2a_agent`, `query_mesh_intelligent`, `run_gemini_managed_agent`), memory (`batch_manage_memory`, `wake_up_context`, `trace_memory_provenance`, `get_profession_stats`), media (`media_library_optimizer`, `image_format_batch_converter`, `image_alt_text_optimizer`, `responsive_image_validator`, `vectorize_image`, `graphic_editor_plus`), misc (`gutenberg_block_pattern_generator`, `performance_optimizer_assistant`, `pro_excel`, `cloudflareai_text_to_image`, `probe_chat`, `submit_document_prompt`, `open_openai_usage`, `openai_usage_analytics`, `prioritize_context`, `visualize_workflow_metrics`) | CG-AI | **Cluster 2** — port as core-style classes |
| JetEngine-gated (9) | `conversation_import_*` (4), `get_jetengine_items`, `list_jetengine_rest_routes`, `invoke_jetengine_route`, `get_jetformbuilder_forms`, `get_jetformbuilder_submissions` | E4 / decision D3 | Defer (register with `is_available()` gating when E4 lands) |
| WooCommerce-gated (4) | `create_woo_product` + validated, `get_woo_products`, `get_woo_recent_orders` | decision D3 | Defer |
| Elementor-gated (3) | `get_elementor_form_submissions`, `get_elementor_templates`, `import_elementor_template_kit` | decision D3 | Defer |
| Rank Math / Site Kit (1 + 4) | `get_rankmath_seo`, `sitekit_get_adsense|analytics|pagespeed|search_console` | decision D3 | Defer |
| Newsletter / WP All Import-Export (6 + 6) | `newsletter_*`, `get_all_import_status`, `get_update_status`, `list_all_*_templates`, `trigger_all_*` | decision D3 | Defer |
| SaaS drivers (8) | `flowhub_*` (7), `payhere_get_payment` | Wave F (gap §8.3 — Pro tier) | Defer |
| Engine pieces (19) | `okf_*` (10), `paper_store_*` (6), `run_crawl4ai_job_validated`, `scrape_product` + validated | E6 / decision D4 | Defer |
| Orchestration / autonomous sessions (9) | `analyze_loop_health`, `calculate_orchestration_capacity`, `check_exit_conditions`, `create_task_plan`, `detect_completion_indicators`, `get_session_status`, `get_task_plan`, `manage_autonomous_session`, `update_task_plan` | E1 | Defer |

## 3. Execution spine — what `tools/call` must do standalone

Byte-parity target is the base `WP_MCP_AI_REST_MCP_Methods::mcp_tools_call()` chain:

1. Parameter validation (already byte-identical in `McpController` — keep).
2. Registry resolution per mode (base `WP_MCP_AI_Tool_Registry` monolith / `CoreBridge::instance()->tools` standalone — existing seam).
3. Capability check + assistant tool scoping (base: `get_required_capability()` vs acting user; standalone: `AuthProvider` + assistant `_wp_mcp_ai_tools` once D-UI-6 lands; scope D8 to the capability check, assistant scoping follows D-UI-6).
4. `execute( $arguments, $context )` through the registry.
5. Canonical envelope: success array in → `result`; `WP_Error` in → JSON-RPC error object with code/message/data (never `array( 'success' => false )`). Two-gate sanitisation (sanitize at entry, escape at exit) enforced per tool.
6. Async path: `is_mcp_async_tool_result()` → `mcp_wait_for_async_tool()` polling (~45 s bound, `kick_inline_if_stale`) — port both helpers.
7. MCP content shaping: `is_mcp_content_array()` / `convert_to_text_content()` — port both.
8. Tool rate limiting (E2 `RateLimitManager` is ported; wire the standalone limiter with the base's window/exemption semantics).

## 4. Clusters (one PR per cluster, characterization-first)

| Cluster | Plugin | Scope | Exit gate |
|---|---|---|---|
| **0 — Execution spine** (✅ landed 2026-09-05) | CG-AI | `McpController::mcp_tools_call()` execution chain (items 2–8 above); `registry_has_tool()` / `execute_registry_tool()` per-mode seams; fix the stale "(D-Tools)" docblock reference | `tools/call` on the 13 AI tools + graph tools returns byte-parity envelopes; 503 gone |
| **1 — Register the pre-ported inventory** (✅ landed 2026-09-05) | CG-AI | Registration manifest mapping the 148 core-parity slugs (+ optionally the 43 core utilities) to `lib/core` FQCNs, instantiated with `CoreBridge`'s `ErrorFactory` and registered via `$bridge->tools->register()` + `notifyRegistered()`; feature-flag gating mirrors the base's `is_available()` contract | `CoreToolFactory` + 196-entry `CoreToolManifest` (148 parity + 48 utilities incl. 7 adapter tools) wired in `CoreBridge`; standalone registry serves 223 tools; 581 ecosystem tests green in both matrices; seven base-coupled adapter tools defer to Cluster 2 |
| **1a–1f batches** | CG-AI | Batch by category: 1a core WordPress (posts/users/site), 1b OpenAI files/models/embeddings/vector stores, 1c client-side AI (`client_*` — Transformers.js substrate), 1d memory/agent tools, 1e media/geospatial/misc, 1f `*_validated` wrappers for ported destructive tools (pair with D4i gate) | Per-batch parity tests |
| **2 — Port the ~46 self-contained tools** | CG-AI | Port the missing self-contained buckets listed in §2 as core-style classes (same slugs/envelopes); media tools degrade cleanly without the media worker URL; `probe_chat`/`submit_document_prompt` follow their E6 owners where they cross paper-store | Delta shrinks to the deferred buckets only |
| **3 — Harness tools** | Platform | Port the 8 harness tools registered standalone by `HarnessService`; eval-dependent paths return the documented degradation envelopes (`wp_mcp_ai_harness_eval_unavailable`, …) | Harness tools appear in standalone `tools/list`; non-eval paths execute |
| **4 — Deferred buckets** | — | Document-only: each deferred bucket already names its owning wave (§2); no code ships | Tracker cross-references stay truthful |

## 5. Test strategy

- Characterization tests live in `plugins/nvoos-content-graph-ai/tests/` (CG-AI suite, both matrices) and `plugins/nvoos-content-graph-ai-platform/tests/` (Cluster 3), following the existing queue-wave pattern (`test-async-job-queue.php`, …).
- Slug-parity gate: a monolith-matrix test dumps the live base registry (`WP_MCP_AI_Tool_Registry::get_instance()->get_tools()`); the standalone matrix asserts the CG-AI registry contains the ported subset with byte-identical slugs.
- Envelope-parity gate: pick one representative tool per batch and assert identical `tools/call` JSON-RPC responses (success + WP_Error + rate-limit) across matrices.
- Two-gate audit: each ported/registered tool passes the existing `WPMCPAI.Tools.CanonicalReturnEnvelope` + `WPMCPAI.Tools.SanitizeAtEntry` PHPCS contract (the lib/core implementations must be audited for this — they predate the ecosystem sniffs).

## 6. Tracking

- Tracker row D8 flips 🟡 → ✅ when Clusters 0–3 land; the deferred buckets keep the row honest via cross-references to E1/E4/E6/F/D3.
- Tracker D5c deviation ("until tool execution ports (D8)") resolves with Cluster 0.
- `MIGRATION-GAPS.md` gains a one-line note under Wave E2 pointing at this plan for the tool layer.

## 7. Risks / notes

- **Envelope drift in the pre-ported core classes** — 148 tools may predate the canonical-envelope + two-gate sniffs; Cluster 1 batches must include a lint gate and fix drift as separate PRs (never silently change base behaviour).
- **The 43 core-only utilities** are additive surface — decide per tool whether to expose them standalone (recommended: yes, behind the registry manifest, since `tools/list` already advertises the core registry).
- **Async tools** (`remote_wp_connection`-style) depend on the E2 queue being live in standalone mode — Cluster 0's polling port must degrade to a visible error (not a hang) when the queue is absent, matching the v1.1.55+ base behaviour.
