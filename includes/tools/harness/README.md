# Harness Tools

## Purpose

Houses 7 LLM-harness MCP tools that manage prompt cues, memory scoping, retrieval provenance, reflections, and self-consistency voting — the Layer A–F primitives of the AI reasoning pipeline.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | `includes/tools-init.php` via tool registry auto-discovery |
| **Optional dependencies** | none |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Apply_Prompt_Cue` | `class-wp-mcp-ai-tool-apply-prompt-cue.php` | AI assistant system-prompt augmentation |
| `WP_MCP_AI_Tool_List_Prompt_Cues` | `class-wp-mcp-ai-tool-list-prompt-cues.php` | AI assistant, admin diagnostics |
| `WP_MCP_AI_Tool_Record_Reflection` | `class-wp-mcp-ai-tool-record-reflection.php` | Self-refine loop (Layer E) |
| `WP_MCP_AI_Tool_Retrieve_With_Provenance` | `class-wp-mcp-ai-tool-retrieve-with-provenance.php` | AI assistant retrieval (Layer D) |
| `WP_MCP_AI_Tool_Scope_Memory` | `class-wp-mcp-ai-tool-scope-memory.php` | Harness self-refine loop (Layer F) |
| `WP_MCP_AI_Tool_Select_Prompt_Cue` | `class-wp-mcp-ai-tool-select-prompt-cue.php` | AI assistant cue router (Layer A) |
| `WP_MCP_AI_Tool_Self_Consistency_Vote` | `class-wp-mcp-ai-tool-self-consistency-vote.php` | Test-time compute (Layer B) |

All classes implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`. All require `edit_posts`.

## Inputs / Outputs / Neighbors

- **Reads from:** `WP_MCP_AI_Prompt_Cue_Library`, `WP_MCP_AI_Retrieval_Harness`, `WP_MCP_AI_Self_Refine_Loop`, `WP_MCP_AI_Harness_Profile`, `WP_MCP_AI_Reasoning_Trace`
- **Writes to:** agent memory (via `WP_MCP_AI_Self_Refine_Loop::record_reflection`)
- **Upstream callers:** `includes/tools/` (tool registry), REST API chat endpoint
- **Downstream collaborators:** `includes/llm-harness/` (Prompt Cue Library, Retrieval Harness, Reasoning Trace, Self-Refine Loop, Harness Profile)
- **Events fired:** none directly (tools return canonical envelopes)
- **Events listened to:** none

## Conventions

- All tools follow the canonical return envelope: `array('success' => true, ...)` on success, `WP_Error` on failure.
- Every tool implements the two-gate sanitisation rule: sanitize `$arguments` at entry, escape every value at exit.
- Capability flags are consistently declared via `get_capability_flags()` (e.g. `read-only`, `local-only`, `idempotent`, `cacheable`).
- Task classes use reserved buckets: `general`, `math`, `code`, `qa`, `rag`, `research`, `reasoning`, `agentic`, `this-site`, `this-user`.

## Tests

```bash
vendor/bin/phpunit tests/test-harness-tools.php
```

Harness tool tests validate cue application, memory scoping, reflection persistence, retrieval deduplication, and self-consistency voting.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../.context/tool-registry.md) — tool registration and lifecycle
