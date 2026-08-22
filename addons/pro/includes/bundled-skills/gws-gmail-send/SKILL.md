---
type: Skill
name: gws-gmail-send
version: 1.0.0
description: Gmail helper for sending email via the gws CLI. Handles RFC 2822 formatting and base64 encoding automatically. Supports plain text, HTML, CC, and BCC.
license: Apache-2.0
compatibility: Claude Code, Cursor, Gemini CLI, Codex CLI
---

# Gmail Send (gws-gmail-send)

> **PREREQUISITE:** Read the `gws-shared` skill for auth, global flags, and security rules.

Send emails quickly using the `gws gmail +send` helper.

## Usage

```bash
gws gmail +send --to <EMAILS> --subject <SUBJECT> --body <TEXT>
```

## Flags

| Flag | Required | Description |
|------|----------|-------------|
| `--to` | ✓ | Recipient email address(es), comma-separated |
| `--subject` | ✓ | Email subject line |
| `--body` | ✓ | Email body (plain text, or HTML when `--html` is set) |
| `--cc` | — | CC email address(es), comma-separated |
| `--bcc` | — | BCC email address(es), comma-separated |
| `--html` | — | Treat `--body` as HTML content |
| `--dry-run` | — | Show the request that would be sent, without executing |

## Examples

```bash
# Simple plain-text email
gws gmail +send \
  --to alice@example.com \
  --subject 'Hello from gws' \
  --body 'Hi Alice! This was sent by the gws CLI.'

# HTML email
gws gmail +send \
  --to alice@example.com \
  --subject 'Your report is ready' \
  --body '<h1>Report Ready</h1><p>Click <a href="https://example.com">here</a> to view.</p>' \
  --html

# With CC and BCC
gws gmail +send \
  --to alice@example.com \
  --subject 'Project Update' \
  --body 'See the attached notes.' \
  --cc bob@example.com \
  --bcc manager@example.com

# Preview without sending
gws gmail +send \
  --to alice@example.com \
  --subject 'Test' \
  --body 'Hello' \
  --dry-run

# Send to multiple recipients
gws gmail +send \
  --to 'alice@example.com,bob@example.com,carol@example.com' \
  --subject 'Team announcement' \
  --body 'The sprint review is tomorrow at 2pm.'
```

## Using the Raw API (for attachments)

The `+send` helper does not support file attachments. Use the raw `messages.send` API with a multipart MIME message for attachments:

```bash
# Base64-encode an RFC 2822 message with attachment
# (compose the MIME message first, then base64-encode it)
gws gmail users messages send \
  --params '{"userId": "me"}' \
  --json '{"raw": "<base64url-encoded-mime-message>"}'
```

> [!CAUTION]
> **This is a write command.** Always confirm with the user before sending an email. Use `--dry-run` to preview the request first.

## Tips

- Handles RFC 2822 formatting and base64url encoding automatically
- For threading (replies), use `gws gmail +reply` instead (see `gws-gmail` skill)
- For bulk email, loop over recipients and add delays to avoid rate limits
- Always include a meaningful subject — blank subjects are marked as spam

## See Also

- `gws-gmail` — Full Gmail API reference (threads, labels, filters, drafts)
- `gws-shared` — Authentication and global flags
