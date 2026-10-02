# WP-CLI Command Reference (Operators)

Complete operator reference for the `wp mcp-ai …` command tree (Base + Pro + addons), rebuilt for Proposal 050 (WP-CLI parity & hardening).

> **Conventions:** every mutating subcommand requires the `manage_options`
> capability and honors `--yes` to skip confirmations. List verbs support
> `--format` (table|json|yaml|csv|ids|count) and `--fields=<csv>` where noted.
> Legacy aliases (`mcp calendar`, top-level `ezuite`/`flowhub`/`shopify-sync`/
> `profession`) remain available; new scripts should use the canonical
> `mcp-ai …` names.

## Base commands

| Command | Subcommands | Notes |
|---|---|---|
| `wp mcp-ai` | `status`, `cleanup-cct`, `remote` | `cleanup-cct` gated |
| `wp mcp-ai assistant` | `list`, `get`, `create`, `update`, `delete`, `import`, `export`, `tools` | `create/update` accept `--provider`, `--model`, `--system-prompt`, `--tools`; `tools` = `list/add/remove <id>`; writes canonical `_wp_mcp_ai_*` meta |
| `wp mcp-ai approval` | `list`, `approve`, `reject` | |
| `wp mcp-ai bulk` | `audit`, `cleanup-artifacts`, `dispatch`, `retry-failed`, `status` | `--dry-run`, `--batch-size` |
| `wp mcp-ai cache clear` | — | gated |
| `wp mcp-ai chat` | one-shot message | `--assistant-id`, `--model`, `--provider`, `--stream` |
| `wp mcp-ai content` | `auto-categorize` | |
| `wp mcp-ai conversation-import` | `detect`, `import`, `status`, `delete` | JetEngine-gated |
| `wp mcp-ai credential` | `list`, `issue`, `revoke` | gated; `--porcelain` prints token once |
| `wp mcp-ai cron` | `list`, `run`, `delete`, `clear` | gated |
| `wp mcp-ai dlq` | `list`, `stats`, `retry`, `delete`, `dismiss`, `purge`, `clear` | |
| `wp mcp-ai harness` | `search`, `population`, `trace` | |
| `wp mcp-ai health` | — | unified diagnostics |
| `wp mcp-ai log` | `errors`, `activity`, `clear`, `prune` | |
| `wp mcp-ai measurement` | `run`, `alert_check`, `list_runs` | CI-grade |
| `wp mcp-ai memory` | `recall`, `store`, `forget`, `stats`, `audit` | |
| `wp mcp-ai model` | `list`, `suggestions`, `discover` | `discover` gated, `--dry-run` |
| `wp mcp-ai oos parity` | `report`, `diff` | |
| `wp mcp-ai plugins` | `list`, `activate`, `deactivate` | mutations gated |
| `wp mcp-ai provider` | `list`, `test`, `models` | |
| `wp mcp-ai queue` | `stats`, `process`, `clear`, `retry`, `show` | mutations gated |
| `wp mcp-ai rabbitmq` | `status`, `test-connection`, `setup`, `list-queues`, `send-test-message`, `worker` | mutations gated |
| `wp mcp-ai restrictions` | `list`, `lift`, `add` | gated |
| `wp mcp-ai security` | `posture`, `audit`, `purge-audit`, `gate`, `keys` | `purge-audit` gated |
| `wp mcp-ai settings` | `get`, `set`, `reset` | gated |
| `wp mcp-ai sla` | `status`, `tune`, `analyze`, `enable`, `disable` | |
| `wp mcp-ai slash` | `execute`, `list`, `help` | |
| `wp mcp-ai stdio` | MCP stdio server | gated |
| `wp mcp-ai thread` | `list`, `get`, `delete`, `compact` | |
| `wp mcp-ai token` | `migrate-providers` | gated |
| `wp mcp-ai tool` | `list`, `enable`, `disable`, `call` | `call <slug> --args='{"…":…}' [--assistant-id] [--format=json]` — per-tool capability |
| `wp mcp-ai transcript` | `mine`/`list`, `status`, `cancel` | |
| `wp mcp-ai version` | — | |

