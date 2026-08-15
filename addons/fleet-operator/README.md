# Fleet Operator (Hermes) Addon

## Purpose

Issues **external-operator credentials** so a supervisor agent — Nous Research's
[Hermes Agent](https://github.com/NousResearch/hermes-agent) or any MCP/A2A host —
can operate this WordPress site within a declared scope, while the human manages
the supervisor. "Hermes manages the site; you manage Hermes."

The addon provides:

- `op_xxxx.SECRET` credentials bound to the site URL, a WordPress user
  (the authorizing human), an expiry, a rate limit, and a tool allowlist.
- **Server-side `tools/list` scoping** — an operator only ever sees its
  allowlisted tools, and `tools/call` re-checks the allowlist even for
  guessed tool names (defense in depth).
- **Read-only mode** that denies write-capable tools regardless of allowlist.
- **Audit attribution** — every operator tool execution is logged with
  `operator_id` / `operator_label` via `WP_MCP_AI_Logger`.
- **Hermes config generation** — `.env` + `config.yaml` fragments with
  `trust: untrusted`, ready to paste.
- Admin page (**Settings → External Operators**) and WP-CLI
  (`wp mcp-ai operator <create|list|revoke|config>`).

## Public surface

| Component | File |
|---|---|
| Bootstrap | `fleet-operator.php` (`WP_MCP_AI_Fleet_Operator_Plugin`) |
| Credential storage + verification | `includes/class-wp-mcp-ai-operator-credential-repository.php` |
| Tool allowlist resolution | `includes/class-wp-mcp-ai-operator-tool-scope.php` |
| Hermes/YAML config builder | `includes/class-wp-mcp-ai-operator-config-generator.php` |
| REST/tool-pipeline integration | `includes/class-wp-mcp-ai-operator-authenticator.php` |
| Admin UI | `includes/class-wp-mcp-ai-operator-admin.php` |
| WP-CLI | `includes/class-wp-mcp-ai-operator-cli.php` |

Hooks consumed (all emitted by the base plugin):

- `wp_mcp_ai_pre_validate_bearer_token`
- `wp_mcp_ai_map_bearer_to_user_id`
- `wp_mcp_ai_mcp_tools_list`
- `wp_mcp_ai_pre_execute_tool`
- `wp_mcp_ai_after_tool_execution`

Filters emitted:

- `wp_mcp_ai_operator_audience_url` — override the audience URL used for
  credential verification (reverse proxies, staging).

## Neighbors

- `addons/media-worker` — per-site media-ops sidecar. **Do not merge fleet
  concerns here**; this addon is intentionally separate.
- `addons/tenant-router` — Cloudflare edge routing for the SaaS product.
- `includes/a2a/` — A2A protocol stack; the Phase-4 companion for cross-site
  delegation (see proposal).

## Context files to load alongside

- [`addons/fleet-operator/.context/`](.context/README.md) — addon-level agent context tree (18 files): conventions + security checklist (always), `hermes-ops.md`, `wp-plugin-dev.md`, `mcp-integration.md`, `design-content.md`, 6 operator roles under `roles/`, and `active/` / `archive/` / `templates/` for task scratch + memory. Registered in [`AGENTS.md`](../../AGENTS.md) §2.
- `.context/` root subsystem notes (conventions, security checklist, REST API).
- `docs/project/proposals/024-hermes-agent-fleet-operator-implementation-plan.md` — full plan.
- `docs/operations/fleet/hermes-operator-setup.md` — operator runbook.

## Skills pack

`skills/` contains the Hermes skills pack published as a GitHub "tap"
(`hermes skills tap add nvdigitalsolutions/nvoos-hermes-skills`). See
`skills/README.md`.

## Testing

PHPUnit tests live in `tests/fleet-operator/` (root test suite):

```bash
vendor/bin/phpunit tests/fleet-operator/
```
