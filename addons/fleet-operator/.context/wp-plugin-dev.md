# WordPress Plugin Development (mcp-ai-wpoos)

Load when working on the NV oOS plugin code. The repo's own `CLAUDE.md` + `AGENTS.md` are canonical — this file is the local digest. **Do not restate repo docs elsewhere; link here.**

## Where the code lives

- Windows host: `F:\GITHUB\worktrees\mcp-ai-wpoos\<branch>\mcp-ai-wpoos` (many branch checkouts; `mina` is one of them).
- Canonical repo context at repo root: `AGENTS.md`, `CLAUDE.md`, `.context/`, `.bmad/`, `MAINTAINER_MAP.md`.

## Hard rules (digest)

- **PHP compat:** Base `includes/` = PHP 7.4+ (no enums, `readonly`, union types, named args, `match`). Pro `addons/pro/` = PHP 8.1+.
- **Naming:** classes `WP_MCP_AI_{Feature}_{Component}`; tools `WP_MCP_AI_Tool_{Name}`; functions `wp_mcp_ai_*`; hooks/options `wp_mcp_ai_*`; CPTs `mcp_ai_*`.
- **Canonical tool envelope:** success = `array('success' => true, 'message' => …, 'data' => …)`; failure = `WP_Error` — never `array('success' => false, …)`.
- **Two-gate sanitisation:** sanitize `$arguments[...]` at entry (Gate 1); escape every value at exit (Gate 2).
- **Security:** capability checks, nonces, ABSPATH guard, `$wpdb->prepare()` — always.
- **Third-party attribution:** `@link` / `@credit` PHPDoc tags + `CREDITS.md` updates.

## Skills to load (from `.agents/skills/`)

| Task | Skills |
|------|--------|
| Tool class | `wp-plugin-architecture`, `wp-plugin-hooks` |
| REST endpoint | `wp-rest-api` |
| Admin/settings | `wp-plugin-options-storage`, `wp-plugin-dto`, `wp-plugin-presenter` |
| Cron / background | `wp-plugin-cron`, `wp-action-scheduler` |
| Lifecycle | `wp-plugin-bootstrap`, `wp-plugin-lifecycle` |
| Security review | `wp-security-audit` (+ `-deep`, `-secrets`) |
| Text/i18n | `wp-i18n-audit`, `wp-utf8-text`, `wp-html-api` |

## Build & test

```bash
bash bin/run-tests-docker.sh                 # all tests (Docker — recommended on Windows)
composer run lint && composer run test       # local PHP + MySQL
composer run ci:all && npm run build         # full gate before release
```

## Workflow

- **Patch** → direct edit + verify.
- **Small feature** → plan + edit + QA role review (`.context/roles/qa-engineer.md`).
- **Medium/major** → sub-agent roles with phase gates from `hermes-ops.md`.
