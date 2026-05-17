# Pro Slash Commands

## Purpose

Registers the Pro chat slash commands — `/schedule`, `/schedule-preset`, `/workflow-preset`, `/run`, `/agent`, `/mcp-app`, `/mcp-server`, `/persona`, `/broadcast` — with Base's `WP_MCP_AI_Slash_Command_Handler` so Pro toolkits, schedules, workflow presets, A2A agents, MCP app connections, personas, and channel broadcasts are addressable from any chat surface.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | [`addons/pro/includes/slash-commands/slash-commands-init.php`](./slash-commands-init.php) hooks `wp_mcp_ai_pro_load_slash_commands()` to the `wp_mcp_ai_slash_commands_initialized` action emitted by Base's [`includes/slash-commands/slash-commands-init.php`](../../../../includes/slash-commands/slash-commands-init.php). Base owns the handler; Pro registers nine additional commands |
| **Optional dependencies** | none required at registration time — individual commands degrade gracefully when their backing subsystem (schedule manager, workflow builder, A2A server, MCP app connections, channel webhooks) is disabled |

## Public Surface

Pro contributes **command names** to the handler that already exists in Base. External callers should invoke commands through the chat REST surface or `wp_mcp_ai_execute_slash_command()`, never by class.

| Symbol | File | Slash command(s) |
|---|---|---|
| `wp_mcp_ai_pro_load_slash_commands( $handler )` | `slash-commands-init.php` | Registration entry point — listens on `wp_mcp_ai_slash_commands_initialized` |
| `WP_MCP_AI_Pro_Slash_Command_Schedule` | `commands/class-wp-mcp-ai-pro-slash-command-schedule.php` | `/schedule` (alias `/sched`) — list / show / create / pause / resume / delete / run / history |
| `WP_MCP_AI_Pro_Slash_Command_Schedule_Preset` | `commands/class-wp-mcp-ai-pro-slash-command-schedule-preset.php` | `/schedule-preset` (alias `/sched-preset`) — browse / install schedule presets |
| `WP_MCP_AI_Pro_Slash_Command_Workflow_Preset` | `commands/class-wp-mcp-ai-pro-slash-command-workflow-preset.php` | `/workflow-preset` (alias `/wf-preset`) — browse / install Workflow Builder presets |
| `WP_MCP_AI_Pro_Slash_Command_Run` | `commands/class-wp-mcp-ai-pro-slash-command-run.php` | `/run` (alias `/run-workflow`) — execute a saved DAG by id or name |
| `WP_MCP_AI_Pro_Slash_Command_Agent` | `commands/class-wp-mcp-ai-pro-slash-command-agent.php` | `/agent` (alias `/a2a`) — A2A delegation: list, status, cancel, send, discover |
| `WP_MCP_AI_Pro_Slash_Command_Mcp_App` | `commands/class-wp-mcp-ai-pro-slash-command-mcp-app.php` | `/mcp-app` (alias `/mcp-apps`) — manage assistant MCP App connections |
| `WP_MCP_AI_Pro_Slash_Command_Mcp_Server` | `commands/class-wp-mcp-ai-pro-slash-command-mcp-server.php` | `/mcp-server` (aliases `/mcp-servers`, `/toolkit-mcp`) — list / show / enable / disable / tools |
| `WP_MCP_AI_Pro_Slash_Command_Persona` | `commands/class-wp-mcp-ai-pro-slash-command-persona.php` | `/persona` (aliases `/profile`, `/assistant`) — switch profession / persona |
| `WP_MCP_AI_Pro_Slash_Command_Broadcast` | `commands/class-wp-mcp-ai-pro-slash-command-broadcast.php` | `/broadcast` — one-shot message to a chat channel (Telegram, Slack, Discord, Teams, Messenger, WhatsApp) |

## Inputs / Outputs / Neighbors

