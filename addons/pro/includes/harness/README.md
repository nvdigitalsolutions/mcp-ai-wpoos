# Harness (Pro) — Layer H: Fine-Tune Curriculum Export

## Purpose

Implements Pro **Layer H** of the LLM harness — exporting an assistant's pinned eval suites as a portable JSONL fine-tune corpus — and nothing else.

This folder **extends** the Base harness in [`includes/harness/`](../../../../includes/harness/) (Layers A–G: Profile, Prompt cues, Reasoning/Self-consistency, Tool routing, Retrieval, Self-Refine, PII filter, Eval scheduling). It does **not** replace any Base layer; it adds the SFT-export slice on top.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | [`addons/pro/includes/harness-init.php`](../harness-init.php) — required from `addons/pro/mcp-ai-wpoos-pro.php` inside `wp_mcp_ai_pro_init()`; the tool class file is lazy-loaded via the `wp_mcp_ai_pro_tools` filter |
| **Optional dependencies** | none at runtime — the exporter reads case bodies from the Base `WP_MCP_AI_Eval_Suite_Registry` and writes JSONL to `wp-content/uploads/mcp-ai/harness-curriculum/` |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Export_Fine_Tune_Curriculum` | `class-wp-mcp-ai-tool-export-fine-tune-curriculum.php` | registered through `wp_mcp_ai_pro_tools` → the global tool registry; callable from chat, REST, CLI |
| `wp_mcp_ai_pro_register_harness_tools()` | `../harness-init.php` | hooked at priority 10 on `wp_mcp_ai_pro_tools` |

Stable contract: tool slug, JSONL row shape (OpenAI chat-format: `{"messages":[system,user,assistant]}`), `HARD_MAX_CASES = 5000`, `DEFAULT_PER_CASE_CHAR_CAP = 16000`, `EXPORT_SUBDIR = 'mcp-ai/harness-curriculum/'`.

## Inputs / Outputs / Neighbors

- **Reads from:** the assistant's `harness_profile.evals_enabled` set (resolved via Base `WP_MCP_AI_Harness_Profile`), the Base `WP_MCP_AI_Eval_Suite_Registry` (suite + case bodies), the case fields `input` / `expected` / `system`.
- **Writes to:** a JSONL file under `wp-content/uploads/mcp-ai/harness-curriculum/`, plus a tool-execution result envelope containing the file URL, row count, and per-case skip reasons.
- **Upstream callers:** the global tool registry (any caller of `tools/call` with this slug), the Pro admin "Harness" tab, the harness eval scheduler when invoked with an export step.
- **Downstream collaborators:** the Base harness subsystem ([`includes/harness/`](../../../../includes/harness/)) for the profile + eval-suite registry, the WP uploads dir API, [`includes/measurement/`](../../../../includes/measurement/) (tool-execution observer records the export run).
- **Events fired:** the standard tool-execution hooks (`wp_mcp_ai_tool_execution_started/completed/failed`) — no harness-specific events from this folder.
- **Events listened to:** `wp_mcp_ai_pro_tools` (filter) for tool registration.

## Conventions

- **Layer H exports; it does not run.** Never call the model from this folder. Running an eval against a live model is the Base Layer-G scheduler's job (see [`includes/harness/class-wp-mcp-ai-harness-eval-scheduler.php`](../../../../includes/harness/class-wp-mcp-ai-harness-eval-scheduler.php)).
- **`HARD_MAX_CASES` is a ceiling, not a default.** Admins exporting larger corpora must chunk by suite; do not raise the constant to dodge the cap.
- **Per-case payload cap is `DEFAULT_PER_CASE_CHAR_CAP` characters** (input + expected). Cases over the cap are skipped with reason `skipped_too_large` rather than truncated — silent truncation would poison a fine-tune corpus.
- **Non-string inputs/expecteds are `wp_json_encode`-d** so the row stays well-formed. Do not introduce a custom serialiser.
- **Honour the canonical Pro tool envelope** (`WP_MCP_AI_Tool_Interface` return contract). The two-gate sanitisation rule applies: sanitise `$arguments` at entry, escape any values that surface in admin messages at exit.
- The Pro slice **must be safe to deactivate** independently — keep all wiring inside this folder + `harness-init.php`. Base Layers A–G must keep working when Layer H is unloaded.

## Tests

```bash
vendor/bin/phpunit addons/pro/tests/test-harness-fine-tune-curriculum.php
```

For the Base harness layers this folder extends, see [`includes/harness/README.md`](../../../../includes/harness/README.md) → "Tests".

## Also Load

- [`includes/harness/README.md`](../../../../includes/harness/README.md) — Base harness Layers A–G (mandatory pre-read for any change here)
- [`docs/llm-harness.md`](../../../../docs/llm-harness.md) — the full Layers A–H reference (authoritative)
- [`.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — uploads dir, PII (run case bodies through `WP_MCP_AI_PII_Filter::scrub()` before export when the assistant has PII filtering enabled)
- [`.context/tool-registry.md`](../../../../.context/tool-registry.md) — canonical tool envelope
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — Layer H placement rationale
- [`CLAUDE.md`](../../../../CLAUDE.md) — PHP-compat (8.1+)

## See Also

- Base counterpart: [`../../../../includes/harness/`](../../../../includes/harness/) — Layers A–G this folder builds on
- Sibling: [`../measurement/`](../measurement/) — eval-suite scoring lives there
- Sibling: [`../../../../includes/tools/harness/`](../../../../includes/tools/harness/) — Base harness tool surface (7 tools)
