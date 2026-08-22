---
type: Skill
name: gws-workflow-standup-report
version: 1.0.0
description: Google Workspace workflow that generates a standup summary combining today's calendar events and open Google Tasks. Read-only — never modifies data.
license: Apache-2.0
compatibility: Claude Code, Cursor, Gemini CLI, Codex CLI
---

# gws workflow +standup-report

> **PREREQUISITE:** Read the `gws-shared` skill for auth, global flags, and security rules.

Generate a standup summary combining today's meetings and open tasks.

## Usage

```bash
gws workflow +standup-report
gws workflow +standup-report --format table
```

## What It Does

Combines two read-only API calls:

1. **Calendar**: fetches today's events across all calendars
2. **Tasks**: fetches open (incomplete) tasks from all task lists

Then formats them into a standup-ready summary:

```
Standup — Friday, 14 March 2026

📅 Today's Meetings:
  09:00  Team Standup (30 min)
  11:00  Product Review (60 min)
  14:30  1:1 with Manager (30 min)

✅ Open Tasks:
  • Review PR #42 — due today
  • Update project timeline — due tomorrow
  • Send invoice to Acme Corp — overdue (Mar 12)
```

## Manual Equivalent

If you need to build this manually:

```bash
# Get today's events
TODAY=$(date -u +%Y-%m-%dT00:00:00Z)
TOMORROW=$(date -u -d '+1 day' +%Y-%m-%dT00:00:00Z 2>/dev/null || date -u -v+1d +%Y-%m-%dT00:00:00Z)

gws calendar events list \
  --params "{\"calendarId\": \"primary\", \"singleEvents\": true, \"orderBy\": \"startTime\", \"timeMin\": \"$TODAY\", \"timeMax\": \"$TOMORROW\", \"maxResults\": 20}"

# Get open tasks from all task lists
gws tasks tasklists list | jq -r '.items[].id' | while read TASKLIST_ID; do
  gws tasks tasks list \
    --params "{\"tasklist\": \"$TASKLIST_ID\", \"showCompleted\": false}"
done
```

## Output Formats

```bash
gws workflow +standup-report                 # JSON (default)
gws workflow +standup-report --format table  # Human-readable table
gws workflow +standup-report --format yaml   # YAML
```

## Tips

- This command is read-only — it never creates or modifies calendar events or tasks
- Run it at the start of each day or automate it as a morning cron job
- Combine with `gws gmail +send` to email the standup summary to your team
- Pipe to `jq` to filter for specific calendars or task lists

## See Also

- `gws-calendar` — Full calendar API reference
- `gws-tasks` — Full tasks API reference
- `gws-workflow` — All cross-service workflow helpers
- `gws-workflow-meeting-prep` — Prepare for your next meeting
- `gws-workflow-weekly-digest` — Weekly summary of meetings and email
