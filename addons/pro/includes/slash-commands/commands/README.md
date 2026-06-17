# Pro Slash Command Implementations

## Purpose

Nine Pro slash command classes — `/schedule`, `/schedule-preset`, `/workflow-preset`, `/run`, `/agent`, `/mcp-app`, `/mcp-server`, `/persona`, `/broadcast` — that plug into Base's `WP_MCP_AI_Slash_Command_Handler` to make Pro toolkits, schedules, A2A agents, MCP connections, personas, and channel broadcasts addressable from any chat surface.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | [`../slash-commands-init.php`](../slash-commands-init.php) — registers all nine commands on the `wp_mcp_ai_slash_commands_initialized` action emitted by Base |
| **Optional dependencies** | none required at registration time — each command degrades gracefully when its backing subsystem (schedule manager, workflow builder, A2A, MCP apps, channel webhooks) is unavailable |

## Public Surface

Each class exposes a single `execute( $args, $flags, $context )` method that returns a structured payload (`string|array|WP_Error`). External callers invoke commands through the chat REST surface or `wp_mcp_ai_execute_slash_command()`, never by class.

| Command class | Slash command(s) |
|---|---|
| `WP_MCP_AI_Pro_Slash_Command_Agent` | `/agent` (alias `/a2a`) — A2A task delegation |
| `WP_MCP_AI_Pro_Slash_Command_Broadcast` | `/broadcast` — one-shot channel broadcast |
| `WP_MCP_AI_Pro_Slash_Command_Mcp_App` | `/mcp-app` (alias `/mcp-apps`) — manage MCP App connections |
| `WP_MCP_AI_Pro_Slash_Command_Mcp_Server` | `/mcp-server` (aliases `/mcp-servers`, `/toolkit-mcp`) — manage toolkit MCP servers |
| `WP_MCP_AI_Pro_Slash_Command_Persona` | `/persona` (aliases `/profile`, `/assistant`) — switch profession/persona |
| `WP_MCP_AI_Pro_Slash_Command_Run` | `/run` (alias `/run-workflow`) — execute a saved DAG |
| `WP_MCP_AI_Pro_Slash_Command_Schedule` | `/schedule` (alias `/sched`) — CRUD + trigger schedules |
| `WP_MCP_AI_Pro_Slash_Command_Schedule_Preset` | `/schedule-preset` (alias `/sched-preset`) — browse/install schedule presets |
| `WP_MCP_AI_Pro_Slash_Command_Workflow_Preset` | `/workflow-preset` (alias `/wf-preset`) — browse/install workflow presets |

## Inputs / Outputs / Neighbors

- **Reads from:** chat REST input (the message body); assistant CPT meta (for `/persona`, `/mcp-app`); Pro schedule + workflow preset libraries; the toolkit MCP server registry; the A2A task store; channel connection settings.
- **Writes to:** the Base slash-command audit table (via the handler); Pro CPTs/options when a command mutates state; outbound channel clients for `/broadcast`.
- **Upstream callers:** Base [`WP_MCP_AI_Slash_Command_Handler`](../../../../../includes/slash-commands/class-wp-mcp-ai-slash-command-handler.php) (invoked from chat REST, slash-command REST, and chat JS).
- **Downstream collaborators:** [`../../class-wp-mcp-ai-pro-schedule-manager.php`](../../class-wp-mcp-ai-pro-schedule-manager.php), [`../../class-wp-mcp-ai-pro-schedule-presets.php`](../../class-wp-mcp-ai-pro-schedule-presets.php), [`../../class-wp-mcp-ai-pro-workflow-presets.php`](../../class-wp-mcp-ai-pro-workflow-presets.php), [`../../mcp-servers/`](../../mcp-servers/), [`../../mcp-apps/`](../../mcp-apps/), [`../../class-wp-mcp-ai-pro-remote-site-manager.php`](../../class-wp-mcp-ai-pro-remote-site-manager.php), tool registry.
- **Events fired:** the standard `wp_mcp_ai_slash_command_executed` audit hook via the handler.
- **Events listened to:** `wp_mcp_ai_slash_commands_initialized` (registration).

## Conventions

- All commands register through the `wp_mcp_ai_slash_commands_initialized` action — **never** require these files directly from a toolkit init or admin page.
- Each command MUST block guest requests. Check `$context['guest_request']` at the top of `execute()` and return `WP_Error` with code `guest_forbidden`.
- Every command MUST support the `--json` flag for machine-readable output. Operators rely on it from CLI / REST harnesses.
- Commands return structured payloads (`string` for markdown, `array` for JSON with `success` + `data` keys, `WP_Error` for failures). Never echo HTML.
- Pro commands MUST be safe to register when their backing toolkit is unavailable — the command body returns a friendly `WP_Error` rather than fataling.

## Tests

```bash
vendor/bin/phpunit addons/pro/tests/test-pro-slash-command-schedule.php
vendor/bin/phpunit addons/pro/tests/test-pro-slash-command-schedule-preset.php
vendor/bin/phpunit addons/pro/tests/test-pro-slash-command-workflow-preset.php
vendor/bin/phpunit addons/pro/tests/test-pro-slash-command-run.php
vendor/bin/phpunit addons/pro/tests/test-pro-slash-command-agent.php
vendor/bin/phpunit addons/pro/tests/test-pro-slash-command-mcp-app.php
vendor/bin/phpunit addons/pro/tests/test-pro-slash-command-mcp-server.php
vendor/bin/phpunit addons/pro/tests/test-pro-slash-command-persona.php
vendor/bin/phpunit addons/pro/tests/test-pro-slash-command-broadcast.php
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — capability + nonce rules (always)
- [`.context/chat-ui.md`](../../../../../.context/chat-ui.md) — front-end parsing + rendering of `/cmd` results
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md) — most Pro commands wrap one or more tools
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro/Base placement rules
- [`CLAUDE.md`](../../../../../CLAUDE.md) — canonical envelope rules

## See Also

- Parent folder: [`addons/pro/includes/slash-commands/`](../) — the init file that registers all nine commands
- Base counterpart: [`includes/slash-commands/`](../../../../../includes/slash-commands/) — handler, parser, validator, and Base command set
- Collaborators: [`../../rest/`](../../rest/), [`../../cli/`](../../cli/), [`../../tools/`](../../tools/)
