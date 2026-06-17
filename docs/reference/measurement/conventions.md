# Measurement Conventions

## Metric identifiers

Metric ids use a dotted lowercase namespace:

```
<domain>.<subject>.<aspect>
```

Examples:

- `tool.execution.success_rate`
- `tool.execution.unjustified_confidence`  (paired counter-metric)
- `chat.cost.per_successful_turn`
- `verifier.agreement.rate`
- `agent.abstention.rate`

Allowed characters: `[a-z0-9_.-]`. IDs are lowercased and trimmed during
registration.

## OpenTelemetry mapping

Each metric MAY declare an `otel_attribute` describing how it maps to the
OpenTelemetry GenAI semantic conventions (e.g. `gen_ai.request.model`,
`gen_ai.usage.input_tokens`). Exporters registered on
`wp_mcp_ai_measurement_export` are expected to emit these attributes on the
corresponding span/metric so downstream APMs can correlate traces with
measurements without custom mapping rules.

## NIST AI RMF alignment

Metrics should be documented against the NIST AI RMF generate/measure/manage
function they support. The admin dashboard (PR 3) groups metrics by function
so compliance reviewers can find the measurements they need.

## Direction

Always set `direction` explicitly:

- `higher_is_better` — e.g. verifier agreement, grounding score.
- `lower_is_better` — e.g. latency, cost-per-success, hallucination rate.
- `neutral` — e.g. token counts, calibration error.

The direction hint is used by the dashboard to colour trends and by anomaly
detection to decide which direction of drift is concerning.

## Counter-metrics (Goodhart guard)

Every "outcome" metric SHOULD declare a `counter_metric` — an adversarial
measurement that would reveal gaming of the primary metric. Examples:

| Primary metric | Counter-metric |
|----------------|----------------|
| `tool.execution.success_rate` | `tool.execution.unjustified_confidence` |
| `agent.task_success` | `cost.per_success` |
| `moderation.safe_reply_rate` | `moderation.false_positive_rate` |

Metrics without a counter-metric are surfaced in the admin dashboard as a
Goodhart risk. This is a warning, not a blocker, because third-party metrics
may be deliberately one-sided.

## Privacy tiers

| Tier | Use for | Storage |
|------|---------|---------|
| `public` | Aggregated public dashboards (counts, rates) | Plain |
| `internal` | Site-admin dashboards | Plain |
| `sensitive` | PII-adjacent, consent-gated | Redacted + optional field-level encryption |
| `restricted` | PHI, legal privilege, SOX data | Encrypted; never exported without explicit allow-list |

Privacy tier is a declarative property of the metric definition; enforcement
lives in the collector, exporters, and admin UI.

## Do not

- Do not put raw prompts, raw tool arguments, or user content in
  `attributes`. Hash them first.
- Do not register metrics that combine different privacy tiers in one value
  (split into multiple metrics instead).
- Do not re-use metric ids across distributions (base vs Pro) — namespace
  them, e.g. `pro.healthcare.imaging.abstention_rate`.
