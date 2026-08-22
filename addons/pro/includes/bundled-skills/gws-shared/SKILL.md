---
type: Skill
name: gws-shared
version: 1.0.0
description: gws CLI shared patterns for authentication, global flags, and output formatting. Read this before using any other gws-* skill.
license: Apache-2.0
compatibility: Claude Code, Cursor, Gemini CLI, Codex CLI
---

# gws — Shared Reference

`gws` is a unified CLI for all Google Workspace APIs. It reads Google's Discovery Service at runtime, so every Workspace API endpoint is available without custom integration code.

## Installation

```bash
npm install -g @googleworkspace/cli
gws --version
```

## Authentication

### Interactive (desktop / laptop)

```bash
gws auth setup    # One-time: creates Cloud project, enables APIs, logs you in (requires gcloud)
gws auth login    # Subsequent scope selection and login
```

Log in with specific services to stay within testing-mode scope limits:

```bash
gws auth login -s drive,gmail,calendar,sheets
```

### Service Account (server / CI)

```bash
export GOOGLE_WORKSPACE_CLI_CREDENTIALS_FILE=/path/to/service-account.json
gws drive files list   # Works without interactive login
```

### Pre-obtained access token

```bash
export GOOGLE_WORKSPACE_CLI_TOKEN=$(gcloud auth print-access-token)
```

### Headless / CI export flow

```bash
# On machine with browser
gws auth export --unmasked > credentials.json

# On headless machine
export GOOGLE_WORKSPACE_CLI_CREDENTIALS_FILE=/path/to/credentials.json
```

## CLI Syntax

```bash
gws <service> <resource> [sub-resource] <method> [flags]
```

## Global Flags

| Flag | Description |
|------|-------------|
| `--format <FORMAT>` | Output format: `json` (default), `table`, `yaml`, `csv` |
| `--dry-run` | Validate request locally without calling the API |
| `--sanitize <TEMPLATE>` | Screen responses through Model Armor |

## Method Flags

| Flag | Description |
|------|-------------|
| `--params '{"key": "val"}'` | URL / query parameters |
| `--json '{"key": "val"}'` | Request body |
| `-o, --output <PATH>` | Save binary responses to file |
| `--upload <PATH>` | Upload file content (multipart) |
| `--page-all` | Auto-paginate (NDJSON output) |
| `--page-limit <N>` | Max pages when using `--page-all` (default: 10) |
| `--page-delay <MS>` | Delay between pages in ms (default: 100) |

## Discovering Commands

```bash
# Browse all services
gws --help

# Browse a service's resources
gws drive --help

# Inspect a specific method's schema
gws schema drive.files.list
gws schema gmail.users.messages.send
```

## Shell Tips

### zsh `!` expansion in ranges

Sheet ranges like `Sheet1!A1` contain `!` which zsh interprets as history expansion. Use double quotes:

```bash
# WRONG (zsh mangles the !)
gws sheets +read --spreadsheet ID --range 'Sheet1!A1:D10'

# CORRECT
gws sheets +read --spreadsheet ID --range "Sheet1!A1:D10"
```

### JSON with inner double quotes

```bash
gws drive files list --params '{"pageSize": 5}'
```

## Security Rules

- **Never** output secrets, tokens, or API keys in responses
- **Always** confirm with the user before executing write or delete commands
- Prefer `--dry-run` for any destructive or sending operation
- Use `--sanitize` for PII screening when processing user-generated content

## MCP Server (for AI agents)

```bash
# Start a built-in MCP server exposing selected APIs
gws mcp -s drive,gmail,calendar,sheets
```

This exposes all methods of the selected services as MCP tools, giving any MCP-compatible AI agent direct Workspace access without additional integration code.