- **Reads from:** chat REST input (the message body); assistant CPT meta (for `/persona`, `/mcp-app`); Pro schedule + workflow preset libraries; the toolkit MCP-server registry; the A2A task store; chat-channel connection settings on the remote-site manager
- **Writes to:** the Base slash-command audit table (via the handler); Pro CPTs / options when a command mutates state (`/schedule create|pause|resume|delete`, `/mcp-app`, `/persona`, `/workflow-preset --install`); the outbound channel client for `/broadcast`
- **Upstream callers:** Base [`WP_MCP_AI_Slash_Command_Handler`](../../../../includes/slash-commands/class-wp-mcp-ai-slash-command-handler.php) (invoked from the chat REST controller, slash-command REST controller, and chat JS); never called directly
- **Downstream collaborators:** [`addons/pro/includes/class-wp-mcp-ai-pro-schedule-manager.php`](../class-wp-mcp-ai-pro-schedule-manager.php), [`addons/pro/includes/class-wp-mcp-ai-pro-schedule-presets.php`](../class-wp-mcp-ai-pro-schedule-presets.php), [`addons/pro/includes/class-wp-mcp-ai-pro-workflow-presets.php`](../class-wp-mcp-ai-pro-workflow-presets.php), [`addons/pro/includes/mcp-servers/`](../mcp-servers/), [`addons/pro/includes/mcp-apps/`](../mcp-apps/), [`addons/pro/includes/class-wp-mcp-ai-pro-remote-site-manager.php`](../class-wp-mcp-ai-pro-remote-site-manager.php), Base [`includes/tools/`](../../../../includes/tools/) + Pro [`tools/`](../tools/)
- **Events fired:** the standard `wp_mcp_ai_slash_command_executed` audit hook via the handler
- **Events listened to:** `wp_mcp_ai_slash_commands_initialized` (this folder's only entry point)

## Conventions

Folder-specific deltas (canonical handler rules in [`includes/slash-commands/README.md`](../../../../includes/slash-commands/README.md)):

- All Pro commands register through the `wp_mcp_ai_slash_commands_initialized` action — **never** require these files directly from a toolkit init or admin page.
- Every command class lives under `commands/`. The top-level `slash-commands-init.php` is the only registration file in this folder.
- Each registration MUST declare `description`, `usage`, `capability`, and parameter schema entries so `/help` autocomplete + the REST slash-command surface stay consistent.
- Pro commands MUST be safe to register even when their backing toolkit is disabled — the command body is responsible for returning a friendly "toolkit not enabled" structured payload rather than warning at registration time.
- Like Base, each command returns a structured payload (`{ "type": "…", "data": …, "render": "…" }`); never echo HTML. The chat UI renders.
- The `--json` flag SHOULD be supported on every Pro command (operators rely on it from CLI / REST harnesses).

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

End-to-end coverage of the slash-command pipeline (parser, validator, audit, REST controller) lives in the Base suite under [`tests/`](../../../../tests/).

## Also Load

- [`.context/conventions.md`](../../../../.context/conventions.md) — naming + style (always)
- [`.context/security-checklist.md`](../../../../.context/security-checklist.md) — capability + nonce + rate-limit rules (always)
- [`.context/chat-ui.md`](../../../../.context/chat-ui.md) — front-end parsing + rendering of `/cmd` results
- [`.context/tool-registry.md`](../../../../.context/tool-registry.md) — most Pro commands wrap one or more Pro tools
- [`.context/pro-vs-base.md`](../../../../.context/pro-vs-base.md) — Pro/Base placement rules
- [`CLAUDE.md`](../../../../CLAUDE.md) — canonical envelope rules (commands return the same shape)

## See Also

- Base counterpart: [`includes/slash-commands/`](../../../../includes/slash-commands/) — handler, parser, validator, audit, orchestrator, and the Base command set (`/help`, `/ship`, `/compact`, `/context`, `/cost`, `/diagnose`, `/jobs`, `/memory`, `/model`, `/preset`, `/session`, `/skills`, `/status`, `/tools`, `/workflow`, …)
- Sibling Pro surfaces: [`addons/pro/includes/rest/`](../rest/), [`addons/pro/includes/cli/`](../cli/), [`addons/pro/includes/tools/`](../tools/)
- Collaborators: [`addons/pro/includes/mcp-servers/`](../mcp-servers/), [`addons/pro/includes/mcp-apps/`](../mcp-apps/), [`addons/pro/includes/admin/`](../admin/) (Schedule Manager, Workflow Builder pages)
