# Model Foundry — Corpus Foundry (Phase 1)

> Feature doc for the **Corpus Foundry** slice of the Model Foundry
> (Proposal 067). Proposal: [`docs/project/proposals/067-cluster-fine-tuning-model-foundry.md`](../project/proposals/067-cluster-fine-tuning-model-foundry.md) ·
> Implementation plan: [`…-implementation-plan.md`](../project/proposals/067-cluster-fine-tuning-model-foundry-implementation-plan.md) ·
> Code: [`addons/pro/includes/model-foundry/`](../../addons/pro/includes/model-foundry/README.md).
> Last reviewed: October 11, 2026 (Phase 1 shipped).

## What it is

The Corpus Foundry distils cluster-owned data — harness trace runs,
preference pairs, and plugin documentation — into **versioned,
trainer-agnostic fine-tuning corpora**. It is the *data-out* half of the
Model Foundry flywheel; training, registry, and serving (Phases 2–6) are
deliberately out of scope for this slice.

The foundry extends the existing harness stack the same way Layer H does:
Base harness Layers A–G capture and eval; Layer H exports SFT curricula from
eval suites; **the Corpus Foundry adds trajectory, preference, and docs
corpora on top** — it never replaces Layer H.

## Components

| Component | Role |
|---|---|
| `WP_MCP_AI_Corpus_Schema_Registry` | Versioned row schemas: `chat_v1`, `trajectory_v1`, `preference_v1`, `docs_v1` |
| `WP_MCP_AI_Corpus_Deduper` | Exact → fuzzy → (opt-in) semantic dedup |
| `WP_MCP_AI_Corpus_Pii` | Deterministic PII scrub (`WP_MCP_AI_Pii_Filter`) + provider-backed scrub seam |
| `WP_MCP_AI_Corpus_Governance` | Consent gate, pipeline order, holdout split, R12 provenance manifest, guarded writes |
| `WP_MCP_AI_Preference_Pair_Store` | Bounded per-assistant DPO pair store (FIFO) |
| `WP_MCP_AI_Tool_Export_Trajectory_Corpus` | `export_trajectory_corpus` |
| `WP_MCP_AI_Tool_Export_Preference_Pairs` | `export_preference_pairs` |
| `WP_MCP_AI_Tool_Export_Plugin_Docs_Corpus` | `export_plugin_docs_corpus` |

## Consent (fails closed)

Exports require per-assistant training consent:

- Post meta `_wp_mcp_ai_training_consent` = `'1'` grants consent.
- Absent / `'0'` → `WP_Error( 'wp_mcp_ai_training_consent_required' )` before
  **any** source data is read.
- Site-level override: `wp_mcp_ai_model_foundry_assistant_consented` filter
  (returns `bool`, `null` = use the meta gate).

The admin UI to set the meta ships in Phase 3 (registry phase); until then
the filter is the operational path.

## Pipeline order (fixed)

```
per-case caps → exact dedup → fuzzy dedup → (opt-in) semantic dedup →
PII scrub → deterministic holdout split → R12 manifest → guarded write
```

- **Skip, never truncate** — oversize/invalid rows are skipped with named
  reasons (`skipped_too_large`, `skipped_no_user_input`, `skipped_low_margin`,
  `skipped_invalid_row`, …).
- **Holdout split** is a content-hash modulo (`md5(row) % 5 == 0` → holdout,
  ~20%), computed *after* dedup: a row is deterministically train-or-holdout
  forever, holdout rows are never written into `train.jsonl`
  (decontamination by construction), and a duplicate can never straddle the
  split.
- **Semantic dedup** is opt-in (`semantic_dedup` argument), capped at
  `WP_MCP_AI_Corpus_Deduper::DEFAULT_SEMANTIC_MAX_ROWS` rows, and embeds via
  the `wp_mcp_ai_model_foundry_embed_text` filter (default: the site's vector
  context service). No embedding provider → exact+fuzzy only, never a fatal.

## Output layout

