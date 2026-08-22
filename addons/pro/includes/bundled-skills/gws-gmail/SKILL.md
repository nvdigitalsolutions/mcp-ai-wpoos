---
type: Skill
name: gws-gmail
version: 1.0.0
description: Gmail skill for sending, reading, searching, labelling, and managing email via the gws CLI. Covers messages, threads, labels, drafts, filters, and inbox management.
license: Apache-2.0
compatibility: Claude Code, Cursor, Gemini CLI, Codex CLI
---

# Gmail (gws-gmail)

> **PREREQUISITE:** Read the `gws-shared` skill for auth, global flags, and security rules.

Manage Gmail using the `gws` CLI.

```bash
gws gmail <resource> <method> [flags]
```

## Quick Examples

```bash
# Get Gmail profile
gws gmail users getProfile --params '{"userId": "me"}'

# List inbox messages (10 most recent)
gws gmail users messages list \
  --params '{"userId": "me", "labelIds": "INBOX", "maxResults": 10}'

# Search messages
gws gmail users messages list \
  --params '{"userId": "me", "q": "from:boss@example.com is:unread"}'

# Get a message (full format)
gws gmail users messages get \
  --params '{"userId": "me", "id": "MESSAGE_ID", "format": "full"}'

# Send an email (use +send helper for simplicity — see gws-gmail-send skill)
gws gmail users messages send \
  --params '{"userId": "me"}' \
  --json '{"raw": "<base64-encoded RFC 2822 message>"}'

# List labels
gws gmail users labels list --params '{"userId": "me"}'

# Create a label
gws gmail users labels create \
  --params '{"userId": "me"}' \
  --json '{"name": "Invoices", "labelListVisibility": "labelShow"}'

# Apply a label to a message
gws gmail users messages modify \
  --params '{"userId": "me", "id": "MESSAGE_ID"}' \
  --json '{"addLabelIds": ["LABEL_ID"]}'

# Archive a message (remove INBOX label)
gws gmail users messages modify \
  --params '{"userId": "me", "id": "MESSAGE_ID"}' \
  --json '{"removeLabelIds": ["INBOX"]}'

# Trash a message
gws gmail users messages trash \
  --params '{"userId": "me", "id": "MESSAGE_ID"}'

# Create a draft
gws gmail users drafts create \
  --params '{"userId": "me"}' \
  --json '{"message": {"raw": "<base64-encoded RFC 2822 message>"}}'

# List threads
gws gmail users threads list \
  --params '{"userId": "me", "q": "subject:\"Project Update\"", "maxResults": 5}'

# Get thread
gws gmail users threads get \
  --params '{"userId": "me", "id": "THREAD_ID"}'

# Create a Gmail filter (auto-label)
gws gmail users settings filters create \
  --params '{"userId": "me"}' \
  --json '{"criteria": {"from": "newsletter@example.com"}, "action": {"addLabelIds": ["LABEL_ID"], "removeLabelIds": ["INBOX"]}}'

# Watch inbox for push notifications
gws gmail users watch \
  --params '{"userId": "me"}' \
  --json '{"topicName": "projects/my-project/topics/gmail-push", "labelIds": ["INBOX"]}'
```

## Helper Commands

| Command | Description |
|---------|-------------|
| `gws gmail +send` | Send an email (see `gws-gmail-send` skill) |
| `gws gmail +triage` | Show unread inbox summary |
| `gws gmail +reply` | Reply to a message (threading handled automatically) |
| `gws gmail +reply-all` | Reply-all to a message |
| `gws gmail +forward` | Forward a message |
| `gws gmail +watch` | Stream new emails as NDJSON |

## Gmail Query Syntax

```
is:unread                          Unread messages
is:starred                         Starred messages
from:user@example.com              From a specific sender
to:user@example.com                To a recipient
subject:invoice                    Subject contains word
has:attachment                     Has file attachments
larger:5M                          Larger than 5 MB
after:2026/01/01                   Received after date
before:2026/03/01                  Received before date
label:work                         Has label "work"
-label:spam                        Does not have label "spam"
```

## Key API Resources

### users.messages
`list`, `get`, `send`, `modify`, `trash`, `delete`, `batchModify`

### users.threads
`list`, `get`, `modify`, `trash`, `delete`

### users.labels
`list`, `get`, `create`, `update`, `delete`

### users.drafts
`list`, `get`, `create`, `update`, `send`, `delete`

### users.settings.filters
`list`, `get`, `create`, `delete`

## Discovering More

```bash
gws gmail --help
gws schema gmail.users.messages.list
gws schema gmail.users.messages.send
```
