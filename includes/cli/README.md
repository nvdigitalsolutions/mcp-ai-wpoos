# CLI

## Purpose

Hosts the WP-CLI command handlers that surface NV oOS assistants, tools, credentials, settings, logs, slash-commands, and operational queues (DLQ / SLA / bulk content) to the `wp mcp-ai …` command tree.

## Tier

| | |
|---|---|
| **Distribution** | Base |
| **PHP target** | 7.4+ |
| **Loaded by** | Each command file self-registers via `WP_CLI::add_command( 'mcp-ai …', WP_MCP_AI_CLI_*_Command::class )`, gated on `defined( 'WP_CLI' ) && WP_CLI`. The top-level dispatcher [`includes/class-wp-mcp-ai-cli-command.php`](../class-wp-mcp-ai-cli-command.php) registers the root `mcp-ai` and umbrella subcommands |
| **Optional dependencies** | none (subcommands gracefully no-op when their backing service — e.g. DLQ, SLA — is unavailable) |

## Public Surface

The folder's external contract is the **`wp mcp-ai <subcommand>`** invocation, not the PHP class. Operators should script against the CLI command, not the class directly.

| Symbol | File | Description | Used by |
|---|---|---|---|
| `WP_MCP_AI_CLI_Base_Command` (abstract) | `class-wp-mcp-ai-cli-base-command.php` | Abstract base for all concrete CLI commands | All concrete commands in this folder |
| `WP_MCP_AI_CLI_Assistant_Command` → `wp mcp-ai assistant` | `class-wp-mcp-ai-cli-assistant-command.php` | Manage assistants (`update`, `import`) | WP-CLI runtime |
| `WP_MCP_AI_CLI_Bulk_Command` → `wp mcp-ai bulk` | `class-wp-mcp-ai-cli-bulk-command.php` | Bulk content operations (`retry-failed`) | WP-CLI runtime |
| `WP_MCP_AI_CLI_Cache_Command` → `wp mcp-ai cache` | `../class-wp-mcp-ai-cli-command.php` | Clear plugin caches | WP-CLI runtime |
| `WP_MCP_AI_CLI_Content_Command` → `wp mcp-ai content` | `class-wp-mcp-ai-cli-content-command.php` | Content management | WP-CLI runtime |
| `WP_MCP_AI_CLI_Credential_Command` → `wp mcp-ai credential` | `class-wp-mcp-ai-cli-credential-command.php` | Credential management (`list` supports optional assistant-id) | WP-CLI runtime |
| `WP_MCP_AI_CLI_DLQ` → `wp mcp-ai dlq` | `class-wp-mcp-ai-cli-dlq.php` | Dead-letter queue management; extends `Base_Command`, `--dry-run` for `purge`/`clear` | WP-CLI runtime |
| `WP_MCP_AI_CLI_Health_Command` → `wp mcp-ai health` | `../class-wp-mcp-ai-cli-command.php` | Unified diagnostic health check | WP-CLI runtime |
| `WP_MCP_AI_CLI_Log_Command` → `wp mcp-ai log` | `class-wp-mcp-ai-cli-log-command.php` | Log viewing | WP-CLI runtime |
| `WP_MCP_AI_CLI_Measurement_Command` → `wp mcp-ai measurement` | `class-wp-mcp-ai-cli-measurement-command.php` | Performance measurements | WP-CLI runtime |
| `WP_MCP_AI_CLI_Settings_Command` → `wp mcp-ai settings` | `class-wp-mcp-ai-cli-settings-command.php` | Plugin settings management | WP-CLI runtime |
| `WP_MCP_AI_CLI_SLA` → `wp mcp-ai sla` | `class-wp-mcp-ai-cli-sla.php` | Service-level agreement tracking; extends `Base_Command` | WP-CLI runtime |
| `WP_MCP_AI_CLI_Slash_Command` → `wp mcp-ai slash` | `class-wp-mcp-ai-cli-slash-command.php` | Slash-command management | WP-CLI runtime |
| `WP_MCP_AI_CLI_Tool_Command` → `wp mcp-ai tool` | `class-wp-mcp-ai-cli-tool-command.php` | Tool registry management | WP-CLI runtime |
| `WP_MCP_AI_CLI_Version_Command` → `wp mcp-ai version` | `../class-wp-mcp-ai-cli-command.php` | Plugin version information | WP-CLI runtime |

