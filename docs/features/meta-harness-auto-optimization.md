# Meta-Harness Auto-Optimization System

**Status:** Stable — v1.1.40
**Category:** Pro Feature — Agent Infrastructure
**Introduced:** July 2026 (PR #5649)

> **Evolution (v1.1.63):** The Continual Harness Evolver + this 7-phase pipeline are now upgraded into a complete gated Darwinian evolution loop for skills, prompts, and roles — see [`docs/project/proposals/007-artifact-evolution.md`](../../project/proposals/007-artifact-evolution.md) (Phases A–G: artifact populations, failure replay + post-mutation verification, pre-commit admission gate, shadow A/B deployment with drift rollback, evolution governor + approval queue). Every layer is opt-in and defaults off.  

## Overview

The Meta-Harness auto-optimization system is a self-improving agent infrastructure that enables the NV oOS plugin to observe, analyze, and self-optimize AI agent execution. Inspired by meta-learning principles, the harness captures execution telemetry, searches for patterns, proposes concrete improvements, and deploys optimizations to production — all through a structured 7-phase pipeline.

## Architecture

### Phase 0–1: Trace Store & Trace Capture

The foundation layer captures detailed execution traces from every AI agent interaction:

- **Trace Capture** — hooks into the tool execution pipeline to record: tool name, arguments, duration, provider, model, token usage, error states, and outcome (success/failure/partial).
- **Trace Store** — persistent storage with queryable indexes by agent, tool, outcome, timing, and provider. Traces are structured with JSON schemas for consistent querying.
- **Storage Backend** — uses WordPress options API with batched writes to avoid per-call overhead. Configurable retention period.

### Phase 2: Harness Search Engine

The telemetry querying layer provides:

- **Full-text search** across trace descriptions, error messages, and tool names.
- **Faceted filtering** by agent ID, tool slug, outcome (success/error/timeout), date range, and provider.
- **Aggregation queries** — count by tool, average duration, error rate, token cost trends.
- **WP-CLI integration** — `wp mcp-ai harness search` subcommand for terminal-accessible queries.
- **Admin UI** — Harness Search page under **NV oOS → Harness** with filterable results table.

### Phase 3: Pro Coding-Agent Proposer

The automated improvement suggestion engine:

- **Pattern Detection** — analyzes traces for recurring failures, slow tools, high-token-cost patterns, and suboptimal prompt routing.
- **Proposal Generation** — produces structured optimization proposals: prompt refinements, tool selection changes, temperature/parameter adjustments, model routing improvements.
- **Confidence Scoring** — each proposal carries a confidence score based on sample size, pattern consistency, and historical accuracy.
- **Human-in-the-Loop** — proposals are reviewed in the admin UI before deployment. One-click accept/reject with rationale.

### Phases 4–6: Cues, Population, Auto-Deploy, DSpark

The orchestration and deployment layer:

- **Cues System** — triggers optimization runs based on configurable thresholds: error rate exceeding N%, average tool duration above threshold, token cost spike detection, new tool adoption patterns.
- **Population Pipelines** — batch-processes historical traces through the proposer to generate retrospective improvement candidates. Chunked processing with resumability for large trace volumes.
- **Auto-Deploy** — pushes approved optimizations to production: updates tool presets, modifies assistant configurations, adjusts safety profile thresholds. All changes are logged with rollback capability.
- **DSpark Speculative Orchestration** — coordinates the full optimization lifecycle with parallel execution where possible. Manages proposal queues, schedules population runs, and enforces rate limits to avoid overwhelming AI providers.

### Phase 7: Test Infrastructure

Comprehensive test coverage for the entire harness system:

- **PHPUnit tests** for Trace Store CRUD, search engine queries, proposer pattern detection, and auto-deploy safety checks.
- **Mock traces** with controlled failure patterns for deterministic proposer testing.
- **Integration tests** validating the full pipeline: capture → store → search → propose → cue → deploy.

## Configuration

### Enabling the Harness

The meta-harness is enabled by default for Pro installations. Control via:

```php
// wp-config.php
define( 'WP_MCP_AI_META_HARNESS_ENABLED', true );
```

### Key Settings

| Setting | Default | Description |
|---------|---------|-------------|
| `meta_harness_trace_retention_days` | 30 | Days to retain execution traces |
| `meta_harness_auto_propose` | false | Auto-generate proposals without manual trigger |
| `meta_harness_cue_error_threshold` | 0.05 | Error rate threshold for auto-cueing (5%) |
| `meta_harness_cue_duration_threshold_ms` | 10000 | Tool duration threshold for auto-cueing (10s) |
| `meta_harness_population_batch_size` | 100 | Traces per batch during population runs |

## WP-CLI Commands

```bash
# View harness status
wp mcp-ai harness status

# Search traces
wp mcp-ai harness search --tool=delegate_to_agent --outcome=error --days=7

# Trigger a population run
wp mcp-ai harness populate --agent-id=42

# List pending proposals
wp mcp-ai harness proposals --status=pending

# Accept a proposal
wp mcp-ai harness accept --proposal-id=15

# Run the cue engine
wp mcp-ai harness cue --check
```

## Hooks & Filters

| Hook | Type | Description |
|------|------|-------------|
| `wp_mcp_ai_harness_trace_captured` | Action | Fires after a trace is stored. Receives trace ID and data. |
| `wp_mcp_ai_harness_proposal_generated` | Action | Fires after a proposal is created. Receives proposal ID. |
| `wp_mcp_ai_harness_before_deploy` | Action | Fires before auto-deploy applies changes. Receives proposal. |
| `wp_mcp_ai_harness_after_deploy` | Action | Fires after auto-deploy completes. Receives result. |
| `wp_mcp_ai_harness_trace_filter` | Filter | Modify trace data before storage. |
| `wp_mcp_ai_harness_cue_thresholds` | Filter | Override cue thresholds per-environment. |

## Security Considerations

- All proposals are reviewed before deployment — no automatic changes without explicit acceptance.
- Trace data is stored with the same access controls as other plugin data (requires `manage_options` capability).
- Sensitive arguments (API keys, passwords, tokens) are redacted from traces before storage.
- Trace retention respects GDPR/data-minimization — configurable retention with automatic cleanup.

## Related Documentation

- [Agent Delegation System](agent-delegation-system.md) — delegation improvements in v1.1.39
- [LLM Harness](llm-harness.md) — the LLM evaluation and curriculum system
- [ROADMAP.md](../ROADMAP.md) — release timeline and future plans
