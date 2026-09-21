# Tool Description Engineering Proposal

**Status:** Phase 1 implemented on `feat/tool-description-engineering-phase1`
**Date:** 2026-09-19
**Scope:** Base plugin + Pro addon (no global tool-surface restructuring)

## 1. Problem Statement

NV oOS exposes ~1,585 tools (~306 base + ~1,279 Pro) through assistant
payloads, per-toolkit MCP servers, and the REST `/tools` endpoint. Industry
research on MCP tool design identifies two failure modes that degrade
tool-selection accuracy at this scale:

1. **Bloat** — every tool definition consumes context tokens on every request,
   whether or not the tool is called (AWS ML Blog, Jul 2026).
2. **Confusion** — semantically similar tools and under-specified descriptions
   cause the model to pick the wrong tool or hallucinate calls.

NV oOS already mitigates both at the *selection* layer (presets, 33
per-toolkit MCP servers, `list_mcp_tools` discovery, skills progressive
disclosure). This proposal closes the remaining gap at the *description*
layer and makes the payload cap model-aware.

## 2. Research Summary

| Source | Finding |
|---|---|
| Block Engineering Playbook (60+ MCP servers) | Design top-down from workflows, not bottom-up from API endpoints. Consolidate *related read-only* tools with enum params. Tool names/descriptions/parameters are prompts. **Do not mix read and write in one tool** — it breaks permission models. |
| AWS ML Blog (Jul 2026) | The progression that works: rich descriptions → enums + defaults + ≤8 params → lazy loading → introspection → agent-as-tool. Anthropic research: on-demand detail cuts response tokens ~⅔; tool-search lazy loading cuts context up to 85%. |
| Digital Applied / AlphaSignal anti-pattern guides | The opposite failure mode is the "God Tool" — one tool with 15+ action strings is too coarse. Bundle related operations, keep single-intent names. |
| MCP Toolbox style guide | ~40 tools per server as an upper limit for reliable selection. |
| Anthropic tool-use docs | Descriptions steer when the model calls a tool; negative guidance ("when NOT to use") is supported and effective. Tool Search exists precisely to handle thousands of tools via on-demand loading. |

## 3. Decisions

### 3.1 Adopt: descriptions as prompt engineering

Descriptions become structured guidance, assembled at the registry layer:

- `get_description()` stays short (admin-UI friendly).
- New optional interface `WP_MCP_AI_Tool_Usage_Guidance_Interface` declares
  `when_to_use`, `when_not_to_use`, `related_tools`, and `notes`.
- `WP_MCP_AI_Tool_Registry::get_model_facing_description()` assembles the
  model-facing description: base description + guidance suffix (mirroring the
  existing data-contract suffix pattern).
- Enforced progressively via the new `WPMCPAI.Tools.ToolDescriptionGuidance`
  PHPCS sniff (advisory, severity 0 in the main ruleset until the sweep
  completes; full severity in `phpcs/WPMCPAI/ruleset.xml`).

### 3.2 Adopt: model-aware tool payload cap (opt-in)

The chat payload cap (`wp_mcp_ai_max_chat_tools`, default 100) stays
backward-compatible. An adaptive default derived from the model's context
window (40 tools ≤128k, 64 ≤256k, 100 larger) is implemented in
`WP_MCP_AI_Tool_Payload_Advisor`. Two opt-in layers, default off:

- **Site default** — option `wp_mcp_ai_adaptive_tool_cap` (or the filter of
  the same name).
- **Per-assistant override** — `_wp_mcp_ai_adaptive_tool_cap` meta
  (`on` / `off` / empty = inherit), editable in the Default Settings metabox
  on the Assistant edit screen; resolved by
  `WP_MCP_AI_Tool_Payload_Advisor::is_adaptive_cap_enabled( $assistant_config )`
  before `WP_MCP_AI_REST::build_tools_payload()` applies the cap.

### 3.3 Adopt: lazy schema retrieval through `list_mcp_tools`

`list_mcp_tools` gains two backward-compatible parameters:

- `tool_slug` — fetch one tool's full schema on demand.
- `include_schemas` (default `true`) — `false` returns a lean catalogue
  (name/description/toolkit/risk only), the Anthropic Tool Search pattern.

### 3.4 Reject: global CRUD collapse into mega-tools

Blanket consolidation (`manage_user(action=...)`) was evaluated and rejected:

- NV oOS granular tools are **composition units**: per-tool capability gating,
  risk levels, HITL approvals, the destructive-ops gate, presets, and the 33
  per-toolkit MCP servers all key off granular slugs.
