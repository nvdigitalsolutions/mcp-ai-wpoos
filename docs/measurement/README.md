# NV oOS Measurement Subsystem

The measurement subsystem is the foundation for evals, verifiers, reward
functions, and observability across NV oOS. It is designed around two
principles:

- **Goodhart-aware design** — no single KPI defines success; every metric is
  paired with an adversarial counter-metric.
- **Verifier's law** — verifiers must be orthogonal to the generator. The
  registry enforces this with an independence profile per verifier.

This document indexes the core primitives shipped in the base plugin. Later
PRs add the eval harness, admin dashboard, OTel exporter, and Pro toolkit
manifests (per the rollout strategy in the original plan).

## Files

| File | Purpose |
|------|---------|
| `class-wp-mcp-ai-measurement-registry.php` | Metric definitions (id, type, unit, direction, privacy tier, counter-metric) |
| `class-wp-mcp-ai-metric-collector.php` | Sampling, in-memory ring buffer, `wp_mcp_ai_metric_recorded`, `wp_mcp_ai_measurement_export` |
| `interface-wp-mcp-ai-verifier.php` | Contract all verifiers implement |
| `class-wp-mcp-ai-verifier-base.php` | Convenience base class for verifier authors |
| `class-wp-mcp-ai-verifier-registry.php` | Lookup + independence enforcement + `wp_mcp_ai_verifier_result` |
| `class-wp-mcp-ai-reward-function-registry.php` | Reward functions with mandatory anti-gaming safeguards |
| `class-wp-mcp-ai-measurement-bootstrap.php` | Singletons boot; admin capabilities wiring |

## Hook Reference

| Hook | Kind | When |
|------|------|------|
| `wp_mcp_ai_register_metrics` | action | During bootstrap; register metric definitions here |
| `wp_mcp_ai_register_verifiers` | action | During bootstrap; register verifiers here |
| `wp_mcp_ai_register_reward_functions` | action | During bootstrap; register reward functions here |
| `wp_mcp_ai_metric_recorded` | action | Fires after a metric is buffered |
| `wp_mcp_ai_verifier_result` | action | Fires after a verifier runs |
| `wp_mcp_ai_measurement_export` | filter | Use to tee events to APMs/OTel (apply redaction here) |
| `wp_mcp_ai_measurement_admin_capabilities` | filter | Customize which caps get auto-granted to admins |

## Capabilities

- `view_wp_mcp_ai_measurements` — read metrics dashboards and exports.
- `manage_wp_mcp_ai_measurements` — register metrics, change sample rates,
  run evals, configure exporters.

These capabilities are granted to the `administrator` role on `admin_init`
unless filtered.

## See also

- [`conventions.md`](conventions.md) — naming, OTel mapping, NIST AI RMF alignment
- [`goodhart-checklist.md`](goodhart-checklist.md) — authoring checklist for new metrics
- [`privacy-matrix.md`](privacy-matrix.md) — privacy tiers and redaction policy