```
wp-content/uploads/mcp-ai/model-foundry/<assistant_id>/<corpus_id>/
    train.jsonl       # training rows (never contains holdout rows)
    holdout.jsonl     # holdout rows (when the split is enabled)
    manifest.json     # R12 provenance (sources, consent, dedup/PII stats, shard hashes)
    .htaccess / index.php   # web-server guards
```

`corpus_id` = `{schema}-{Ymd-His}`. The manifest is written **last** so its
presence signals a complete corpus.

## Row schemas

| Schema | Format | Shape |
|---|---|---|
| `chat_v1` | `openai_chat_jsonl` | `{"messages":[{role,content},…]}` |
| `trajectory_v1` | `openai_chat_jsonl` | tool-calling rows: assistant `tool_calls` + `role:tool` answers + final assistant content |
| `preference_v1` | `openai_preference_jsonl` | DPO: exactly `prompt` / `chosen` / `rejected` strings (no lists — R4) |
| `docs_v1` | `text_jsonl` | `{"text": …, "meta": {source,path,hash,bytes}}` |

## Tools

All three require `manage_options`, Pro, consent, and follow the canonical
Pro tool envelope. `dry_run` previews counts + first row without writing.

### `export_trajectory_corpus`

Walks the assistant's harness trace runs (`tool_calls.jsonl`,
`model_response.txt`, `retrieval.json`) and emits one tool-calling row per
run. User prompts come from the retrieval query; the
`wp_mcp_ai_model_foundry_run_user_prompt` filter supplies prompts for runs
where retrieval never fired. Runs without a prompt or tool calls are
skipped with named reasons.

### `export_preference_pairs`

Reads `WP_MCP_AI_Preference_Pair_Store` (populated by other systems via
`record()` — chat-UI thumbs and eval-based rejection sampling land in later
phases) plus pairs contributed by the `wp_mcp_ai_model_foundry_preference_pairs`
filter, and emits DPO rows. `min_margin` skips pairs below a stored judge
margin; margin-less pairs always pass.

### `export_plugin_docs_corpus`

Scans the docs-hub uploads content folder (default) or an explicit `dir`
that must realpath inside the uploads basedir (symlink-escape hardened).
Reads `.md`/`.markdown`/`.txt` only; skips dotfiles and
`vendor`/`node_modules`/`.git` directories.

## Filters & seams

| Hook | Purpose |
|---|---|
| `wp_mcp_ai_model_foundry_assistant_consented` | Consent override |
| `wp_mcp_ai_model_foundry_embed_text` | Semantic-dedup embedding callable |
| `wp_mcp_ai_model_foundry_extra_scrub` | Provider-backed free-text PII scrub |
| `wp_mcp_ai_model_foundry_run_user_prompt` | User prompt for retrieval-less trace runs |
| `wp_mcp_ai_model_foundry_preference_pairs` | Contributed preference pairs |
| `wp_mcp_ai_model_foundry_trajectory_per_case_char_cap` | Trajectory row size cap |
| `wp_mcp_ai_model_foundry_docs_per_file_char_cap` | Docs per-file size cap |

## Placement & conventions

- Pro addon only; registered via the `model_foundry` module in
  `WP_MCP_AI_Pro_Module_Registry` (`model-foundry-init.php` hooks
  `wp_mcp_ai_pro_tools`). Safe to deactivate independently.
- Tools join the `ai_ml` preset (model-management group, alongside
  `export_fine_tune_curriculum`).
- Deviation from the implementation plan §1.3: Layer H's `format` enum is
  untouched — `trajectory_v1` is owned solely by `export_trajectory_corpus`.

## Tests

```bash
vendor/bin/phpunit addons/pro/tests/test-model-foundry-governance.php
vendor/bin/phpunit addons/pro/tests/test-model-foundry-exporters.php
```

33 tests / 156 assertions; validated on WP 6.9 + WP 7.1.3.

## What's next (not in this slice)

Phase 2 train-worker sidecar · Phase 3 registry + serving + model cards ·
Phase 4 eval gate & promotion · Phase 5 automation + portability bundle v2 ·
Phase 6 embeddings/multimodal/GRPO.
