---
type: Skill
name: gws-sheets
version: 1.0.0
description: Google Sheets skill for reading and writing spreadsheets via the gws CLI. Covers cell reads, row appends, batch updates, sheet management, and formula evaluation.
license: Apache-2.0
compatibility: Claude Code, Cursor, Gemini CLI, Codex CLI
---

# Google Sheets (gws-sheets)

> **PREREQUISITE:** Read the `gws-shared` skill for auth, global flags, and security rules.

Read and write Google Sheets spreadsheets using the `gws` CLI.

```bash
gws sheets <resource> <method> [flags]
```

> **Shell tip:** Sheet ranges contain `!` which zsh interprets as history expansion. Always use double quotes around range values:
> ```bash
> --range "Sheet1!A1:D10"   # CORRECT
> --range 'Sheet1!A1:D10'   # WRONG in zsh
> ```

## Quick Examples

```bash
# Create a new spreadsheet
gws sheets spreadsheets create \
  --json '{"properties": {"title": "Q1 Budget 2026"}}'

# Get spreadsheet metadata
gws sheets spreadsheets get \
  --params '{"spreadsheetId": "SPREADSHEET_ID"}'

# Read a range of cells
gws sheets spreadsheets values get \
  --params '{"spreadsheetId": "SPREADSHEET_ID", "range": "Sheet1!A1:E10"}'

# Write values to a range
gws sheets spreadsheets values update \
  --params '{"spreadsheetId": "SPREADSHEET_ID", "range": "Sheet1!A1", "valueInputOption": "USER_ENTERED"}' \
  --json '{"values": [["Name", "Email", "Amount"], ["Alice", "alice@example.com", 500]]}'

# Append rows to a sheet
gws sheets spreadsheets values append \
  --params '{"spreadsheetId": "SPREADSHEET_ID", "range": "Sheet1!A1", "valueInputOption": "USER_ENTERED", "insertDataOption": "INSERT_ROWS"}' \
  --json '{"values": [["Bob", "bob@example.com", 750]]}'

# Clear a range
gws sheets spreadsheets values clear \
  --params '{"spreadsheetId": "SPREADSHEET_ID", "range": "Sheet1!A2:E100"}'

# Batch read multiple ranges
gws sheets spreadsheets values batchGet \
  --params '{"spreadsheetId": "SPREADSHEET_ID", "ranges": ["Sheet1!A1:B10", "Sheet2!C1:D5"]}'

# Batch write multiple ranges
gws sheets spreadsheets values batchUpdate \
  --params '{"spreadsheetId": "SPREADSHEET_ID"}' \
  --json '{
    "valueInputOption": "USER_ENTERED",
    "data": [
      {"range": "Sheet1!A1", "values": [["Header"]]},
      {"range": "Sheet2!A1", "values": [["Other Header"]]}
    ]
  }'

# Add a new sheet tab
gws sheets spreadsheets batchUpdate \
  --params '{"spreadsheetId": "SPREADSHEET_ID"}' \
  --json '{
    "requests": [{"addSheet": {"properties": {"title": "April"}}}]
  }'

# Format cells (bold header row)
gws sheets spreadsheets batchUpdate \
  --params '{"spreadsheetId": "SPREADSHEET_ID"}' \
  --json '{
    "requests": [{
      "repeatCell": {
        "range": {"sheetId": 0, "startRowIndex": 0, "endRowIndex": 1},
        "cell": {"userEnteredFormat": {"textFormat": {"bold": true}}},
        "fields": "userEnteredFormat.textFormat.bold"
      }
    }]
  }'
```

## Helper Commands

| Command | Description |
|---------|-------------|
| `gws sheets +read` | Read values from a spreadsheet (simplified) |
| `gws sheets +append` | Append a row to a spreadsheet (simplified) |

## valueInputOption Reference

| Value | Behaviour |
|-------|-----------|
| `RAW` | Values stored as-is (strings stay strings) |
| `USER_ENTERED` | Parsed as if typed by a user (formulas evaluated, dates recognised) |

## Key API Resources

### spreadsheets
`get`, `create`, `batchUpdate`

### spreadsheets.values
`get`, `update`, `append`, `clear`, `batchGet`, `batchUpdate`, `batchClearByDataFilter`

### spreadsheets.sheets
`copyTo`

## Discovering More

```bash
gws sheets --help
gws schema sheets.spreadsheets.values.get
gws schema sheets.spreadsheets.values.append
gws schema sheets.spreadsheets.batchUpdate
```
