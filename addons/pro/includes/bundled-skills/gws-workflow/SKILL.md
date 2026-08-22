---
type: Skill
name: gws-workflow
version: 1.0.0
description: Google Workspace cross-service productivity workflows via the gws CLI. Combines Drive, Gmail, Calendar, Sheets, Tasks, Docs, and Chat into multi-step automations.
license: Apache-2.0
compatibility: Claude Code, Cursor, Gemini CLI, Codex CLI
---

# Google Workspace Workflow (gws-workflow)

> **PREREQUISITE:** Read the `gws-shared` skill for auth, global flags, and security rules. Individual service skills (`gws-drive`, `gws-gmail`, `gws-calendar`, `gws-sheets`, `gws-tasks`, `gws-docs`) contain per-service command references.

Cross-service productivity workflows using the `gws` CLI.

```bash
gws workflow <resource> <method> [flags]
```

## Built-in Workflow Helpers

| Command | Description |
|---------|-------------|
| `gws workflow +standup-report` | Today's meetings + open tasks as a standup summary |
| `gws workflow +meeting-prep` | Prepare for your next meeting: agenda, attendees, and linked docs |
| `gws workflow +email-to-task` | Convert a Gmail message into a Google Tasks entry |
| `gws workflow +weekly-digest` | Weekly summary: this week's meetings + unread email count |
| `gws workflow +file-announce` | Announce a Drive file in a Chat space |

## Common Multi-Service Workflows

### Email → Task conversion

```bash
# 1. Get the email
gws gmail users messages get \
  --params '{"userId": "me", "id": "MESSAGE_ID", "format": "full"}'

# 2. Create a task from it
gws tasks tasks insert \
  --params '{"tasklist": "TASKLIST_ID"}' \
  --json '{"title": "Follow up: [email subject]", "notes": "[email snippet]", "due": "2026-03-20T17:00:00.000Z"}'
```

### Drive file → Chat announcement

```bash
# 1. Get file metadata (including sharing link)
gws drive files get \
  --params '{"fileId": "FILE_ID", "fields": "id,name,webViewLink"}'

# 2. Send to Chat space
gws chat spaces messages create \
  --params '{"parent": "spaces/SPACE_ID"}' \
  --json '{"text": "📄 New document ready: [File Name] — https://docs.google.com/..."}'
```

### Calendar event → Doc meeting notes

```bash
# 1. Get next event
gws calendar events list \
  --params '{"calendarId": "primary", "maxResults": 1, "orderBy": "startTime", "singleEvents": true, "timeMin": "'"$(date -u +%Y-%m-%dT%H:%M:%SZ)"'"}'

# 2. Create a meeting notes doc
gws docs documents create \
  --json '{"title": "Meeting Notes — [Event Name] — [Date]"}'

# 3. Add agenda section
gws docs documents batchUpdate \
  --params '{"documentId": "DOC_ID"}' \
  --json '{"requests": [{"insertText": {"location": {"index": 1}, "text": "## Agenda\n\n## Notes\n\n## Action Items\n"}}]}'
```

### Sheets CRM update → Gmail confirmation

```bash
# 1. Append deal update to Sheets
gws sheets spreadsheets values append \
  --params '{"spreadsheetId": "SHEET_ID", "range": "Deals!A1", "valueInputOption": "USER_ENTERED"}' \
  --json '{"values": [["2026-03-14", "Acme Corp", "Proposal Sent", "$50,000"]]}'

# 2. Send confirmation email
gws gmail +send \
  --to sales-team@example.com \
  --subject "CRM Updated: Acme Corp — Proposal Sent" \
  --body "Deal logged in the tracker."
```

## Persona Workflows

### Executive Assistant

Manage an executive's schedule, inbox, and communications:
- Standup report: `gws workflow +standup-report`
- Meeting prep: `gws workflow +meeting-prep`
- Email triage: `gws gmail +triage`
- Schedule focus blocks: `gws calendar events insert` with recurring RRULE

### Project Manager

Coordinate projects — track tasks, schedule meetings, share docs:
- Log deal/status: `gws sheets spreadsheets values append`
- Create recurring standups: `gws calendar events insert` with `RRULE:FREQ=WEEKLY`
- Generate project doc from template: `gws docs documents batchUpdate` + `replaceAllText`
- Notify team: `gws chat spaces messages create`

### IT Admin

Administer Workspace — monitor security, manage users:
- Run audit reports: `gws admin reports activities list`
- Review login events: filter by `applicationName=login`
- Export user list: `gws admin directory users list`

## Tips

- Chain multiple `gws` commands with shell variables to pass IDs between steps
- Use `jq` to extract specific fields from JSON responses
- Use `--dry-run` on any command before automating it in a scheduled workflow
- For recurring automations, wrap `gws` commands in a cron job or GitHub Actions workflow
