# Architect Agent Toolkit

> AI-powered self-editing capabilities for WordPress: file operations, shell commands,
> git, and codebase search. Inspired by GitHub Copilot CLI to give an NV oOS assistant
> a complete development workflow.

| | |
|---|---|
| **Activation setting** | `enable_architect_agent_toolkit` |
| **Admin location** | NV oOS → Settings → Pro Features → Architect Agent |
| **Tools** | 4 |
| **Available since** | Pro v1.1.0 |
| **Status** | ⚠️ **Powerful — read the safety section before enabling on production sites.** |

---

## What it provides

The Architect Agent gives an assistant the same surface area a human developer has:

| Tool slug | What it does |
|---|---|
| `manage_files` | Read, write, list and diff files inside the WordPress install |
| `execute_shell_command` | Run shell commands (with allow-list / deny-list controls) |
| `git_operations` | `status`, `diff`, `log`, `branch`, `commit`, etc. |
| `search_codebase` | Pattern / semantic search across the codebase |

Tool source: `addons/pro/includes/tools/architect-agent/`.

---

## Why use it

- Building a "code-aware" assistant that can answer questions about your own plugin code.
- Automating chores like running `composer install`, regenerating translations, or applying
  small refactors.
- Pairing with the [AI Tool Builder Toolkit](ai-tool-builder.md) to scaffold and commit new
  custom tools end-to-end.
- Powering the GSD × BMAD methodology described in [`AGENTS.md`](../../../AGENTS.md) and
  [`CONTRIBUTING.md`](../../../CONTRIBUTING.md).

---

## Activation

1. Activate the Pro add-on (license required).
2. Toggle **Architect Agent** under **NV oOS → Settings → Pro Features**.
3. Visit **NV oOS → Settings → Architect Agent** (`WP_MCP_AI_Architect_Agent_Settings_Page`)
   to configure the shell command allow-list, working directory, and capability map.

---

## Safety & permissions

Because three of the four tools can mutate the filesystem, the host, and the git repo:

- **Default capability:** `manage_options` — only administrators can call these tools.
- **Recommended:** restrict the toolkit to a dedicated admin user that the assistant
  authenticates as, not a shared admin account.
- **Shell tool:** uses an allow-list. Configure it in the Architect Agent settings and
  keep it as small as possible (e.g. `composer`, `npm`, `git`, `wp`).
- **Git tool:** never run `git push` from a production install — push from a CI/CD job or
  the Copilot Coding Agent flow instead.
- **File tool:** the working directory is configurable; keep it scoped to the plugin's
  own folder unless you have a specific reason to widen it.
- **Audit:** every tool call goes through the standard tool-execution lifecycle and is
  logged to `wp_mcp_ai_recent_activity` when logging is enabled.

Do **not** enable this toolkit on shared hosting where you cannot constrain shell access
or where other tenants share the filesystem.

---

## Related docs

- [Pro Toolkits index](README.md)
- [AI Tool Builder Toolkit](ai-tool-builder.md) — companion toolkit for building new tools
- [Site Creator Toolkit](site-creator.md) — higher-level builders that can call Architect Agent under the hood
- [`AGENTS.md`](../../../AGENTS.md) — multi-agent / BMAD context
