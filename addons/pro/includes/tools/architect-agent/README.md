# Architect Agent

## Purpose

Houses 7 Architect Agent tools enabling AI-driven development workflows directly within the plugin: shell command execution (with safety controls), git change operations (add/commit/checkout/stash), git inspection (status/log/diff/blame), broad git operations, file management, codebase search, and a shared trait for git helper logic.

## Tier

| | |
|---|---|
| **Distribution** | Pro |
| **PHP target** | 8.1+ |
| **Loaded by** | Pro tool registry; requires `WP_MCP_AI_ALLOW_SHELL_TOOLS` constant for shell execution |
| **Optional dependencies** | `proc_open()` must be available for shell tool; `edit_plugins` capability |

## Public Surface

| Symbol | File | Used by |
|---|---|---|
| `WP_MCP_AI_Tool_Execute_Shell_Command` | `class-wp-mcp-ai-tool-execute-shell-command.php` | tool registry |
| `WP_MCP_AI_Tool_Git_Change` | `class-wp-mcp-ai-tool-git-change.php` | tool registry |
| `WP_MCP_AI_Tool_Git_Inspect` | `class-wp-mcp-ai-tool-git-inspect.php` | tool registry |
| `WP_MCP_AI_Tool_Git_Operations` | `class-wp-mcp-ai-tool-git-operations.php` | tool registry |
| `WP_MCP_AI_Tool_Manage_Files` | `class-wp-mcp-ai-tool-manage-files.php` | tool registry |
| `WP_MCP_AI_Tool_Search_Codebase` | `class-wp-mcp-ai-tool-search-codebase.php` | tool registry |
| `WP_MCP_AI_Tool_Git_Helpers` (trait) | `trait-wp-mcp-ai-tool-git-helpers.php` | all git tools |

## Inputs / Outputs / Neighbors

- **Reads from:** `WP_MCP_AI_ALLOW_SHELL_TOOLS` constant; filesystem (shell, file tools); git repository state
- **Writes to:** Filesystem (file management, git changes); git repository (commits, branches, stashes)
- **Upstream callers:** Pro tool registry, orchestrator, assistant runtime
- **Downstream collaborators:** `proc_open()` (shell execution); git binary on system PATH; `WP_MCP_AI_Logger` (execution logging)
- **Events fired:** `wp_mcp_ai_shell_command_executed`
- **Events listened to:** None

## Conventions

- All tools implement `WP_MCP_AI_Tool_Interface` and `WP_MCP_AI_Tool_Capability_Flags_Interface`.
- Shell tool requires `manage_options` capability and `WP_MCP_AI_ALLOW_SHELL_TOOLS` constant; blocks dangerous commands via regex patterns.
- Git tools share helper logic via `WP_MCP_AI_Tool_Git_Helpers` trait.
- All tools carry `architect-agent`, `development-workflow`, and `state-changing` capability flags.
- Shell tool uses `proc_open()` with non-blocking I/O and configurable timeout (1-300s).

## Tests

```bash
vendor/bin/phpunit tests/pro/tools/architect-agent/
```

## Also Load

- [`.context/conventions.md`](../../../../../.context/conventions.md) — naming, style, PHP compat
- [`.context/security-checklist.md`](../../../../../.context/security-checklist.md) — security
- [`.context/tool-registry.md`](../../../../../.context/tool-registry.md) — tool registration
- [`.context/pro-vs-base.md`](../../../../../.context/pro-vs-base.md) — Pro vs Base distribution
