---
name: gateway-site-operations
description: Explore and inspect the public NV oOS demo site through the fleet MCP gateway — environment status, capabilities, and the namespaced demo_* tool surface. Read-first workflows for anyone evaluating NV oOS.
---

Work against the connected `nvoos_gateway` MCP server. The gateway exposes the
public NV oOS demo site (`demo`) with tool names prefixed `demo_`.

Read-only tools first:

- `demo_get_environment_status` — the demo site's live environment report
  (WordPress version, active plugins, configured AI providers). Ground every
  claim about the demo site in this tool's output.
- `demo_deep_research` — long-running web research with citations. For
  standard/comprehensive depth prefer `run_mode: "background"`; immediate
  mode may exceed client-side tool timeouts.
- Discover anything else available with `tools/list` and read each tool's
  schema before calling it.

Rules:

- The gateway scopes access per site: this plugin only reaches the public
  `demo` site. Never claim access to other sites or clients.
- Never call a destructive or write tool without explicit user confirmation,
  including the exact IDs that will be affected.
- A failing site surfaces as `_meta.gateway.errors` — report degradation
  honestly instead of guessing.
- Quote tool names with their `demo_` prefix; do not invent tools that are
  not in the `tools/list` response.