The umbrella verbs (`mcp-ai`, `mcp-ai plugins`, `mcp-ai queue`, `mcp-ai token`, `mcp-ai rabbitmq`, `mcp-ai stdio`) live in the top-level `includes/class-wp-mcp-ai-cli-command.php` for historical reasons.

## Inputs / Outputs / Neighbors

- **Reads from:** WP-CLI `$args` / `$assoc_args`; the tool registry; assistant CPT meta; credential post meta; the logger ring-buffers (`wp_mcp_ai_recent_errors`, `wp_mcp_ai_recent_activity`); DLQ + SLA tables
- **Writes to:** stdout (formatted by `WP_CLI::log`, `WP_CLI\Utils\format_items`); WordPress options/post meta on mutating subcommands; the same DB surfaces as the REST API
- **Upstream callers:** the `wp` binary (humans, CI, cron, ops scripts); the helper [`bin/codex-startup.sh`](../../bin/codex-startup.sh) and other dev scripts
- **Downstream collaborators:** [`includes/tools/`](../tools/) via `WP_MCP_AI_Tool_Registry`, [`includes/services/`](../services/), [`includes/repositories/`](../repositories/), [`includes/slash-commands/`](../slash-commands/), the credential manager, the DLQ + SLA managers
- **Events fired:** none beyond what the underlying tool / service already fires
- **Events listened to:** none — registration is unconditional once `WP_CLI` is defined

## Conventions

Folder-specific deltas:

- Every concrete command extends `WP_MCP_AI_CLI_Base_Command` (which itself extends `WP_CLI_Command`) so progress bars, batch counters, and error/success summaries stay consistent — **including `DLQ` and `SLA`**, which were migrated to extend `Base_Command`.
- Each file MUST be a no-op outside WP-CLI — guard with `if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) { return; }` at the top.
- Operator-facing output goes through `WP_CLI::log` / `WP_CLI::success` / `WP_CLI::warning` / `WP_CLI::error`; never `echo` or `print_r`.
- Mutating subcommands MUST honour the same capability checks as their REST/tool counterparts — the CLI is not an authentication bypass.
- Long-running subcommands SHOULD accept `--dry-run` and `--batch-size=` flags where applicable; see `WP_MCP_AI_CLI_Bulk_Command` for the canonical shape.

## Tests

```bash
vendor/bin/phpunit tests/test-wp-cli-tool.php
vendor/bin/phpunit tests/test-wp-cli-new-commands.php
```

CLI tests bootstrap WP-CLI in headless mode via `tests/bootstrap.php` and assert against captured stdout. Coverage is intentionally focused on argument parsing, capability gating, and output formatting; the underlying tool/service code is covered by its own suite.

## Also Load

- [`.context/conventions.md`](../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../.context/security-checklist.md) — capability + sanitisation rules (always)
- [`.context/tool-registry.md`](../../.context/tool-registry.md) — for `wp mcp-ai tool …`
- [`.context/testing.md`](../../.context/testing.md) — running CLI tests
- [`docs/guides/operator/wp-cli.md`](../../docs/guides/operator/wp-cli.md) — operator-facing command reference (if present)

## See Also

- Sibling surfaces: [`includes/rest/`](../rest/), [`includes/tools/`](../tools/) — the two other entry-point surfaces sharing the same downstream services
- Collaborators: [`includes/slash-commands/`](../slash-commands/) — many CLI commands have a chat-side slash-command counterpart
- Top-level dispatcher (legacy location): [`includes/class-wp-mcp-ai-cli-command.php`](../class-wp-mcp-ai-cli-command.php)
