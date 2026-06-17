# Slash Commands Reference

> **Status:** Current as of NV oOS unreleased (May 3–4 2026 sprint) — base plugin ships 24 commands total (13 pre-existing + 11 added May 3–4 sprint); Pro addon adds 8 new commands.  
> **Last reviewed:** May 2026 · Version: 1.x

Slash commands let users trigger powerful, structured operations directly from the chat input box by typing `/command [args] [--flags]`. The system is extensible: third-party plugins can register their own commands via the `wp_mcp_ai_default_slash_commands_loaded` action hook.

---

## Table of Contents

1. [Architecture](#architecture)
2. [Base commands (24)](#base-commands)
3. [Pro commands (8)](#pro-commands)
4. [Registering custom commands](#registering-custom-commands)
5. [Related docs](#related-docs)

---

## Architecture

- **Parser** (`includes/slash-commands/class-wp-mcp-ai-slash-command-parser.php`) — extracts command name, positional arguments, long flags (`--flag=value`), and short flags (`-f value`) from the raw input string.
- **Handler** (`includes/slash-commands/class-wp-mcp-ai-slash-command-toolkit-manager.php`) — routes parsed commands to registered handlers, enforces capability checks, rate-limits, and logs all executions.
- **Registration hook** — `wp_mcp_ai_default_slash_commands_loaded` fires after all default commands are registered; pass your `WP_MCP_AI_Slash_Command_Handler` instance to this hook to add commands.
- **Pro hook** — `wp_mcp_ai_slash_commands_initialized` fires when Pro commands are ready; Pro commands are loaded from `addons/pro/includes/slash-commands/slash-commands-init.php`.

---

## Base commands

### Utility / Help

| Command | Aliases | Required capability | Description |
|---------|---------|-------------------|-------------|
| `/help [command]` | `h`, `?` | `read` | Display help for all commands or a specific one. `--detailed` shows full parameter list; `--new` lists commands added since v2.0. |
| `/context` | `ctx` | `read` | Show context budget: token usage, message count, remaining capacity. `--detailed` shows per-provider breakdown. |
| `/compact` | — | `read` | Proactive context compaction. Strategies: `summarize` (default), `trim-tools`, `keep-recent`, `full`. `--keep=<n>` sets messages to preserve (default 6). |
| `/clear` | — | `read` | Clear the chat window (front-end signal only — no server state changed). |
| `/reset` | — | `read` | Reset the current session context. |
| `/resume` | — | `read` | Resume the most recent saved session transcript. |

### System diagnostics

| Command | Aliases | Required capability | Description |
|---------|---------|-------------------|-------------|
| `/status` | — | `edit_posts` | Aggregated system health: async health, job counts, tool registry status. `--json` for machine-readable output. |
| `/cost` | — | `edit_posts` | Token usage and cost summary. `--days=<n>` (default 7, max 365), `--user-id=<n>` (requires `manage_options`), `--json`. |
| `/diagnose` | `debug` | `manage_options` | Diagnostic bundle: version, PHP, errors, async health, tool count. `--json` for support submissions. |

### Content & publishing

| Command | Aliases | Required capability | Description |
|---------|---------|-------------------|-------------|
| `/next-task` | `next` | `edit_posts` | Autonomous task discovery and execution for WordPress content. Flags: `--filter=<all\|drafts\|seo\|updates>`, `--type=<task>`, `--limit=<n>`, `--dry-run`, `--auto`. |
| `/ship [post_id…]` | — | `publish_posts` | Automated content review, optimization, and publishing workflow. Flags: `--dry-run`, `--publish`, `--schedule=<YYYY-MM-DD HH:MM>`, `--skip-checks`, `--skip-seo`, `--skip-images`, `--skip-links`. |
| `/clean-content` | `clean` | `edit_posts` | Content QA with 3-phase detection (HIGH/MEDIUM/LOW certainty). Flags: `--phase=<1-3>`, `--limit=<n>`, `--dry-run`, `--auto-fix`, `--post-type=<type>`, `--verbose`. |
| `/sync-docs` | `docs` | `edit_posts` | Documentation drift detection and synchronization. Flags: `--type=<all\|posts\|pages\|readme>`, `--dry-run`, `--auto-fix`, `--skip-links`, `--skip-code`. |

### Tools & skills

| Command | Aliases | Required capability | Description |
|---------|---------|-------------------|-------------|
| `/tools [<search>]` | — | `edit_posts` | Browse, filter, and inspect registered tools. `--capability-flag=<flag>`, `--page=<n>` (20 per page), `--show=<slug>`, `--json`. |
| `/skills` | — | `edit_posts` | List, inspect, and install agent skill packs. `--install=<slug>` (requires `manage_options`), `--show=<slug>`, `--json`. |
| `/preset` | — | `edit_posts` | List, inspect, and apply orchestration presets. `--show=<id>`, `--apply=<id>` (requires `manage_options`), `--active`, `--json`. |
| `/model` | — | `edit_posts` | List available models; view or set the model for an assistant. `--set=<slug>` (requires `manage_options`), `--assistant-id=<n>`, `--discover`, `--current`, `--json`. |

### Workflow & automation

| Command | Aliases | Required capability | Description |
|---------|---------|-------------------|-------------|
| `/workflow [name]` | — | `edit_posts` | Execute and manage custom automation workflows. `--action=<run\|list\|show>`, `--dry-run`, `--list`, `--show`. |
| `/jobs` | — | `edit_posts` | List and manage async background jobs. `--all` (requires `manage_options`), `--cancel=<job_id>`, `--status=<queued\|running\|completed\|failed\|paused>`, `--limit=<n>`, `--json`. |
| `/optimize-perf` | `perf` | `manage_options` | Automated performance analysis and optimization. `--phases=<1-10>`, `--url=<url>`, `--dry-run`, `--auto-apply`, `--detailed`. |

### Memory

| Command | Aliases | Required capability | Description |
|---------|---------|-------------------|-------------|
| `/remember <text>` | `memorize` | `edit_posts` | Store verbatim long-term memory for the current assistant. `--tag=<tag>`, `--importance=<low\|medium\|high\|critical>`, `--wing=<name>`, `--room=<name>`, `--summarize`. |
| `/forget <context_id>` | — | `edit_posts` | Delete a stored memory by its `context_id`. |
| `/scope` | — | `edit_posts` | Set the active wing/room scope for subsequent memory operations in this conversation. `--wing=<name>` (omit to clear scope), `--room=<name>`. |

### Markup & telemetry

| Command | Aliases | Required capability | Description |
|---------|---------|-------------------|-------------|
| `/markup-stats` | `mstats` | `manage_options` | Show aggregate markup telemetry counters (completion/cancellation rates). `--verbose`, `--json`, `--reset`. |

---

## Pro commands

Pro commands are registered from `addons/pro/includes/slash-commands/slash-commands-init.php` via the `wp_mcp_ai_slash_commands_initialized` action.

| Command | Aliases | Required capability | Description |
|---------|---------|-------------------|-------------|
| `/schedule [action] [id]` | `sched` | `edit_posts` | Manage Pro schedules: list, show, create, pause, resume, delete, run, history. `--name=<n>`, `--type=<task\|workflow\|assistant_run\|channel_broadcast\|workflow_builder>`, `--cron=<interval>`, `--all` (requires `manage_options`), `--limit=<n>`, `--notify`, `--json`. |
| `/schedule-preset` | `sched-preset` | `edit_posts` | Browse and install Pro schedule presets. `--toolkit=<cat>`, `--show=<id>`, `--install=<id>` (requires `manage_options`), `--categories`, `--json`. |
| `/workflow-preset` | — | `edit_posts` | Browse and install Pro workflow presets. `--toolkit=<cat>`, `--show=<id>`, `--install=<id>` (requires `manage_options`), `--categories`, `--json`. |
| `/run [workflow_name]` | — | `edit_posts` | Trigger a Pro autonomous run or named workflow. `--dry-run`, `--assistant-id=<n>`, `--json`. |
| `/agent [peer_id]` | — | `edit_posts` | Agent-to-Agent (A2A) dispatch: call a peer assistant with a message. `--message=<text>`, `--context`, `--json`. |
| `/mcp-app [action]` | — | `edit_posts` | Manage per-assistant MCP App connections: list, show, test, enable, disable. `--label=<l>`, `--assistant-id=<n>`, `--json`. |
| `/persona [slug]` | — | `edit_posts` | Switch the active assistant persona for the current session. `--list`, `--reset`, `--json`. |
| `/broadcast <message>` | — | `manage_options` | Broadcast a message across all configured channels. `--channel=<slug>`, `--test`, `--json`. |

---

## Registering custom commands

```php
add_action( 'wp_mcp_ai_default_slash_commands_loaded', function ( $handler ) {
    $handler->register(
        'my-command',
        array(
            'handler'     => array( new My_Command_Class(), 'execute' ),
            'description' => __( 'What my command does.', 'my-plugin' ),
            'usage'       => '/my-command [--flag]',
            'capability'  => 'edit_posts',
            'aliases'     => array( 'mc' ),
            'parameters'  => array(
                '--flag' => array(
                    'description' => __( 'An optional flag.', 'my-plugin' ),
                    'required'    => false,
                ),
            ),
        )
    );
} );
```

The `execute( $args, $flags, $context )` method must return a `string`, `array` (rendered as a Markdown table), or `WP_Error`.

---

## Related docs

- [`docs/SLASH_COMMANDS_GUIDE.md`](../../SLASH_COMMANDS_GUIDE.md) — original Phase 1 guide
- [`docs/PRO_TOOLKIT_SLASH_COMMANDS.md`](../../PRO_TOOLKIT_SLASH_COMMANDS.md) — Pro toolkit commands (Phase 2)
- [`docs/hooks-reference.md`](../../hooks-reference.md) — `wp_mcp_ai_default_slash_commands_loaded` hook
