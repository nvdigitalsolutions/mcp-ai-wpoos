# Measurement Dashboard (read-only)

The measurement dashboard is a WordPress admin page that gives operators a
single place to confirm the measurement subsystem is wired correctly. It
is intentionally **read-only** in this PR — the page writes nothing, runs
no AJAX handlers, and issues no outbound requests. Writable actions
(trigger eval runs, export metrics, purge buffers) land in a later PR
alongside the OpenTelemetry exporter.

## Location

Menu: **NV oOS → Measurement**
Slug: `wp-mcp-ai-measurement`
Capability: `manage_options`

If the plugin's top-level menu has not yet been mounted at page-render
time, the dashboard falls back to a **Tools → Measurement** submenu so
operators can still inspect state during early bootstrap debugging.

## Panels

1. **Summary** — count of metrics, verifiers, reward functions, eval
   suites, and recent events in the in-memory buffer.
2. **Metrics** — one row per registered metric: id, type, unit,
   direction, privacy tier, paired counter metric.
3. **Verifiers** — slug, label, kind, disallowed providers and disallowed
   models (from the independence profile). If a verifier lists no
   disallowed providers AND no disallowed models, it is an honor-system
   verifier — consider adding an independence profile before trusting
   its output.
4. **Reward Functions** — slug, label, bounded output range, paired
   counter metric, and the registry-required `anti_gaming` string. If
   you see an empty anti-gaming cell, a newer code path bypassed the
   registry's validation; file a bug.
5. **Eval Suites** — slug, label, case count, and tags. Cases are not
   listed individually to keep the page fast on sites with large
   corpora; use the (future) suite detail view for per-case drilldowns.
6. **Recent Events** — the last 50 events in the collector's in-memory
   ring buffer. This buffer is **per-request** and **in-memory only**;
   events disappear at the end of the request. Persistent storage is
   provided by the OTel exporter PR.

## What the dashboard deliberately does NOT show

- **Raw inputs or outputs** — surfacing generator inputs here would
  leak user content. The recent-events panel shows only the metric id,
  numeric value, and timestamp.
- **PII or secrets** — the collector sanitizes context values before
  buffering, and the dashboard re-sanitizes on render.
- **Cross-site aggregates** — multisite aggregation is the exporter's
  job, not the admin UI's.
- **Action buttons** — no "Run eval now", no "Clear buffer", no "Force
  export". Those ship with a proper nonce + capability story in the
  exporter PR.

## Extending

The dashboard reads directly from the singletons, so any verifier,
metric, reward, or suite registered through the standard hooks
(`wp_mcp_ai_register_metrics`, `wp_mcp_ai_register_verifiers`,
`wp_mcp_ai_register_reward_functions`, `wp_mcp_ai_register_eval_suites`)
shows up automatically on the next page load. No additional wiring is
required.
