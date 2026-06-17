# `.zed/` — Zed Editor Configuration for NV oOS

This directory configures the [Zed editor](https://zed.dev) for contributors working on **Open Operator System (NV oOS)**. Its main purpose is to expose the GitHub Custom Agent roster from [`examples/agents/`](../examples/agents/) as native Zed **agent profiles**, so each example becomes a one-click selection in Zed's Agent Panel.

> **Last reviewed:** May 2026 · **Mirrors:** `examples/agents/` (13 agents)

---

## Files

| File | Purpose |
|------|---------|
| `settings.json` | Project-level Zed settings — editor defaults, MCP context-server templates, and the 12 agent profiles. |
| `README.md` | This file. |

---

## How agents map between GitHub and Zed

Zed and GitHub Custom Agents speak slightly different dialects. The mapping is mechanical:

| `examples/agents/*.agent.md` field | Zed equivalent (in `settings.json`) |
|------------------------------------|-------------------------------------|
| `name:` (frontmatter) | profile key + `name` |
| `description:` (frontmatter) | shown in Agent Panel via the profile name |
| `tools: read, view` | `read_file: true` |
| `tools: grep` | `grep: true` |
| `tools: glob` | `find_path: true` + `list_directory: true` |
| `tools: edit` | `edit_file: true` + `create_directory: true` + `move_path: true` |
| `tools: bash` | `terminal: true` |
| `Required reading` block | Auto-loaded — Zed reads `AGENTS.md` and `CLAUDE.md` from the repo root as agent rules |
| `Scope` / `Refusals` / `Success criteria` | Stay in the source `*.agent.md` files; the agent reads them via `read_file` when invoked |

The shared rules (naming, security, PHP-compat, tool patterns, architecture) are **never** restated in `.zed/settings.json` — that would violate the layering rule from [`AGENTS.md` §2](../AGENTS.md). Zed loads the canonical sources automatically.

---

## The 13 profiles

All 13 examples from `examples/agents/` are exposed. Pick the one that matches your task.

### Read-only reviewers *(no `edit_file`, no `terminal`)*

| Profile | Source | What it owns |
|---------|--------|--------------|
| `wp-rest-reviewer` | [`wp-rest-reviewer.agent.md`](../examples/agents/wp-rest-reviewer.agent.md) | REST endpoints, permissions, schemas |
| `wp-security-reviewer` | [`wp-security-reviewer.agent.md`](../examples/agents/wp-security-reviewer.agent.md) | Capability gates, nonces, sanitization, escaping, prepared SQL |
| `wp-org-compliance-auditor` | [`wp-org-compliance-auditor.agent.md`](../examples/agents/wp-org-compliance-auditor.agent.md) | WordPress.org plugin-review checklist |
| `php-compat-reviewer` | [`php-compat-reviewer.agent.md`](../examples/agents/php-compat-reviewer.agent.md) | PHP 7.4 floor (Base) / PHP 8.1 floor (Pro) |

### Writers *(get `edit_file`; most also get `terminal`)*

| Profile | Source | What it owns |
|---------|--------|--------------|
| `tool-author` | [`tool-author.agent.md`](../examples/agents/tool-author.agent.md) | Tool classes under `includes/tools/` and `addons/pro/includes/tools/` |
| `slash-command-author` | [`slash-command-author.agent.md`](../examples/agents/slash-command-author.agent.md) | Commands under `includes/slash-commands/commands/` |
| `chat-ui-author` | [`chat-ui-author.agent.md`](../examples/agents/chat-ui-author.agent.md) | Frontend chat — JS, CSS, blocks, Elementor widget |
| `phpunit-test-author` | [`phpunit-test-author.agent.md`](../examples/agents/phpunit-test-author.agent.md) | Tests under `tests/` and `addons/pro/tests/` |
| `agent-skill-curator` | [`agent-skill-curator.agent.md`](../examples/agents/agent-skill-curator.agent.md) | Bundled `SKILL.md` files + `THIRD_PARTY_NOTICES.md` |
| `addon-maintainer` | [`addon-maintainer.agent.md`](../examples/agents/addon-maintainer.agent.md) | One addon per session — algorave / canvas / cornerstone3d / embedded / fantasy-football / graphify |
| `toolkit-spa-maintainer` | [`toolkit-spa-maintainer.agent.md`](../examples/agents/toolkit-spa-maintainer.agent.md) | One toolkit-SPA addon per session — toolkit-shell / canvas-toolkit / document-editor / ohif-viewer / media-studio / video-studio |
| `release-engineer` | [`release-engineer.agent.md`](../examples/agents/release-engineer.agent.md) | Versions, `CHANGELOG.md`, `readme.txt`, build scripts |
| `docs-maintainer` | [`docs-maintainer.agent.md`](../examples/agents/docs-maintainer.agent.md) | `docs/`, `README.md`, `readme.txt`, `CHANGELOG.md` *(no `terminal`)* |

---

## Using a profile

1. Open this repo in Zed.
2. Open the **Agent Panel** (`cmd-?` / `ctrl-?`).
3. In the panel header, click the profile selector (next to the model picker) and choose one of the **NV oOS · …** profiles.
4. Start your prompt. The agent inherits the repo's `AGENTS.md` + `CLAUDE.md` as rules and is restricted to the tool set declared for its role.
5. For sensitive operations (e.g. running tests via `terminal`), Zed will prompt for confirmation unless you flip `always_allow_tool_actions` to `true` in your **user** settings (do **not** flip it project-wide).

> **Tip:** the first time you select a writer profile, Zed will ask whether to allow `edit_file` for the workspace. Approve once and the profile is sticky for that workspace.

---

## MCP (Model Context Protocol) context servers

The `context_servers` block in `settings.json` ships with three commented templates:

- **`github`** — official GitHub MCP server (`@modelcontextprotocol/server-github`). Useful for the `release-engineer`, `wp-org-compliance-auditor`, and any reviewer that wants to check CI runs, PRs, or issues from inside the agent.
- **`filesystem`** — sandbox the agent to this repo only (`@modelcontextprotocol/server-filesystem`). Useful when you want extra-strict path scoping for writer profiles.
- **`nv-oos-local`** — point Zed at the `mcp-ai/v1` REST endpoint of a local NV oOS site (e.g. `docker compose up -d` per [`MAINTAINER_MAP.md`](../MAINTAINER_MAP.md)). Gives the agent access to all ~1,000+ in-product NV oOS tools while developing.

Each is **off by default**. Uncomment the block, supply the matching secret (Zed has a built-in secret store: `cmd-shift-p` → "agent: edit settings" → secrets), then reload the Agent Panel.

---

## Why this is needed

`examples/agents/*.agent.md` are GitHub Custom Agent files — they are auto-discovered by GitHub's Copilot Coding Agent and compatible runtimes (per [`AGENTS.md` §1](../AGENTS.md)). Zed does not auto-discover them.

This `.zed/` directory bridges that gap: contributors who use Zed get the same scoped agents (least-privilege tools, single-subsystem ownership, refusal of out-of-scope work) without re-reading the GitHub spec or copy-pasting frontmatter.

If you change a `*.agent.md` file's `tools:` line, update the matching profile's tool block in `settings.json` in the **same** PR. The mapping table at the top of this file is the authority.

---

## Related files

- [`AGENTS.md`](../AGENTS.md) — agent inventory, coordination, and the layering rule (§2).
- [`CLAUDE.md`](../CLAUDE.md) — Claude Code per-turn context (auto-loaded by Zed too).
- [`examples/agents/`](../examples/agents/) — canonical GitHub Custom Agent files this directory mirrors.
- [`.context/`](../.context/) — subsystem context loaded on-demand by every agent.
- [`.vscode/`](../.vscode/) — VS Code-specific equivalent (formatters + extensions only; no agent profiles).
