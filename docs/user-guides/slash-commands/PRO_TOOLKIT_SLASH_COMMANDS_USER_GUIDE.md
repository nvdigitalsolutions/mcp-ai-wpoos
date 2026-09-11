# Toolkit Slash Commands User Guide

## Introduction

Toolkit slash commands in NV oOS are declarative wrappers over the MCP
tool registry. Each command maps to one real, tested tool — so when you
run `/video-compress`, you are executing the same `compress_video` tool
the AI calls itself, with the same validation, capability checks and
result envelope.

This guide covers the tool-backed command set. A full table lives in
[PRO_TOOLKIT_SLASH_COMMANDS.md](./PRO_TOOLKIT_SLASH_COMMANDS.md).

## Getting Started

### Prerequisites

- NV oOS plugin installed and activated
- Pro mode enabled (`WP_MCP_AI_BASE_VERSION = false`) for Pro toolkit commands
- The WordPress capability required by the command (shown per command)

### Basic Command Structure

```
/command-name --parameter="value" --flag
```

- **Command name**: the action you want to perform (e.g., `/video-compress`)
- **Parameters**: named arguments (e.g., `--video_id=123`)
- **Flags**: boolean options (e.g., `--dry_run=true`)

Every command also accepts `--json` for a JSON envelope of the result.

### Finding Available Commands

```
/help                     # List all commands
/help video-compress      # Detailed help for a command
/help --detailed          # Detailed information for all commands
/tools                    # Inspect the underlying tool registry
```

The `/help` list now only shows commands that are actually backed by a
tool — placeholder entries were removed in v2.2.0.

---

## Examples by Domain

### E-commerce

```bash
# Identify abandoned carts
/abandoned-recover --action=identify

# Send recovery emails
/abandoned-recover --action=send_recovery --send_email=true

# Forecast inventory demand
/inventory-forecast --forecast_type=demand --analysis_period_days=30

# Create a discount campaign
/create-discount-campaign --code=SAVE20 --discount_type=percent --amount=20
```

### Social Media

```bash
# Schedule a post
/social-schedule --content="Hello world" --platform=twitter --scheduled_time="2026-09-20T09:00:00"

# Publish immediately (dry run previews without posting)
/social-publish --platform=twitter --content="Hello world" --dry_run=true

# Review unified analytics
/social-analytics --platforms=twitter,instagram
```

### Video Production

```bash
# Compress a video
/video-compress --video_id=123 --quality=medium

# Trim to a range
/video-trim --video_id=123 --start_time=10 --end_time=60

# Merge clips
/video-merge --video_ids=1,2,3 --transition=fade

# Generate thumbnails
/video-thumbnail --video_id=123 --count=5

# AI-edit with a prompt
/video-edit --source_video_id=123 --edit_prompt="make it cinematic"
```

### CRM

```bash
/lead-add --email="jane@example.com" --first_name="Jane"
/lead-qualify --lead_id=456 --message_or_notes="Asked about enterprise pricing"
/lead-assign --lead_id=456 --owner_id=789
/deal-create --lead_id=456 --deal_name="Website project" --amount=5000
/deal-move --deal_id=789 --new_stage=proposal
/pipeline-view
```

### Content & SEO

```bash
/content-draft --topic="AI trends" --type=post
/seo-optimize --post_id=123 --focus_keyword="ai"
/meta-generate --post_id=123
/content-translate --post_id=123 --target_language=es
```

### Media & Documents

```bash
/image-optimize --attachment_id=456
/image-edit --attachment_id=456 --prompt="remove background"
/watermark-add --video_id=123 --watermark_id=456
/doc-create --title="Monthly report" --description="Quarterly sales summary"
```

### Research & Analytics

```bash
/research-query --topic="WordPress AI plugins" --depth=standard
/chart-create --type=bar --title="Sales"
/code-analyze --language=php --code="<?php echo 1;"
/security-scan
```

### Scheduling & Calendar

```bash
/booking-create --client_name="Jane" --client_email="jane@example.com" --start_time="2026-09-15 10:00:00"
/budget-create --monthly_income=5000 --savings_goal=1000
```

---

## MCP Prompts Bridge

Every registered command is also exposed as an MCP prompt template
(`slash.<command>`) through the plugin's MCP servers (`prompts/list` and
`prompts/get`). Clients that support MCP prompts surface these as
user-invoked commands — the prompt body instructs the assistant to call
the same backing tool the chat slash command uses.

## Removed Placeholder Commands

Commands that previously answered with "Implementation coming soon"
were removed. The functionality they described is available through the
underlying MCP tools — ask the assistant in plain chat, or use the
commands in the reference table.
