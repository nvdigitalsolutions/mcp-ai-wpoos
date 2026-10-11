# Model Foundry — Corpus Foundry (Pro, Phase 1)

## Purpose

Implements the **Corpus Foundry** slice of the Model Foundry (Proposal 067):
distilling cluster-owned data (harness traces, preference pairs, docs-hub
markdown) into versioned, trainer-agnostic fine-tuning corpora — and nothing
else. No training, no model registry, no serving in this folder.

This folder extends the Pro harness slice ([`../harness/`](../harness/), Layer H)
the same way Layer H extends the Base harness Layers A–G. It does **not**
replace Layer H; the curriculum exporter there remains the SFT path for eval
suites, and this folder adds trajectory, preference, and docs corpora on top.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | [`model-foundry-init.php`](model-foundry-init.php) — required from the Pro module registry ([`../class-wp-mcp-ai-pro-module-registry.php`](../class-wp-mcp-ai-pro-module-registry.php), module `model_foundry`); tool classes are lazy-loaded via the `wp_mcp_ai_pro_tools` filter |
| **Optional dependencies** | Docs Hub addon (only for `export_plugin_docs_corpus`'s default source); no runtime dependency otherwise |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Corpus_Schema_Registry` | `class-wp-mcp-ai-corpus-schema-registry.php` | Row-schema definitions (`chat_v1`, `trajectory_v1`, `preference_v1`, `docs_v1`) + validation |
| `WP_MCP_AI_Corpus_Deduper` | `class-wp-mcp-ai-corpus-deduper.php` | Exact / fuzzy / optional semantic dedup |
| `WP_MCP_AI_Corpus_Pii` | `class-wp-mcp-ai-corpus-pii.php` | PII scrub orchestration (deterministic tier now; provider-backed tier seam) |
| `WP_MCP_AI_Corpus_Governance` | `class-wp-mcp-ai-corpus-governance.php` | Consent gate, pipeline order, holdout split, R12 provenance manifest, guarded writes |
| `WP_MCP_AI_Preference_Pair_Store` | `class-wp-mcp-ai-preference-pair-store.php` | Bounded, option-based chosen/rejected pair store (FIFO) |
| `WP_MCP_AI_Tool_Export_Trajectory_Corpus` | `class-wp-mcp-ai-tool-export-trajectory-corpus.php` | `export_trajectory_corpus` tool |
| `WP_MCP_AI_Tool_Export_Preference_Pairs` | `class-wp-mcp-ai-tool-export-preference-pairs.php` | `export_preference_pairs` tool |
| `WP_MCP_AI_Tool_Export_Plugin_Docs_Corpus` | `class-wp-mcp-ai-tool-export-plugin-docs-corpus.php` | `export_plugin_docs_corpus` tool |
| `wp_mcp_ai_pro_register_model_foundry_tools()` | `model-foundry-init.php` | hooked at priority 10 on `wp_mcp_ai_pro_tools` |

Stable contracts: tool slugs, the four schema keys, `train.jsonl` +
`holdout.jsonl` + `manifest.json` output layout, and the
`_wp_mcp_ai_training_consent` consent meta key (`'1'` = consented).

## Inputs / Outputs / Neighbors

- **Reads from:** `WP_MCP_AI_Harness_Trace_Store` (runs, `tool_calls.jsonl`,
  `model_response.txt`, `retrieval.json`), `WP_MCP_AI_Preference_Pair_Store`,
  the Docs Hub uploads content dir (`wp_upload_dir()['basedir'] . '/nvoos-docs-hub/content'`,
  overridable via `NV_oOS_Docs_Hub_Plugin::uploads_docs_dir()` when the addon
  is active), the assistant's `_wp_mcp_ai_training_consent` and
  `_wp_mcp_ai_assistant_instructions` post meta.
- **Writes to:** `wp-content/uploads/mcp-ai/model-foundry/<assistant_id>/<corpus_id>/`
  — `train.jsonl`, `holdout.jsonl` (when the split is enabled), `manifest.json`
  — with `.htaccess` + `index.php` guards.
- **Upstream callers:** the global tool registry (any caller of `tools/call`),
  the Pro admin UI (Phase 3+), the train worker (Phase 2, via the file URLs).
- **Downstream collaborators:** Base harness (trace store, PII filter
  `WP_MCP_AI_Pii_Filter::scrub()`), the vector context service (optional
  semantic-dedup embeddings), the Docs Hub scanner (optional default source).
- **Events fired:** the standard tool-execution hooks — no foundry-specific
  events from this folder.
- **Events listened to:** `wp_mcp_ai_pro_tools` (filter) for tool registration.

## Conventions

- **Exporters export; they do not train and do not call the chat model.**
  Semantic dedup is the only provider-bound path and it is opt-in
  (`semantic_dedup` argument, default false), capped, and embedding-only.
- **Consent fails closed.** `_wp_mcp_ai_training_consent` absent/`'0'` →
  `WP_Error( 'wp_mcp_ai_training_consent_required' )` before any row is read.
  The `wp_mcp_ai_model_foundry_assistant_consented` filter is the site-level
  override seam (returns bool).
- **Pipeline order is fixed:** caps → exact dedup → fuzzy dedup → (optional)
  semantic dedup → PII scrub → deterministic holdout split (content-hash
  modulo, 20% default) → manifest → sharded write. Holdout rows are never
  written into `train.jsonl` (decontamination by construction).
- **Skip, never truncate.** Oversize rows are skipped with named reasons
  (`skipped_too_large`, `skipped_no_user_input`, …) — silent truncation would
  poison the corpus.
- **Honour the canonical Pro tool envelope** (`WP_MCP_AI_Tool_Interface`
  return contract). Two-gate sanitisation: sanitise `$arguments` at entry,
  escape values that surface in admin-facing messages at exit.
- **Deviation from the 067 implementation plan:** Layer H's `format` enum is
  left untouched — `trajectory_v1` is owned solely by
  `export_trajectory_corpus` (one builder, not two). Recorded here so the
  plan can be corrected.
- The slice **must be safe to deactivate independently** — everything is
  wired through `model-foundry-init.php`; the harness slice keeps working
  when this module is unloaded.

## Tests

```bash
vendor/bin/phpunit addons/pro/tests/test-model-foundry-governance.php
vendor/bin/phpunit addons/pro/tests/test-model-foundry-exporters.php
```

## Also Load

- [`../harness/README.md`](../harness/README.md) — Layer H precedent (mandatory pre-read)
- [`docs/project/proposals/067-cluster-fine-tuning-model-foundry.md`](../../../../docs/project/proposals/067-cluster-fine-tuning-model-foundry.md) + [implementation plan](../../../../docs/project/proposals/067-cluster-fine-tuning-model-foundry-implementation-plan.md)
- [`.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — uploads dir, PII, path traversal
- [`.context/tool-registry.md`](../../../../.context/tool-registry.md) — canonical tool envelope
- [`CLAUDE.md`](../../../../CLAUDE.md) — PHP-compat (8.1+)
