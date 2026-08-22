---
type: Skill
name: gws-calendar
version: 1.0.0
description: Google Calendar skill for managing calendars, events, and free/busy queries via the gws CLI. Covers creating, updating, listing, and deleting events across multiple calendars.
license: Apache-2.0
compatibility: Claude Code, Cursor, Gemini CLI, Codex CLI
---

# Google Calendar (gws-calendar)

> **PREREQUISITE:** Read the `gws-shared` skill for auth, global flags, and security rules.

Manage Google Calendar using the `gws` CLI.

```bash
gws calendar <resource> <method> [flags]
```

## Quick Examples

```bash
# List all calendars
gws calendar calendarList list

# List upcoming events (next 10) on primary calendar
gws calendar events list \
  --params '{"calendarId": "primary", "maxResults": 10, "orderBy": "startTime", "singleEvents": true, "timeMin": "2026-03-14T00:00:00Z"}'

# Search for events by text
gws calendar events list \
  --params '{"calendarId": "primary", "q": "standup", "maxResults": 5, "singleEvents": true}'

# Get a specific event
gws calendar events get \
  --params '{"calendarId": "primary", "eventId": "EVENT_ID"}'

# Create a simple event
gws calendar events insert \
  --params '{"calendarId": "primary"}' \
  --json '{
    "summary": "Team Standup",
    "start": {"dateTime": "2026-03-15T09:00:00-05:00"},
    "end":   {"dateTime": "2026-03-15T09:30:00-05:00"}
  }'

# Create event with attendees
gws calendar events insert \
  --params '{"calendarId": "primary", "sendUpdates": "all"}' \
  --json '{
    "summary": "Project Kickoff",
    "description": "Initial project planning session",
    "start": {"dateTime": "2026-03-20T14:00:00-05:00"},
    "end":   {"dateTime": "2026-03-20T15:00:00-05:00"},
    "attendees": [
      {"email": "alice@example.com"},
      {"email": "bob@example.com"}
    ],
    "location": "Conference Room A"
  }'

# Create a recurring event (weekly standup)
gws calendar events insert \
  --params '{"calendarId": "primary"}' \
  --json '{
    "summary": "Weekly Standup",
    "start": {"dateTime": "2026-03-16T09:00:00-05:00"},
    "end":   {"dateTime": "2026-03-16T09:30:00-05:00"},
    "recurrence": ["RRULE:FREQ=WEEKLY;BYDAY=MO,WE,FR"]
  }'

# Update an event
gws calendar events patch \
  --params '{"calendarId": "primary", "eventId": "EVENT_ID", "sendUpdates": "all"}' \
  --json '{"summary": "Updated Title", "location": "Zoom"}'

# Delete an event
gws calendar events delete \
  --params '{"calendarId": "primary", "eventId": "EVENT_ID", "sendUpdates": "all"}'

# Quick-add event from natural language
gws calendar events quickAdd \
  --params '{"calendarId": "primary", "text": "Team lunch Friday at noon"}'

# Move event to a different calendar
gws calendar events move \
  --params '{"calendarId": "primary", "eventId": "EVENT_ID", "destination": "CALENDAR_ID"}'

# Check free/busy for multiple users
gws calendar freebusy query \
  --json '{
    "timeMin": "2026-03-15T09:00:00Z",
    "timeMax": "2026-03-15T18:00:00Z",
    "items": [{"id": "alice@example.com"}, {"id": "bob@example.com"}]
  }'

# List instances of a recurring event
gws calendar events instances \
  --params '{"calendarId": "primary", "eventId": "RECURRING_EVENT_ID"}'
```

## Helper Commands

| Command | Description |
|---------|-------------|
| `gws calendar +insert` | Create a new event (guided) |
| `gws calendar +agenda` | Show upcoming events across all calendars |

## Key API Resources

### events
`list`, `get`, `insert`, `update`, `patch`, `delete`, `quickAdd`, `move`, `instances`, `import`, `watch`

### calendarList
`list`, `get`, `insert`, `update`, `patch`, `delete`, `watch`

### calendars
`get`, `insert`, `update`, `patch`, `delete`, `clear`

### freebusy
`query`

### acl (Access Control)
`list`, `get`, `insert`, `update`, `patch`, `delete`

## Discovering More

```bash
gws calendar --help
gws schema calendar.events.insert
gws schema calendar.freebusy.query
```