- The proposed shape mixes read and write in one tool — explicitly flagged by
  Block's playbook as harmful for permission/approval UX.
- Weak-model support (Ollama, LM Studio) degrades more gracefully with
  granular tools + good descriptions than with large-enum mega-tools (AWS:
  descriptions tuned for one model confuse another).
- The action-enum pattern already exists where it is proven:
  `remote_wp_connection`, `toolkit_cpt`, `create_cron_job_validated`,
  Composio. New bundles would duplicate surface and add confusion.

Read-only bundling remains an available *authoring pattern* (documented in
`docs/features/tool-description-guidelines.md`) for new cross-surface tools,
not a refactor of existing granular tools.

## 4. Implementation Plan

### Phase 1 (this branch)

1. **Interface** — `WP_MCP_AI_Tool_Usage_Guidance_Interface` in
   `includes/interfaces/interface-wp-mcp-ai-tool.php`.
2. **Registry assembly** — `get_model_facing_description()` +
   `get_usage_guidance()` on `WP_MCP_AI_Tool_Registry`; `get_tool_definition()`
   returns the model-facing description (propagates to `WP_MCP_AI_Tool_Service`
   and the REST `/tools` endpoint for free).
3. **Chat payload wiring** — `WP_MCP_AI_REST::build_tools_payload()` uses the
   model-facing description before appending provider-fallback text.
4. **Payload advisor** — `includes/helpers/class-wp-mcp-ai-tool-payload-advisor.php`
   (context-window → recommended cap; opt-in setting `adaptive_tool_cap`).
5. **Lazy schema loading** — `tool_slug` / `include_schemas` params on
   `list_mcp_tools`; the tool becomes the first guidance exemplar.
6. **Sniff** — `phpcs/WPMCPAI/Sniffs/Tools/ToolDescriptionGuidanceSniff.php`;
   registered at severity 0 in `phpcs.xml.dist` (roll-out gate) and severity 5
   in `phpcs/WPMCPAI/ruleset.xml`.
7. **Exemplar sweep** — usage guidance applied to high-traffic tool families:
   core WP CRUD (`get_post`, `create_post`, `update_post`, `delete_post`,
   `get_recent_posts`), `web_search`, cron tools, `list_mcp_tools`, plus Pro
   exemplars (`remote_wp_connection`, `toolkit_cpt`, Gmail tools). The AI Tool
   Builder scaffold emits the new interface for generated tools.
8. **Docs** — this proposal, `docs/features/tool-description-guidelines.md`,
   `.context/tool-registry.md`, `CLAUDE.md` tool pattern note, presets doc.

### Phase 2 (complete — merged into `alpha-working`)

- [x] Swept all remaining tool classes to the guidance interface in cluster
      PRs against `alpha-working` (base `includes/tools/` and
      `addons/pro/includes/tools/`; legacy-format tools use the
      `WP_MCP_AI_Legacy_Tool_Wrapper` passthrough). Full-tree gate:
      `phpcs --standard=phpcs/WPMCPAI/ruleset.xml --severity=5 includes/tools
      addons/pro/includes/tools` passes 1,584/1,584 files with zero warnings.
- [x] Raised the sniff severity from 0 to 5 in `phpcs.xml.dist` (warnings,
      so CI error thresholds are unaffected; new tool classes without
      guidance now surface on PRs).
- [ ] Evaluate raising the count-cap default for small-context models once
      telemetry on `tools_truncated_for_chat` events is collected.

## 5. Success Criteria

- [ ] `composer run lint` stays clean (sniff silent at severity 0).
- [ ] Explicit sniff run (`phpcs --standard=phpcs/WPMCPAI/ruleset.xml --severity=5`)
      flags only tools missing guidance.
- [ ] PHPUnit: new tests for the registry assembly, payload advisor, and
      `list_mcp_tools` parameters pass.
- [ ] No changes to existing tool slugs, capabilities, or return envelopes.

## 6. References

- Block Engineering — <https://engineering.block.xyz/blog/blocks-playbook-for-designing-mcp-servers>
- AWS ML Blog — <https://aws.amazon.com/blogs/machine-learning/mcp-tool-design-practical-approaches-and-tradeoffs/>
- MCP Toolbox Style Guide — <https://mcp-toolbox.dev/reference/style-guide/>
- Anthropic tool-use docs — <https://docs.anthropic.com/en/docs/build-with-claude/tool-use/overview>