## Pro commands

| Command | Subcommands | Notes |
|---|---|---|
| `wp mcp-ai pro status` | — | Pro/core versions, active toolkits |
| `wp mcp-ai toolkit` | `list`, `enable`, `disable` | 24 toolkits |
| `wp mcp-ai connection` | `list`, `create`, `get`, `delete`, `test` | `--remote-url` on create |
| `wp mcp-ai project` / `wp mcp-ai task` | `list`, `create`, `get`, `update`, `delete` (+ `complete`, `dependencies` for task) | PM toolkit |
| `wp mcp-ai mcp-server` | `list`, `get`, `enable`, `disable`, `tools`, `token-generate`, `token-list`, `token-revoke` | |
| `wp mcp-ai calendar` / `wp mcp-ai place` | (legacy alias: `mcp calendar` / `mcp place`) | |
| `wp mcp-ai composition` | OOS engine composition | |
| `wp mcp-ai crm` | `lead`, `deal`, `company`, `customer`, `activity`, `ticket` × `list`/`get` | CRM toolkit |
| `wp mcp-ai incident` | `list`, `get`, `resolve` | `resolve` gated |
| `wp mcp-ai maintenance` | `list`, `get`, `cancel` | `cancel` gated |
| `wp mcp-ai schedule` | `list`, `get`, `run` | `run` gated, `--dry-run` |
| `wp mcp-ai workflow` | `list`, `get`, `validate` | |
| `wp mcp-ai vault` | `list`, `get` | metadata only — never renders secret material |
| `wp mcp-ai remote-site` | `list`, `get`, `test`, `peer-list` | read-only |
| `wp mcp-ai communication` | `contacts`, `messages` | chat-channels toolkit |
| `wp mcp-ai media-studio` | `status`, `list-jobs` | |
| `wp mcp-ai ezuite` / `wp mcp-ai flowhub` / `wp mcp-ai shopify-sync` | `status`, `trigger`, `clear-cache`, `test-connection`, `sync-log`, … | legacy top-level names aliased |
| `wp mcp-ai profession` | `seed-orchestration`, `orchestration-stats` | legacy `profession` aliased |

## Addon commands

| Command | Subcommands |
|---|---|
| `wp nvoos-docs` | `rebuild`, `status`, `reset`, `fix-links` |
| `wp mcp-ai operator` | `create`, `list`, `revoke`, `config` (gated `manage_options`) |

## Scripting patterns

```bash
# Capture an assistant ID, assign tools, issue a credential.
AID=$(wp mcp-ai assistant create --title="Ops Bot" --status=publish --porcelain)
wp mcp-ai assistant tools add "$AID" --tools=web_search,get_post
wp mcp-ai credential issue "$AID" --porcelain

# Run one tool with JSON args (per-tool capability enforced).
wp mcp-ai tool call web_search --args='{"query":"wp-cli"}' --format=json

# Security posture + audit tail for monitoring.
wp mcp-ai security posture --format=json
wp mcp-ai security audit --per-page=10 --format=json

# Dry-run a schedule without executing.
wp mcp-ai schedule run <id> --dry-run
```

## Capability matrix

- Read-only verbs: no capability gate (matching the admin read surfaces).
- Mutating verbs: `manage_options` (or the tool's own `required_capability`
  for `tool call`), with `--yes` to skip prompts in CI.
- `wp mcp-ai vault` gates reads on `manage_options` as well, mirroring the
  vault REST controller.

## See also

- [`docs/project/proposals/050-wp-cli-gap-analysis.md`](../project/proposals/050-wp-cli-gap-analysis.md)
- [`docs/project/proposals/050-wp-cli-hardening-proposal.md`](../project/proposals/050-wp-cli-hardening-proposal.md)
- [`includes/cli/README.md`](../../includes/cli/README.md) · [`addons/pro/includes/cli/README.md`](../../addons/pro/includes/cli/README.md)
