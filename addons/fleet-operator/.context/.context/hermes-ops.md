# Hermes Operations

Load when working on Hermes configuration, hooks, kanban, cron, memory, or the WebUI.

## Layout

```
~/.hermes/
├── SOUL.md            # per-turn context (always loaded)
├── config.yaml        # gateway config — edit carefully, back up first
├── skills/            # runtime skills + .bundled_manifest index
├── hooks/             # session lifecycle hook scripts (register via CLI)
├── memories/          # persisted learnings (harvest after tasks)
├── kanban.db          # kanban board (SQLite)
├── cron/              # scheduled jobs (executions.db)
├── sessions/          # session transcripts
├── webui/             # WebUI state; last_workspace points at /home/ppuhgmkjff
└── state.db           # agent state
```

## Key config (`config.yaml`)

| Key | Value | Notes |
|-----|-------|-------|
| `model.default` | `~anthropic/claude-sonnet-latest` (openrouter) | Sessions may override (e.g. `deepseek/deepseek-v4-pro`) |
| `agent.max_turns` | 75 | Turn budget per run |
| `compression` | threshold 0.35, `protect_last_n` 20, `protect_first_n` 3 | Aligned to the GSD 30% rule |
| `delegation` | orchestrator on, 2 concurrent, depth 1 | Role prompts in `.context/roles/` |
| `memory` | enabled; user profile 1375 chars; entries 2200 chars | Harvest into `memories/` |
| `mcp_servers.nv-oos-sophie-agent` | `npx mcp-remote` → `victory.nvdigital.solutions` | Bearer token in config — see security checklist |
| `security.tirith` | enabled | Policy enforcement — do not disable |
| `terminal.backend` | local, `cwd: /home/ppuhgmkjff` | Commands run in the workspace |

## Hooks

Shell hooks are registered declaratively via the `hooks:` block in `config.yaml` and live in `~/.hermes/agent-hooks/`:

- `session-start-load-context.sh` (event `pre_llm_call`) — injects the base-context digest on the first turn of each new session.
- `session-end-archive-task.sh` (event `on_session_end`, observer) — logs a wrap-up reminder to `~/.hermes/logs/session-reminders.log`.

Consent is persisted in `~/.hermes/shell-hooks-allowlist.json`; changes take effect on gateway restart. Verify with `hermes hooks doctor`. Full mechanism: `~/.hermes/hooks/README.md`. `hooks_auto_accept: false` — approval still required for any newly added hook.

## Kanban ↔ phase gates

Kanban columns mirror the BMAD gates used for medium/major work:

| Gate | Exit criteria (all must be true) |
|------|----------------------------------|
| Planned | Base context loaded; task file created in `.context/active/` |
| Researched | Brief complete; feasibility assessed |
| Specified | PRD + acceptance criteria approved |
| Architected | Architecture reviewed; file map exists |
| Ready | Stories broken down and sequenced |
| In progress | Code committed atomically per story |
| Verified | Acceptance criteria pass; lint + tests + security review pass |
| Shipped | Version bumped; changelog updated; release published |
| Monitored | No new errors for 48h; learnings harvested to `memories/` |

## Memory

- `memory_enabled: true` — persist durable facts (gotchas, decisions, environment quirks), not chatter.
- Template: `.context/templates/memory-entry-template.md`.
- Memory is capped (2200 chars/entry, 1375 user profile) — be concise, no secrets.

## Cron

- Jobs run from `cron/` with `executions.db` history; check `cron/output/` after runs.
- Keep jobs idempotent; prefer read-only schedules.
